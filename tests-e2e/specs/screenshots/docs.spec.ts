/**
 * Capture documentation widgets by visible heading on a seeded site. Set GRATORA_E2E_SHOTS=1
 * and GRATORA_E2E_SHOTS_DIR, then run the screenshots project with this spec.
 */

import { expect, test, type Locator, type Page } from '@playwright/test';

import { AdminPage } from '../../helpers/AdminPage';
import { shoot } from '../../helpers/capture';

/** The documentation is shot at 1600 wide, so the 2x files land at 3200. */
const VIEWPORT = { width: 1600, height: 1000 };

const admin = (page: string, extra = ''): string => `/wp-admin/admin.php?page=${page}${extra}`;

/** One dashboard widget, addressed by the heading a reader sees. */
function widget(page: Page, heading: string): Locator {
    return page.locator('.gratora-widget-slot').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
}

/**
 * Unpin wp-admin's chrome before a fullPage capture.
 *
 * The admin menu is position:fixed, so a fullPage shot paints it once at the
 * viewport it was captured from and leaves the rest of the column empty, which
 * reads as a rendering glitch in the middle of the article. Static positioning
 * lets it paint the whole height.
 */
async function unpinAdminChrome(page: Page): Promise<void> {
    await page.addStyleTag({
        content: `#adminmenuback, #adminmenuwrap { position: static !important; }
                  #wpadminbar { position: absolute !important; }`,
    });
}

/**
 * Capture one element. Fails rather than writing a wrong image when the target
 * is missing, because a stale screenshot in the docs is worse than a red test.
 */
async function shootElement(page: Page, target: Locator, name: string): Promise<void> {
    await expect(target).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(600);
    await target.screenshot({ path: `${process.env.GRATORA_E2E_SHOTS_DIR}/${name}.png`, animations: 'disabled', caret: 'hide' });
}

test.describe('documentation screenshots', () => {
    test.beforeEach(async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize(VIEWPORT);
        await new AdminPage(page).login();
    });

    test('dashboard', async ({ page }) => {
        await page.goto(admin('gratora'));
        await page.waitForLoadState('networkidle');
        // Recharts draws its series from JS, so idle alone still catches the
        // revenue chart mid-sweep.
        await page.waitForTimeout(2_500);

        await shootElement(page, widget(page, 'Key metrics'), 'dashboard-kpi-cards');
        await shootElement(page, widget(page, 'Revenue'), 'dashboard-revenue-chart');
        await shootElement(page, widget(page, 'Needs attention'), 'dashboard-attention-items');
        await shootElement(page, widget(page, 'Top campaigns'), 'dashboard-top-campaigns');
        await shootElement(page, widget(page, 'Recurring revenue'), 'dashboard-recurring-stats');
        await shootElement(page, widget(page, 'Recent donations'), 'dashboard-recent-activity');

        await unpinAdminChrome(page);
        await shoot(page, 'dashboard-overview', true);
    });

    test('lists and details', async ({ page }) => {
        // Every full-screen documentation image is viewport sized, not fullPage:
        // 1600x1000 at 2x is the 3200x2000 the existing set uses.
        await page.goto(admin('gratora-campaigns'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'campaigns-list');

        await page.goto(admin('gratora-donations'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'donations-list');

        await page.goto(admin('gratora-donors'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'donors-list');

        // Open the first row of each list rather than addressing an id, so the
        // capture survives a reseed.
        await page.goto(admin('gratora-donations'));
        await page.waitForLoadState('networkidle');
        const donation = page.locator('#gratora-admin-donations a[href^="#donation/"], #gratora-admin-donations a[href*="view=detail"]').first();
        await expect(donation).toBeVisible({ timeout: 15_000 });
        await donation.click();
        await page.waitForTimeout(2_500);
        await shoot(page, 'donation-detail');

        await page.goto(admin('gratora-donors'));
        await page.waitForLoadState('networkidle');
        const donor = page.locator('#gratora-admin-donors a[href^="#donor/"]').first();
        await expect(donor).toBeVisible({ timeout: 15_000 });
        await donor.click();
        await page.waitForTimeout(2_500);
        await shoot(page, 'donor-detail');
    });
});
