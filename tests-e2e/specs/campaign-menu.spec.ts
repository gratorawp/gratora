import { test, expect } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

// Only a browser lays the row of tabs out, and only wp-admin puts its own menu
// beside it, so this runs on a real page.
test('the campaign menu can be used when its button wraps to the start of a row', async ({ page }) => {
    await new AdminPage(page).login();
    await page.goto('/wp-admin/admin.php?page=gratora-campaigns');
    await page.locator('.gratora-row__link').first().click();

    const trigger = page.locator('.gratora-menu__trigger');
    const row = page.locator('.gratora-detail-nav');
    await expect(trigger).toBeVisible();

    const wrapped = async (): Promise<boolean> => {
        const button = (await trigger.boundingBox())!;
        const tabs = (await row.boundingBox())!;

        return button.y > tabs.y + 10 && button.x - tabs.x < 2;
    };

    // Add-on tabs fill the row on a laptop. A narrowing window does the same to any campaign.
    let width = 1280;
    while (! (await wrapped()) && width > 400) {
        width -= 10;
        await page.setViewportSize({ width, height: 720 });
    }
    expect(await wrapped(), 'the button wrapped to the start of a row').toBe(true);

    await trigger.click();

    const menu = page.locator('.gratora-menu__list');
    const drawn = (await menu.boundingBox())!;
    const tabs = (await row.boundingBox())!;
    expect(drawn.x, 'the menu opens inside the row').toBeGreaterThanOrEqual(tabs.x);
    expect(drawn.x + drawn.width, 'the menu ends inside the row').toBeLessThanOrEqual(tabs.x + tabs.width);

    // A trial click checks that nothing lies over the item.
    await menu.getByRole('menuitem').first().click({ trial: true, timeout: 5_000 });
});
