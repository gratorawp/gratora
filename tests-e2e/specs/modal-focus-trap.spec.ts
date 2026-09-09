import { test, expect } from '@playwright/test';

/**
 * A real browser, because this is about focus semantics: pressing Donate
 * disables the button, which blurs it to the body. The panel is aria-modal, so
 * from there Tab walks a page the screen reader is told is not there, and a
 * keyboard-only donor cannot reach the gateway's own Pay button on a donation
 * that already exists server-side.
 */
test.describe('modal form focus trap', () => {
    test('Tab stays inside the panel after focus falls to the body', async ({ page }) => {
        await page.goto(process.env.GRATORA_E2E_FORM_PATH ?? '/');

        const panel = page.locator('.gratora-donation-form');
        await expect(panel.first()).toBeVisible();

        // What the browser does when the focused control is disabled mid-flight.
        await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
        await expect.poll(() => page.evaluate(() => document.activeElement?.tagName)).toBe('BODY');

        await page.keyboard.press('Tab');

        const inside = await page.evaluate(() => {
            const form = document.querySelector('.gratora-donation-form');

            return !! form && form.contains(document.activeElement);
        });

        expect(inside).toBe(true);
    });
});
