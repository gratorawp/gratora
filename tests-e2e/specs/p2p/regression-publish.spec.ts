import { test, expect } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';
import { AdminPage } from '../../helpers/AdminPage';

/**
 * Regression for QA bug #1: a fresh draft campaign had no way to be published
 * from the admin UI. The overflow menu only had Duplicate / Archive / Delete
 * and the Settings tabs had no status control. Fix added Publish campaign /
 * Move to draft items to the HeaderMenu, wired through the existing PUT to
 * /admin/campaigns/{id}.
 *
 * This spec flips the seeded campaign to draft via REST, opens it in the
 * admin, walks the overflow menu, asserts Publish is offered, clicks it, and
 * confirms the status pill flips to Active.
 *
 * Needs DONO_E2E_P2P_CAMPAIGN_ID + admin creds.
 */
test.describe('P2P regression #1: publish menu', () => {
    let admin: AdminPage;

    test.beforeEach(async ({ page }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');
        admin = new AdminPage(page);
        await admin.login();
    });

    test('admin can publish a draft campaign from the overflow menu', async ({ page }) => {
        // Flip to draft as the starting state via REST so the spec is
        // self-contained (doesn't depend on prior spec order).
        await page.evaluate(async (id) => {
            const nonce = (window as any).wpApiSettings?.nonce ?? '';
            await fetch(`/wp-json/dono/v1/admin/campaigns/${id}`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ status: 'draft' }),
            });
        }, P2P.campaignId);

        await admin.openCampaign(P2P.campaignId!);

        // Sanity: header pill reflects draft.
        await expect(page.locator('.dono-pill').filter({ hasText: /^Draft$/i }).first()).toBeVisible();

        // Open overflow + publish.
        await page.locator('.dono-menu__trigger').click();
        const publishItem = page.locator('.dono-menu__item').filter({ hasText: 'Publish campaign' });
        await expect(publishItem, 'overflow menu offers Publish for a draft campaign').toBeVisible();
        await publishItem.click();

        // After reload the pill should read Active and the overflow should
        // now offer "Move to draft" instead.
        await expect(page.locator('.dono-pill').filter({ hasText: /^Active$/i }).first()).toBeVisible({ timeout: 10_000 });

        await page.locator('.dono-menu__trigger').click();
        await expect(page.locator('.dono-menu__item').filter({ hasText: 'Move to draft' })).toBeVisible();
        await expect(page.locator('.dono-menu__item').filter({ hasText: 'Publish campaign' })).toHaveCount(0);
    });
});
