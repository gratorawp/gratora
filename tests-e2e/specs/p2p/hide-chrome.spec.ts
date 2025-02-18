import { test, expect } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';
import { AdminPage } from '../../helpers/AdminPage';

/**
 * The Appearance hide-header/footer toggles must actually drop the theme's
 * header/footer template parts on the campaign's public pages (block theme).
 * admin.spec.ts proves the toggle persists; this proves it takes effect.
 */
test.describe('P2P hide theme chrome', () => {
    test.beforeEach(() => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID');
    });

    const header = (p: import('@playwright/test').Page) => p.locator('header.wp-block-template-part');
    const footer = (p: import('@playwright/test').Page) => p.locator('footer.wp-block-template-part');

    async function setChrome(page: import('@playwright/test').Page, admin: AdminPage, hide: boolean): Promise<void> {
        await admin.openCampaign(P2P.campaignId!, 'settings');
        await page.getByRole('tab', { name: 'Appearance' }).click();
        for (const label of ['Hide theme header', 'Hide theme footer']) {
            const toggle = page.getByLabel(label);
            if ((await toggle.isChecked()) !== hide) await toggle.click();
        }
        const save = page.getByRole('button', { name: 'Save changes' });
        if (await save.isVisible()) {
            await save.click();
            await expect(page.locator('.dono-save-bar')).toBeHidden({ timeout: 10_000 });
        }
    }

    test('toggling hide on removes header/footer, off restores them', async ({ page }) => {
        const admin = new AdminPage(page);
        await admin.login();

        // Baseline: theme chrome present.
        await setChrome(page, admin, false);
        await page.goto(P2P.campaignPath);
        await expect(header(page)).toHaveCount(1);
        await expect(footer(page)).toHaveCount(1);

        // Hidden: chrome gone.
        await setChrome(page, admin, true);
        await page.goto(P2P.campaignPath);
        await expect(header(page)).toHaveCount(0);
        await expect(footer(page)).toHaveCount(0);

        // Restore so the fixture is left clean.
        await setChrome(page, admin, false);
        await page.goto(P2P.campaignPath);
        await expect(header(page)).toHaveCount(1);
    });
});
