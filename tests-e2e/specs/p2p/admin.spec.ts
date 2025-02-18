import { test, expect } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';
import { AdminPage } from '../../helpers/AdminPage';

/**
 * wp-admin coverage for the P2P campaign: the campaign-detail "View page" link,
 * the Appearance hide-header/footer toggles (round-tripped through a save), and
 * the Fundraisers extension tab listing the seeded fundraisers.
 *
 * Needs DONO_E2E_P2P_CAMPAIGN_ID + admin creds (the seed prints both); skips
 * cleanly when they are absent.
 */
test.describe('P2P admin', () => {
    let admin: AdminPage;

    test.beforeEach(async ({ page }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (run wp dono-p2p e2e-seed)');
        admin = new AdminPage(page);
        await admin.login();
    });

    test('campaign detail shows the View page link', async ({ page }) => {
        await admin.openCampaign(P2P.campaignId!);

        const view = page.getByRole('link', { name: /View page/ });
        await expect(view).toBeVisible();
        await expect(view).toHaveAttribute('href', new RegExp(P2P.campaignPath.replace(/\//g, '\\/')));
        await expect(view).toHaveAttribute('target', '_blank');
    });

    test('appearance toggles persist a save round-trip', async ({ page }) => {
        await admin.openCampaign(P2P.campaignId!, 'settings');

        await page.getByRole('tab', { name: 'Appearance' }).click();
        const headerToggle = page.getByLabel('Hide theme header');
        await expect(headerToggle).toBeVisible();
        await expect(page.getByLabel('Hide theme footer')).toBeVisible();

        const initial = await headerToggle.isChecked();

        // Flip it and save.
        await headerToggle.click();
        const saveBtn = page.getByRole('button', { name: 'Save changes' });
        await expect(saveBtn).toBeVisible();
        await saveBtn.click();
        await expect(page.locator('.dono-save-bar')).toBeHidden({ timeout: 10_000 });

        // Reload and confirm the new value stuck.
        await admin.openCampaign(P2P.campaignId!, 'settings');
        await page.getByRole('tab', { name: 'Appearance' }).click();
        await expect(page.getByLabel('Hide theme header')).toBeChecked({ checked: ! initial });

        // Restore the original value so the fixture stays clean.
        await page.getByLabel('Hide theme header').click();
        await page.getByRole('button', { name: 'Save changes' }).click();
        await expect(page.locator('.dono-save-bar')).toBeHidden({ timeout: 10_000 });
    });

    test('fundraisers tab lists the seeded fundraisers', async ({ page }) => {
        // The section tabs are <a> links; navigating straight to the tab avoids a
        // detach race when the tablist re-renders after the record loads.
        await admin.openCampaign(P2P.campaignId!, 'fundraisers');

        await expect(page.getByText('Solo Sam').first()).toBeVisible({ timeout: 10_000 });
        await expect(page.getByText('Cara Captain').first()).toBeVisible();
        await expect(page.getByText('Joe Member').first()).toBeVisible();
    });

    test('teams tab lists the seeded team', async ({ page }) => {
        await admin.openCampaign(P2P.campaignId!, 'teams');

        await expect(page.getByText(P2P.teamName).first()).toBeVisible({ timeout: 10_000 });
    });

    test('a fundraiser can be paused and resumed', async ({ page }) => {
        await admin.openCampaign(P2P.campaignId!, 'fundraisers');

        const row = () => page.locator('tr', { hasText: 'Solo Sam' }).first();
        await expect(row()).toContainText('Active', { timeout: 10_000 });

        const action = async (name: string) => {
            await row().getByRole('button', { name: 'Actions' }).click();
            await page.getByRole('menuitem', { name, exact: true }).click();
        };

        await action('Pause');
        await expect(row()).toContainText('Paused', { timeout: 10_000 });

        await action('Resume');
        await expect(row()).toContainText('Active', { timeout: 10_000 });
    });

    test('approval queue lists a pending fundraiser kept off the public site', async ({ page }) => {
        await admin.openCampaign(P2P.campaignId!, 'approval');
        await expect(page.getByText('Pending Pat').first()).toBeVisible({ timeout: 10_000 });

        // Pending fundraisers are not public until approved.
        await page.goto(`${P2P.campaignPath}fundraiser/pending-pat/`);
        await expect(page.getByText('Pending Pat')).toHaveCount(0);
    });
});
