/**
 * Dono Stripe Connect broker.
 *
 * The GPL plugin cannot hold the Stripe platform secret. This worker does,
 * via env, and performs the OAuth code exchange. The plugin only ever talks
 * to this broker and stores the per-account tokens it returns.
 *
 * Flow (hardened: tokens never travel in a browser URL):
 *   plugin  -> GET  /stripe/authorize?state&return_url[&mode]  -> 302 Stripe
 *   Stripe  -> GET  /stripe/oauth/return?code&state            -> 302 return_url?exchange_code
 *   plugin  -> POST /stripe/claim {exchange_code}              -> 200 {token bundle}
 *   plugin  -> POST /stripe/deauthorize {stripe_user_id}       -> 200 {ok}
 */

export interface Env {
    EXCHANGE: KVNamespace;
    BROKER_PUBLIC_URL: string;
    STRIPE_CLIENT_ID_LIVE: string;
    STRIPE_SECRET_LIVE: string;
    STRIPE_CLIENT_ID_TEST: string;
    STRIPE_SECRET_TEST: string;
    STATE_SIGNING_SECRET: string;
}

type Mode = 'live' | 'test';

const STATE_MAX_AGE_MS = 15 * 60 * 1000;
const EXCHANGE_TTL_SECONDS = 180;

export default {
    async fetch(req: Request, env: Env): Promise<Response> {
        const url = new URL(req.url);
        try {
            if (req.method === 'GET' && url.pathname === '/stripe/authorize') {
                return await authorize(url, env);
            }
            if (req.method === 'GET' && url.pathname === '/stripe/oauth/return') {
                return await oauthReturn(url, env);
            }
            if (req.method === 'POST' && url.pathname === '/stripe/claim') {
                return await claim(req, env);
            }
            if (req.method === 'POST' && url.pathname === '/stripe/deauthorize') {
                return await deauthorize(req, env);
            }
            return text(404, 'not found');
        } catch (e) {
            return text(500, 'broker error: ' + (e as Error).message);
        }
    },
};

// --- 1. authorize: build Stripe OAuth URL, redirect the admin's browser ---

async function authorize(url: URL, env: Env): Promise<Response> {
    const state = url.searchParams.get('state') ?? '';
    const returnUrl = url.searchParams.get('return_url') ?? '';
    const mode: Mode = url.searchParams.get('mode') === 'test' ? 'test' : 'live';

    if (state === '' || !isAllowedReturnUrl(returnUrl)) {
        return text(400, 'bad request');
    }

    const signed = await signState({ s: state, r: returnUrl, m: mode, t: Date.now() }, env);
    const clientId = mode === 'test' ? env.STRIPE_CLIENT_ID_TEST : env.STRIPE_CLIENT_ID_LIVE;

    const auth = new URL('https://connect.stripe.com/oauth/authorize');
    auth.searchParams.set('response_type', 'code');
    auth.searchParams.set('client_id', clientId);
    auth.searchParams.set('scope', 'read_write');
    auth.searchParams.set('redirect_uri', env.BROKER_PUBLIC_URL + '/stripe/oauth/return');
    auth.searchParams.set('state', signed);

    return redirect(auth.toString());
}

// --- 2. oauth/return: Stripe redirect_uri; exchange code, stash, bounce back ---

async function oauthReturn(url: URL, env: Env): Promise<Response> {
    const signed = url.searchParams.get('state') ?? '';
    const st = await verifyState(signed, env);
    if (!st) {
        // Cannot trust return_url without a valid signature.
        return text(400, 'invalid state');
    }

    const err = url.searchParams.get('error');
    if (err) {
        return redirect(appendParams(st.r, { connect_error: err, state: st.s }));
    }

    const code = url.searchParams.get('code') ?? '';
    if (code === '') {
        return redirect(appendParams(st.r, { connect_error: 'missing_code', state: st.s }));
    }

    const secret = st.m === 'test' ? env.STRIPE_SECRET_TEST : env.STRIPE_SECRET_LIVE;
    const tokenRes = await fetch('https://connect.stripe.com/oauth/token', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({
            grant_type: 'authorization_code',
            code,
            client_secret: secret,
        }),
    });
    const tok = (await tokenRes.json()) as Record<string, unknown>;
    if (!tokenRes.ok || typeof tok.stripe_user_id !== 'string') {
        const reason = typeof tok.error === 'string' ? tok.error : 'exchange_failed';
        return redirect(appendParams(st.r, { connect_error: reason, state: st.s }));
    }

    const exchangeCode = hex(crypto.getRandomValues(new Uint8Array(32)));
    await env.EXCHANGE.put(
        'xc:' + exchangeCode,
        JSON.stringify({
            mode: st.m,
            stripe_user_id: tok.stripe_user_id,
            access_token: typeof tok.access_token === 'string' ? tok.access_token : '',
            publishable_key:
                typeof tok.stripe_publishable_key === 'string' ? tok.stripe_publishable_key : '',
        }),
        { expirationTtl: EXCHANGE_TTL_SECONDS }
    );

    return redirect(appendParams(st.r, { state: st.s, exchange_code: exchangeCode }));
}

// --- 3. claim: plugin server-to-server exchange; one-time, then deleted ---

async function claim(req: Request, env: Env): Promise<Response> {
    const body = (await safeJson(req)) as { exchange_code?: unknown };
    const xc = typeof body.exchange_code === 'string' ? body.exchange_code : '';
    if (xc === '') {
        return json(400, { error: 'missing_exchange_code' });
    }

    const key = 'xc:' + xc;
    const raw = await env.EXCHANGE.get(key);
    if (raw === null) {
        return json(404, { error: 'expired' });
    }
    await env.EXCHANGE.delete(key); // one-time

    const b = JSON.parse(raw) as {
        mode: Mode;
        stripe_user_id: string;
        access_token: string;
        publishable_key: string;
    };

    // Shape exactly as StripeConnectAccount::store() expects; only the
    // onboarded mode's pair is populated, the other stays empty.
    const out = {
        stripe_user_id: b.stripe_user_id,
        stripe_access_token: b.mode === 'live' ? b.access_token : '',
        stripe_access_token_test: b.mode === 'test' ? b.access_token : '',
        stripe_publishable_key: b.mode === 'live' ? b.publishable_key : '',
        stripe_publishable_key_test: b.mode === 'test' ? b.publishable_key : '',
    };
    return json(200, out);
}

// --- 4. deauthorize: revoke the platform's access to a connected account ---

async function deauthorize(req: Request, env: Env): Promise<Response> {
    const body = (await safeJson(req)) as { stripe_user_id?: unknown };
    const acct = typeof body.stripe_user_id === 'string' ? body.stripe_user_id : '';
    if (acct === '') {
        return json(400, { error: 'missing_stripe_user_id' });
    }

    // Mode is unknown here; try live then test. "Already deauthorized" or an
    // account that belongs to the other mode is treated as success: the
    // plugin clears local state regardless and this stays idempotent.
    for (const mode of ['live', 'test'] as Mode[]) {
        const ok = await stripeDeauthorize(acct, mode, env);
        if (ok) {
            return json(200, { ok: true });
        }
    }
    return json(200, { ok: true });
}

async function stripeDeauthorize(acct: string, mode: Mode, env: Env): Promise<boolean> {
    const clientId = mode === 'test' ? env.STRIPE_CLIENT_ID_TEST : env.STRIPE_CLIENT_ID_LIVE;
    const secret = mode === 'test' ? env.STRIPE_SECRET_TEST : env.STRIPE_SECRET_LIVE;
    if (!clientId || !secret) {
        return false;
    }
    const res = await fetch('https://connect.stripe.com/oauth/deauthorize', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/x-www-form-urlencoded',
            Authorization: 'Bearer ' + secret,
        },
        body: new URLSearchParams({ client_id: clientId, stripe_user_id: acct }),
    });
    if (res.ok) {
        return true;
    }
    const data = (await res.json().catch(() => ({}))) as Record<string, unknown>;
    // Stripe returns this when the account already isn't connected to us.
    return data.error === 'invalid_grant';
}

// --- signed state (no DB needed for the authorize -> return passthrough) ---

interface StatePayload {
    s: string; // plugin's original CSRF state
    r: string; // plugin return_url
    m: Mode;
    t: number; // issued-at ms
}

async function signState(p: StatePayload, env: Env): Promise<string> {
    const data = b64urlEncode(new TextEncoder().encode(JSON.stringify(p)));
    const sig = await hmac(data, env.STATE_SIGNING_SECRET);
    return data + '.' + sig;
}

async function verifyState(token: string, env: Env): Promise<StatePayload | null> {
    const dot = token.indexOf('.');
    if (dot < 0) {
        return null;
    }
    const data = token.slice(0, dot);
    const sig = token.slice(dot + 1);
    if (!(await hmacVerify(data, sig, env.STATE_SIGNING_SECRET))) {
        return null;
    }
    try {
        const p = JSON.parse(new TextDecoder().decode(b64urlDecode(data))) as StatePayload;
        if (typeof p.t !== 'number' || Date.now() - p.t > STATE_MAX_AGE_MS) {
            return null;
        }
        if (typeof p.s !== 'string' || !isAllowedReturnUrl(p.r)) {
            return null;
        }
        return p;
    } catch {
        return null;
    }
}

// --- helpers ---

function isAllowedReturnUrl(u: string): boolean {
    try {
        const p = new URL(u);
        if (p.protocol === 'https:') {
            return true;
        }
        // Allow plain http only for local-dev sites.
        return p.protocol === 'http:' && (p.hostname === 'localhost' || p.hostname === '127.0.0.1');
    } catch {
        return false;
    }
}

function appendParams(base: string, params: Record<string, string>): string {
    const u = new URL(base);
    for (const [k, v] of Object.entries(params)) {
        u.searchParams.set(k, v);
    }
    return u.toString();
}

async function hmac(data: string, secret: string): Promise<string> {
    const key = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['sign']
    );
    const sig = await crypto.subtle.sign('HMAC', key, new TextEncoder().encode(data));
    return b64urlEncode(new Uint8Array(sig));
}

async function hmacVerify(data: string, sig: string, secret: string): Promise<boolean> {
    const key = await crypto.subtle.importKey(
        'raw',
        new TextEncoder().encode(secret),
        { name: 'HMAC', hash: 'SHA-256' },
        false,
        ['verify']
    );
    try {
        return await crypto.subtle.verify(
            'HMAC',
            key,
            b64urlDecode(sig),
            new TextEncoder().encode(data)
        );
    } catch {
        return false;
    }
}

function b64urlEncode(bytes: Uint8Array): string {
    let s = '';
    for (const b of bytes) {
        s += String.fromCharCode(b);
    }
    return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

function b64urlDecode(s: string): Uint8Array {
    const b64 = s.replace(/-/g, '+').replace(/_/g, '/');
    const bin = atob(b64 + '==='.slice((b64.length + 3) % 4));
    const out = new Uint8Array(bin.length);
    for (let i = 0; i < bin.length; i++) {
        out[i] = bin.charCodeAt(i);
    }
    return out;
}

function hex(bytes: Uint8Array): string {
    let s = '';
    for (const b of bytes) {
        s += b.toString(16).padStart(2, '0');
    }
    return s;
}

async function safeJson(req: Request): Promise<Record<string, unknown>> {
    try {
        return (await req.json()) as Record<string, unknown>;
    } catch {
        return {};
    }
}

function redirect(location: string): Response {
    return new Response(null, { status: 302, headers: { Location: location } });
}

function json(status: number, body: unknown): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: { 'Content-Type': 'application/json' },
    });
}

function text(status: number, body: string): Response {
    return new Response(body, { status, headers: { 'Content-Type': 'text/plain' } });
}
