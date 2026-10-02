import { test, expect, type Page } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

// Only a real WordPress page loads core's editor scripts beside the bundle, and
// jest swaps the editor's skeleton for a stub, so these run in the browser.

const formId = process.env.GRATORA_E2E_FORM_ID ?? '';
const formRequest = new RegExp(`gratora/v1/admin/forms/${formId}([?&]|$)`);

/** Opens the canonical form in the editor and returns what its console reports from then on. */
async function openEditor(page: Page): Promise<string[]> {
    await new AdminPage(page).login();

    const errors: string[] = [];
    page.on('console', (msg) => {
        if (msg.type() === 'error') errors.push(msg.text());
    });
    page.on('pageerror', (err) => errors.push(err.message));

    await page.goto(`/wp-admin/admin.php?page=gratora-forms&form=${formId}`);
    await expect(page.locator('.gratora-form-editor__canvas [data-block]').first()).toBeVisible();
    await page.waitForLoadState('networkidle');

    return errors;
}

test.describe('form editor', () => {
    test.skip(! formId, 'set GRATORA_E2E_FORM_ID via `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`');

    test('opens without a console error', async ({ page }) => {
        const errors = await openEditor(page);

        expect(errors).toEqual([]);
    });

    test('a save the server refuses says why', async ({ page }) => {
        // Nothing is written: the save is answered here.
        await page.route(formRequest, (route) =>
            route.request().method() === 'GET'
                ? route.continue()
                : route.fulfill({ status: 500, json: { code: 'refused', message: 'Refused on purpose.', data: { status: 500 } } })
        );
        await openEditor(page);

        await page.locator('.gratora-editor-header__title').fill('A new title');
        await page.locator('.gratora-editor-header').getByRole('button', { name: 'Save', exact: true }).click();

        await expect(page.locator('.gratora-notice--error', { hasText: 'Refused on purpose.' })).toBeVisible();
    });

    test('a draft missing a required block says which', async ({ page }) => {
        await page.route(formRequest, async (route) => {
            if (route.request().method() !== 'GET') return route.abort();

            const response = await route.fetch();
            const form = await response.json();
            await route.fulfill({
                response,
                json: { ...form, status: 'draft', blocks: form.blocks.replace(/<!-- wp:gratora\/email[^>]*-->\s*/, '') },
            });
        });
        await openEditor(page);

        await expect(page.locator('.gratora-notice--warning', { hasText: 'Add these blocks before publishing: Email.' })).toBeVisible();
    });
});
