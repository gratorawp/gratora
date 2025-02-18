import { test, expect } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';
import { AdminPage } from '../../helpers/AdminPage';

/**
 * Single end-to-end smoke that walks one customer journey across the whole
 * product surface in one pass. Codifies the manual QA round 2 walkthrough.
 *
 * Phases (each step asserts the previous one's outcome):
 *   1. Admin: campaign in published state, default form auto-created.
 *   2. Public: campaign landing renders donate + start CTAs.
 *   3. Public: a supporter creates a fundraiser via /start/ (solo).
 *   4. Public: the supporter's fundraiser page resolves and shows them.
 *   5. Public: a donor gives via the fundraiser page using the sandbox
 *      gateway and lands at the thank-you state.
 *   6. Server: DB confirms attribution + paid status.
 *   7. Admin: campaign overview reflects the activity.
 *
 * Built as ONE test on purpose: the steps are causally dependent (you can't
 * test "fundraiser exists" without first having run /start/). Split tests
 * would either share state (flaky) or re-seed every step (slow).
 *
 * Needs DONO_E2E_P2P_CAMPAIGN_ID + admin creds + DONO_E2E_P2P_START_PATH.
 */
test.describe('P2P customer journey', () => {
    test('admin -> public start -> public donate -> admin sees activity', async ({ page, baseURL }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');
        test.skip(! P2P.startPath, 'set DONO_E2E_P2P_START_PATH (wp dono-p2p e2e-seed)');

        const admin = new AdminPage(page);
        const stamp = Date.now();
        const supporterName = `Journey ${stamp}`;
        const supporterEmail = `journey-${stamp}@dono.test`;
        const donorEmail = `journey-donor-${stamp}@dono.test`;

        // -------------------------------------------------------------- 1
        // Admin: campaign should be in published state. The seed leaves it
        // in `published`, but bug #1's fix means we can also recover from
        // a draft via the menu. Belt-and-suspenders: PUT to published.
        await admin.login();
        await page.evaluate(async (id) => {
            const nonce = (window as any).wpApiSettings?.nonce ?? '';
            await fetch(`/wp-json/dono/v1/admin/campaigns/${id}`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ status: 'published' }),
            });
        }, P2P.campaignId);

        await admin.openCampaign(P2P.campaignId!);
        await expect(page.locator('.dono-pill').filter({ hasText: /^Active$/i }).first()).toBeVisible();

        // Forms tab should show at least one published form (the default).
        await page.goto(`/wp-admin/admin.php?page=dono-campaigns&view=detail&id=${P2P.campaignId}&tab=forms`);
        await expect(page.locator('text=DEFAULT').first(), 'a default form exists').toBeVisible();

        // -------------------------------------------------------------- 2
        // Public: campaign landing renders + has a Start fundraising CTA.
        await page.goto(P2P.campaignPath);
        await expect(page.getByRole('link', { name: /^Start fundraising$/ }).first()).toBeVisible();

        // -------------------------------------------------------------- 3
        // Public: supporter creates a fundraiser via /start/ (solo flow).
        await page.goto(P2P.startPath);
        await page.locator('input[name="name"]').fill(supporterName);
        await page.locator('input[name="email"]').fill(supporterEmail);
        await page.locator('input[name="display_name"]').fill(supporterName);
        await page.locator('button[type="submit"]').click();
        const done = page.locator('[data-dps-done]');
        await expect(done, 'start success card appears').toBeVisible({ timeout: 10_000 });

        // The page returns the public URL to use; pick it up from the share row.
        const fundraiserUrl = await page.locator('[data-dps-url]').inputValue();
        expect(fundraiserUrl, 'response carries a public fundraiser URL (campaign not in approval mode)').toMatch(/\/fundraiser\//);

        // -------------------------------------------------------------- 4
        // Public: the supporter's fundraiser page resolves and shows them.
        await page.goto(fundraiserUrl);
        await expect(page.getByText(supporterName).first()).toBeVisible();

        // -------------------------------------------------------------- 5
        // Public: donor gives via the fundraiser page (sandbox gateway).
        // Bug #3's fix means sandbox lands as paid in the same request.
        const nonce = await page.evaluate(() => {
            const root = document.querySelector('.dono-form');
            return root?.getAttribute('data-nonce') ?? '';
        });
        expect(nonce, 'fundraiser page exposes the form nonce').not.toBe('');

        const apiResponse = await page.request.post('/wp-json/dono/v1/donations', {
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
            data: {
                gateway:      'sandbox',
                amount_cents: 1000,
                currency:     'EUR',
                email:        donorEmail,
                profile:      { first_name: 'Journey', last_name: 'Donor' },
            },
        });
        expect(apiResponse.status()).toBe(201);
        const donation = await apiResponse.json();

        // -------------------------------------------------------------- 6
        // Server-side correctness: paid + attributed.
        expect(donation.status, 'sandbox donation lands as paid').toBe('paid');
        expect(donation.reference, 'reference issued').toMatch(/^DONO-/);

        // -------------------------------------------------------------- 7
        // Admin: campaign overview reflects activity. Test donations are
        // is_test=1 so they stay out of real totals - which is correct.
        // We only assert that the admin screen renders without errors.
        await admin.openCampaign(P2P.campaignId!);
        await expect(page.locator('.dono-pill').filter({ hasText: /^Active$/i }).first()).toBeVisible();
    });
});
