import { test, expect, request } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';

/**
 * Regression for QA bug #3: a sandbox donation submitted via the donation
 * form sat at status=pending forever. The form ran POST /donations (intent)
 * but never POST /donations/{ref}/confirm, and the sandbox gateway had no
 * off-site step to fire it async. Fix: GatewayIntentResult gained an
 * `auto_confirm` flag; sandbox returns true; the donations controller calls
 * gateway->confirm() + donations->confirm() in the same request.
 *
 * Test: POST /donations directly with gateway=sandbox and assert the response
 * already reports status=paid. Bypasses the public form (which has its own
 * E2E in donate.spec.ts) to isolate the gateway-confirm path.
 *
 * The create route is public (permission_callback __return_true) but gated by
 * AntiSpamGuard, so the POST still needs the form's HMAC token + form id. Both
 * are server-rendered into the donation form's `script[data-dono-form-config]`
 * blob, which is read from the raw HTML rather than the live DOM: the Preact
 * runtime clears `form.innerHTML` before mounting, so the script node is gone
 * once the page has hydrated.
 *
 * Needs DONO_E2E_P2P_CAMPAIGN_ID so we can submit against a real campaign.
 */
test.describe('P2P regression #3: sandbox donation auto-confirms', () => {
    test('POST /donations with gateway=sandbox lands as paid in the same request', async ({ baseURL }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');

        const ctx = await request.newContext({ baseURL });

        // The fundraiser page embeds the campaign's donation form; its config
        // blob carries every value the create route gates on.
        const pageRes = await ctx.get(P2P.fundraiserPath);
        expect(pageRes.status(), 'fundraiser page renders').toBe(200);
        const html  = await pageRes.text();
        const match = html.match(/<script[^>]*data-dono-form-config[^>]*>([\s\S]*?)<\/script>/);
        expect(match, 'fundraiser page embeds a donation form with its config blob').not.toBeNull();

        const config = JSON.parse(match![1]);
        expect(config.form_id, 'form config carries the form id').toBeGreaterThan(0);
        expect(config.spam?.formToken, 'form config carries the anti-spam form token').toMatch(/^\d+\.[a-f0-9]{64}$/);

        // No X-WP-Nonce header: config.nonce is empty for anonymous visitors by
        // design (DonationFormShortcode), so a page-cached form can never carry
        // a stale nonce the REST layer would 403 on. The form token and the
        // rate-limit quotas are what protect the public create route.
        expect(config.nonce, 'anonymous donors are issued no REST nonce').toBe('');

        const res = await ctx.post('/wp-json/dono/v1/donations', {
            headers: { 'Content-Type': 'application/json' },
            data: {
                gateway:      'sandbox',
                amount_cents: 1000,
                currency:     config.currency,
                email:        `e2e-sandbox-${Date.now()}@dono.test`,
                profile:      { first_name: 'Sandy', last_name: 'Confirm' },
                form_id:      config.form_id,
                _ft:          config.spam.formToken,
            },
        });
        const raw = await res.text();
        expect(res.status(), `donation create succeeds, got ${res.status()}: ${raw}`).toBe(201);
        const body = JSON.parse(raw);
        expect(body.status, `sandbox auto-confirm: donation should be paid, got ${body.status}`).toBe('paid');
        expect(body.reference, 'response carries a reference').toMatch(/^DONO-/);
        expect(body.requires_action, 'sandbox needs no off-site step').toBe(false);
        await ctx.dispose();
    });
});
