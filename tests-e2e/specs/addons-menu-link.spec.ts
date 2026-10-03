import { test, expect, type Locator, type Page } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';
import { describeInk, expectReadable, inkOf, installInk } from '../helpers/contrast';

/**
 * Add-ons is the one link of the Fundraising menu drawn in a colour of its own.
 * Every admin colour scheme paints the menu a different ground, so the colour is
 * measured on each scheme WordPress ships, not trusted from one.
 */

const SCHEMES = ['modern', 'fresh', 'light', 'blue', 'midnight', 'sunrise', 'ectoplasm', 'ocean', 'coffee'];

const PROFILE = '/wp-admin/profile.php';
const DASHBOARD = '/wp-admin/admin.php?page=gratora';
const ADDONS = '/wp-admin/admin.php?page=gratora-addons';

function link(page: Page, id: string): Locator {
    return page.locator(`#toplevel_page_gratora .wp-submenu a[href$="page=${id}"]`);
}

function drawn(locator: Locator): Promise<{ color: string; weight: string }> {
    return locator.evaluate((el) => {
        const style = getComputedStyle(el);

        return { color: style.color, weight: style.fontWeight };
    });
}

async function schemeInUse(page: Page): Promise<string> {
    await page.goto(PROFILE);

    return page.locator('input[name="admin_color"]:checked').inputValue();
}

async function useScheme(page: Page, scheme: string): Promise<void> {
    await page.goto(PROFILE);
    await page.check(`input[name="admin_color"][value="${scheme}"]`);
    await page.click('#submit');
    await expect(page.locator('#message.updated')).toBeVisible();
    await expect(page.locator('body')).toHaveClass(new RegExp(`\\badmin-color-${scheme}\\b`));
}

test.describe('the add-ons link in the menu', () => {
    let before = '';

    test.beforeEach(async ({ page }) => {
        await page.addInitScript(installInk);
        await new AdminPage(page).login();
        before = await schemeInUse(page);
    });

    test.afterEach(async ({ page }) => {
        if (before !== '') {
            await useScheme(page, before);
        }
    });

    test('has a colour of its own that reads on every colour scheme', async ({ page }) => {
        test.setTimeout(120_000);

        for (const scheme of SCHEMES) {
            await useScheme(page, scheme);
            await page.goto(DASHBOARD);

            const addons = await inkOf(link(page, 'gratora-addons'));
            const neighbour = await inkOf(link(page, 'gratora-settings'));

            expect(addons.color, `${scheme}: ${describeInk(addons)}`).not.toEqual(neighbour.color);
            expectReadable(addons, scheme);
        }
    });

    test('is the colour of the test mode badge on a dark menu', async ({ page }) => {
        await useScheme(page, 'modern');
        await page.goto(DASHBOARD);
        await page.mouse.move(700, 500);

        const badge = await page
            .locator('#wp-admin-bar-gratora-test-mode .gratora-test-mode-badge')
            .evaluate((el) => getComputedStyle(el).backgroundColor);

        expect((await drawn(link(page, 'gratora-addons'))).color).toBe(badge);
    });

    test('takes the colour of every other link under the pointer', async ({ page }) => {
        await page.goto(DASHBOARD);

        await link(page, 'gratora-settings').hover();
        const neighbour = await drawn(link(page, 'gratora-settings'));

        await link(page, 'gratora-addons').hover();

        await expect.poll(() => drawn(link(page, 'gratora-addons'))).toEqual(neighbour);
    });

    test('is drawn as the current page on its own screen', async ({ page }) => {
        await page.goto(DASHBOARD);
        const current = await drawn(link(page, 'gratora'));

        await page.goto(ADDONS);

        expect(await drawn(link(page, 'gratora-addons'))).toEqual(current);
    });
});
