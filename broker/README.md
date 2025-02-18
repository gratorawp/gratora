# Dono Stripe Connect broker

Tiny Cloudflare Worker that holds the Stripe **platform** secret and runs the
Connect OAuth code exchange, so the GPL plugin never has to. This is the
standard hosted-broker pattern for a distributed plugin that cannot ship a
platform secret. Contract: `../docs/stripe-connect-broker.md`.

Not part of the WordPress plugin (`.distignore` excludes `/broker`). Deploy
it separately.

## Endpoints

- `GET  /stripe/authorize` - build Stripe OAuth URL, 302 the admin to Stripe
- `GET  /stripe/oauth/return` - Stripe redirect_uri; exchanges the code,
  stashes the tokens under a one-time `exchange_code`, 302 back to the site
- `POST /stripe/claim` - plugin server-to-server; returns the token bundle
  once, then deletes it (tokens never travel in a browser URL)
- `POST /stripe/deauthorize` - revoke

## One-time setup

1. **Stripe**: in the platform account's Connect settings, register the
   redirect URI for **both** live and test:
   ```
   https://<broker-domain>/stripe/oauth/return
   ```
   Note the live + test `client_id` (`ca_...`) and the platform secret keys.

2. **KV**:
   ```
   cd broker && npm install
   npx wrangler kv namespace create EXCHANGE
   ```
   Put the returned id into `wrangler.toml` (`kv_namespaces[0].id`).

3. **Vars / secrets**:
   ```
   # public base URL of the deployed worker (no trailing slash)
   # set in wrangler.toml [vars] BROKER_PUBLIC_URL, or override per-env

   npx wrangler secret put STRIPE_CLIENT_ID_LIVE
   npx wrangler secret put STRIPE_SECRET_LIVE
   npx wrangler secret put STRIPE_CLIENT_ID_TEST
   npx wrangler secret put STRIPE_SECRET_TEST
   npx wrangler secret put STATE_SIGNING_SECRET   # random 32+ bytes
   ```

4. **Deploy + domain**:
   ```
   npx wrangler deploy
   ```
   Point the chosen domain (e.g. `connect.getdono.com`) at the worker via a
   Cloudflare custom domain/route, and set `BROKER_PUBLIC_URL` to it.

5. **Plugin**: set `StripeConnect::BROKER_URL` (in
   `src/Gateways/Stripe/StripeConnect.php`) to the same base URL. That is the
   only plugin-side change tied to the domain.

## Local development

- Broker: `npm run dev` (wrangler dev). Use a Stripe **test** Connect app and
  a tunnel if you want the full OAuth round trip.
- Plugin without the broker: the `WP_DEBUG` "dev-connect" paste path in
  Settings -> Gateways stores a test account id + test access token directly,
  exercising the whole plugin flow with no broker.

## Notes

- No database. Authorize->return state rides a signed (HMAC) value through
  Stripe's own `state` param; the only stored thing is the short-TTL
  one-time `exchange_code` bundle in KV.
- `mode` (`live` default, `?mode=test`) selects which credential set is used
  and which token pair is returned.
- Deauthorize is unauthenticated by design: a baked shared secret in a GPL
  plugin is readable by anyone, so it cannot authenticate installs. Worst
  case is an account disconnect, and Stripe still requires prior platform
  authorization. Token confidentiality is protected by the one-time,
  short-TTL, signature-bound `exchange_code` instead.
