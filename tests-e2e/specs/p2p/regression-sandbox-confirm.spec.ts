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
 * Needs DONO_E2E_P2P_CAMPAIGN_ID so we can submit against a real campaign.
 */
test.describe('P2P regression #3: sandbox donation auto-confirms', () => {
    test('POST /donations with gateway=sandbox lands as paid in the same request', async ({ baseURL, page }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');

        // We need a public REST nonce. Easiest: open any fundraiser page (it
        // ships the form runtime, which exposes the nonce).
        await page.goto(P2P.fundraiserPath);
        const nonce = await page.evaluate(() => {
            const root = document.querySelector('.dono-form');
            return root?.getAttribute('data-nonce') ?? '';
        });
        expect(nonce, 'fundraiser page exposes the donation-form nonce').not.toBe('');

        const ctx = await request.newContext({ baseURL });
        const res = await ctx.post('/wp-json/dono/v1/donations', {
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            data: {
                gateway:      'sandbox',
                amount_cents: 1000,
                currency:     'EUR',
                email:        `e2e-sandbox-${Date.now()}@dono.test`,
                profile:      { first_name: 'Sandy', last_name: 'Confirm' },
            },
        });
        expect(res.status(), 'donation create succeeds').toBe(201);
        const body = await res.json();
        expect(body.status, `sandbox auto-confirm: donation should be paid, got ${body.status}`).toBe('paid');
        expect(body.reference, 'response carries a reference').toMatch(/^DONO-/);
        await ctx.dispose();
    });
});
