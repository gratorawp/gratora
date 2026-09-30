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
        const path = process.env.GRATORA_E2E_MODAL_FORM_PATH;
        test.skip(! path, 'set GRATORA_E2E_MODAL_FORM_PATH via `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`');

        await page.goto(path!);
        await page.locator('.gratora-modal-trigger').first().click();

        const panel = page.locator('.gratora-modal__panel');
        await expect(panel).toBeVisible();

        // Donate is the panel's last control, and the browser resumes Tab from the control that lost focus.
        // Blurred anywhere earlier, the next Tab lands inside the panel whether or not anything traps it.
        await panel.evaluate((el) => {
            const controls = el.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled]):not([type="hidden"]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])');
            controls[controls.length - 1]?.focus();
        });

        // What the browser does when the focused control is disabled mid-flight.
        await page.evaluate(() => (document.activeElement as HTMLElement | null)?.blur());
        await expect.poll(() => page.evaluate(() => document.activeElement?.tagName)).toBe('BODY');

        await page.keyboard.press('Tab');

        expect(await panel.evaluate((el) => el.contains(document.activeElement))).toBe(true);
    });
});
