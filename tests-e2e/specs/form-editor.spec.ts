import { test, expect } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

// Only a real WordPress page loads core's editor scripts beside the bundle, so
// a store this plugin registers a second time shows here and nowhere in jest.

const formId = process.env.GRATORA_E2E_FORM_ID ?? '';

test.describe('form editor', () => {
    test.skip(! formId, 'set GRATORA_E2E_FORM_ID via `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`');

    test('opens without a console error', async ({ page }) => {
        await new AdminPage(page).login();

        const errors: string[] = [];
        page.on('console', (msg) => {
            if (msg.type() === 'error') errors.push(msg.text());
        });
        page.on('pageerror', (err) => errors.push(err.message));

        await page.goto(`/wp-admin/admin.php?page=gratora-forms&form=${formId}`);
        await expect(page.locator('.gratora-form-editor__canvas [data-block]').first()).toBeVisible();
        await page.waitForLoadState('networkidle');

        expect(errors).toEqual([]);
    });
});
