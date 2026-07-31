import { test, expect, type Page } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';
import { AdminPage } from '../../helpers/AdminPage';

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

/**
 * A wp_rest nonce for the signed-in admin. Core's own renewal endpoint rather
 * than window.wpApiSettings, which is only localised on the admin screens that
 * enqueue wp-api-request - the dashboard the login lands on is not one of them,
 * so reading it there yields '' and every settings call 403s.
 */
async function restNonce(page: Page): Promise<string> {
    return page.evaluate(async () => {
        const res = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' });
        return (await res.text()).trim();
    });
}

test.describe('P2P regression #4: pending success copy', () => {
    let restoreRequireApproval = false;
    let adminNonce = '';

    test.beforeEach(async ({ page }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');

        // Login as admin so we can flip the campaign's require_approval flag.
        await new AdminPage(page).login();
        adminNonce = await restNonce(page);

        const result = await page.evaluate(async ({ id, nonce }) => {
            const url     = `/wp-json/dono/v1/admin/p2p/campaigns/${id}/settings`;
            const headers = { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce };
            const before  = await (await fetch(url, { credentials: 'same-origin', headers })).json();
            const after   = await (await fetch(url, {
                method:      'PUT',
                credentials: 'same-origin',
                headers,
                body:        JSON.stringify({ require_approval: true }),
            })).json();
            return { previous: !! before.require_approval, now: !! after.require_approval };
        }, { id: P2P.campaignId as number, nonce: adminNonce });

        // A refused flip renders exactly like the regression under test (the
        // fundraiser goes active and the card says "live"), so fail here rather
        // than blame the copy.
        expect(result.now, 'require_approval enabled on the campaign').toBe(true);
        restoreRequireApproval = ! result.previous;
    });

    test.afterEach(async ({ page }) => {
        if (! restoreRequireApproval) return;
        restoreRequireApproval = false;
        await page.evaluate(async ({ id, nonce }) => {
            await fetch(`/wp-json/dono/v1/admin/p2p/campaigns/${id}/settings`, {
                method:      'PUT',
                credentials: 'same-origin',
                headers:     { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:        JSON.stringify({ require_approval: false }),
            });
        }, { id: P2P.campaignId as number, nonce: adminNonce });
    });

    test('pending fundraiser sees under-review copy, no share URL', async ({ page }) => {
        // Public flow: open the start form and submit a new fundraiser. The
        // wp-admin session is irrelevant here - the form only pre-fills for a
        // donor-portal session, so the name/email fields still render.
        await page.goto(P2P.startPath);

        const form = page.locator('[data-dono-start]');
        await expect(form).toBeVisible();

        const stamp = Date.now();
        await form.getByLabel('Full name').fill('Pending Test');
        await form.getByLabel('Email address').fill(`pending-${stamp}@dono.test`);
        await form.getByLabel('Display name').fill(`Pending ${stamp}`);
        await form.getByRole('button', { name: /create my page/i }).click();

        const done = page.locator('.dono-p2p-done');
        await expect(done, 'success card appears').toBeVisible({ timeout: 15_000 });

        // The fix: pending state swaps the title + sub to the review copy
        // and hides the share row.
        const title = done.getByRole('heading');
        await expect(title, 'title reads under-review, not "live"').toContainText(/under review/i);
        await expect(title).not.toContainText(/live/i);

        const sub = done.locator('.dono-p2p-done__sub');
        await expect(sub).toContainText(/will review|approved/i);

        // The share row should be hidden (display: none) for pending
        // fundraisers, and the link field never gets a URL to leak.
        await expect(done.locator('[data-dono-p2p-share]')).toBeHidden();
        await expect(done.locator('[data-dono-p2p-url]')).toHaveValue('');
    });
});
