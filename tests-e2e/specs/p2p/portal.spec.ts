import { test, expect, P2P } from '../../fixtures/p2p';

/**
 * Donor-portal "My fundraising" tab: magic-link sign-in, then the self-service
 * manage round-trip (edit a fundraiser's headline and confirm it persists
 * server-side). Needs a single-use magic link (DONO_E2E_PORTAL_URL) that
 * `wp dono-p2p e2e-seed` prints fresh each run, for the solo fundraiser (one
 * Manage control). Reseed before re-running.
 */
test.describe('P2P donor portal', () => {
    test('magic link signs in and a fundraiser can be managed', async ({ healthyPage }) => {
        const link = process.env.DONO_E2E_PORTAL_URL;
        test.skip(! link, 'set DONO_E2E_PORTAL_URL from `wp dono-p2p e2e-seed` (single-use)');

        // Bare portal path (no token) reuses the session cookie after the first
        // exchange, so we can reload to verify server-side persistence.
        const portalPath = new URL(link!).pathname;

        await healthyPage.goto(link!);

        const tab = healthyPage.getByRole('tab', { name: 'My fundraising' });
        await expect(tab).toBeVisible({ timeout: 15_000 });
        await tab.click();

        await expect(healthyPage.getByText(P2P.fundraiserName).first()).toBeVisible({ timeout: 15_000 });

        // Recruiter surface: the seed has Sam recruit Joe, so the recruit link
        // (a start URL carrying ?ref=<fundraiser id>) and the count are shown.
        await expect(healthyPage.locator('.dp-p2p__recruit .dp-p2p__share-url').first())
            .toHaveValue(/[?&]ref=\d+/);
        await expect(healthyPage.locator('.dp-p2p__recruit-count').first()).toBeVisible();

        // Manage -> edit the headline -> save.
        const headline = `E2E headline ${Date.now()}`;
        await healthyPage.locator('.dp-p2p__manage').first().click();
        const headlineInput = healthyPage.getByLabel(/Headline/);
        await expect(headlineInput).toBeVisible();
        await headlineInput.fill(headline);
        await healthyPage.getByRole('button', { name: 'Save changes' }).click();

        // The form closes back to the card on success.
        await expect(healthyPage.getByRole('button', { name: 'Save changes' })).toBeHidden({ timeout: 10_000 });

        // Reload via the cookie session and confirm the edit persisted.
        await healthyPage.goto(portalPath);
        await healthyPage.getByRole('tab', { name: 'My fundraising' }).click();
        await healthyPage.locator('.dp-p2p__manage').first().click();
        await expect(healthyPage.getByLabel(/Headline/)).toHaveValue(headline);
    });
});
