import { test, expect, type Locator, type Page } from '@playwright/test';
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

const libraryItem = (page: Page, name: string): Locator => page.getByRole('option', { name, exact: true });
const canvasBlock = (page: Page, type: string): Locator => page.locator(`.gratora-form-editor__canvas [data-type="gratora/${type}"]`);
const library = (page: Page): Locator => page.locator('.gratora-form-editor__secondary--inserter');
const viewTab = (page: Page, name: string): Locator => page.getByRole('tab', { name, exact: true });

/** Core's inserter looks at where focus went on the frame after an insert. */
const afterInsert = (page: Page): Promise<void> =>
    page.evaluate(() => new Promise<void>((done) => requestAnimationFrame(() => requestAnimationFrame(() => done()))));

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

    test('adds a block without a console error', async ({ page }) => {
        const errors = await openEditor(page);
        const before = await canvasBlock(page, 'divider').count();

        await libraryItem(page, 'Divider').click();
        await expect(canvasBlock(page, 'divider')).toHaveCount(before + 1);
        await afterInsert(page);

        expect(errors).toEqual([]);
    });

    test('a block that can be added once takes focus and leaves the library in place', async ({ page }) => {
        await openEditor(page);
        const added = canvasBlock(page, 'terms');

        await libraryItem(page, 'Terms').click();
        await expect(added).toHaveCount(1);
        await expect.poll(() => added.evaluate((block) => block.contains(document.activeElement))).toBe(true);
        await afterInsert(page);

        await expect(libraryItem(page, 'Terms')).toBeInViewport();
    });

    test('on a narrow screen the library closes after adding a block', async ({ page }) => {
        await page.setViewportSize({ width: 700, height: 900 });
        const errors = await openEditor(page);
        // The settings panel lies over the library at this width.
        await page.getByRole('button', { name: 'Toggle side panel' }).click();

        await libraryItem(page, 'Divider').click();
        await expect(library(page)).toHaveCount(0);
        await afterInsert(page);

        expect(errors).toEqual([]);
    });

    test('the library is back after a trip through preview and settings', async ({ page }) => {
        await openEditor(page);

        await viewTab(page, 'Preview').click();
        await viewTab(page, 'Settings').click();
        await viewTab(page, 'Build').click();

        // The header button first: a library on its way out is still in the page for a moment.
        await expect(page.getByRole('button', { name: 'Close block inserter' })).toBeVisible();
        await expect(library(page)).toBeVisible();
    });

    test('a library closed on purpose stays closed after a trip to preview', async ({ page }) => {
        await openEditor(page);
        await page.getByRole('button', { name: 'Close block inserter' }).click();

        await viewTab(page, 'Preview').click();
        await viewTab(page, 'Build').click();

        await expect(page.getByRole('button', { name: 'Toggle block inserter' })).toBeVisible();
        await expect(library(page)).toHaveCount(0);
    });
});
