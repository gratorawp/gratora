import { test, expect } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';

/**
 * Regression for QA bug #4: with `require_approval: true` the start-fundraising
 * success card still said "Your page is live!" - misleading, because the
 * fundraiser is actually pending and the public URL redirects. Fix added
 * data-attr swap on title + sub, JS branch on `pending` from the response,
 * and a hidden share row.
 *
 * Test: enable require_approval via REST, submit a fresh registration, assert
 * the under-review copy + that no share URL is shown. Restore the setting at
 * the end so the rest of the suite sees the baseline state.
 *
 * Needs DONO_E2E_P2P_CAMPAIGN_ID + admin creds.
 */
test.describe('P2P regression #4: pending success copy', () => {
    let restoreRequireApproval = false;

    test.beforeEach(async ({ page }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');

        // Login as admin so we can flip the campaign's require_approval flag.
        await page.goto('/wp-login.php');
        await page.fill('#user_login', process.env.DONO_E2E_ADMIN_USER ?? 'admin');
        await page.fill('#user_pass', process.env.DONO_E2E_ADMIN_PASS ?? 'password');
        await page.click('#wp-submit');
        await page.waitForURL(/\/wp-admin\//);

        const result = await page.evaluate(async (id) => {
            const nonce = (window as any).wpApiSettings?.nonce ?? '';
            const before = await fetch(`/wp-json/dono/v1/admin/p2p/campaigns/${id}/settings`).then(r => r.json());
            const previous = !! before.require_approval;
            await fetch(`/wp-json/dono/v1/admin/p2p/campaigns/${id}/settings`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ require_approval: true }),
            });
            return { previous };
        }, P2P.campaignId);
        restoreRequireApproval = ! result.previous;
    });

    test.afterEach(async ({ page }) => {
        if (! restoreRequireApproval) return;
        await page.evaluate(async (id) => {
            const nonce = (window as any).wpApiSettings?.nonce ?? '';
            await fetch(`/wp-json/dono/v1/admin/p2p/campaigns/${id}/settings`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ require_approval: false }),
            });
        }, P2P.campaignId);
    });

    test('pending fundraiser sees under-review copy, no share URL', async ({ page }) => {
        // Public flow: open the start form and submit a new fundraiser.
        await page.goto(P2P.startPath);

        await page.locator('input[name="name"]').fill('Pending Test');
        await page.locator('input[name="email"]').fill(`pending-${Date.now()}@dono.test`);
        await page.locator('input[name="display_name"]').fill(`Pending ${Date.now()}`);
        await page.locator('button[type="submit"]').click();

        const done = page.locator('[data-dps-done]');
        await expect(done, 'success card appears').toBeVisible({ timeout: 10_000 });

        // The fix: pending state swaps the title + sub to the review copy
        // and hides the share row.
        const title = done.locator('.dps-done__title');
        await expect(title, 'title reads under-review, not "live"').toContainText(/under review/i);
        await expect(title).not.toContainText(/live/i);

        const sub = done.locator('.dps-done__sub');
        await expect(sub).toContainText(/will review|approved/i);

        const share = done.locator('[data-dps-share]');
        // The share row should be hidden (display: none) for pending fundraisers.
        await expect(share).toBeHidden();
    });
});
