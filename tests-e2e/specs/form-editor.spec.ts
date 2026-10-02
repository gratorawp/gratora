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
const settingsPanel = (page: Page): Locator => page.locator('.interface-interface-skeleton__sidebar');
const viewTab = (page: Page, name: string): Locator => page.getByRole('tab', { name, exact: true });
const headerButton = (page: Page, text: string): Locator => page.locator('.gratora-editor-header__right button', { hasText: new RegExp(`^${text}$`) });

/** Serves the canonical form as a draft without its Email block. Nothing is written. */
async function draftWithoutEmail(page: Page): Promise<void> {
    await page.route(formRequest, async (route) => {
        if (route.request().method() !== 'GET') return route.abort();

        const response = await route.fetch();
        const form = await response.json();
        await route.fulfill({
            response,
            json: { ...form, status: 'draft', blocks: form.blocks.replace(/<!-- wp:gratora\/email[^>]*-->\s*/, '') },
        });
    });
}

/** Takes the Email block off the canvas the way an author does. */
async function removeEmail(page: Page): Promise<void> {
    await canvasBlock(page, 'email').click({ position: { x: 4, y: 4 } });
    await page.keyboard.press('Escape');
    await page.keyboard.press('Delete');
    await expect(canvasBlock(page, 'email')).toHaveCount(0);
}

const headerControls = '.gratora-editor-header :is(a, button, input)';
const quickInserterBlocks = '.block-editor-inserter__quick-inserter [role="option"]';

/** Those of the controls that something else is drawn over, or that run off the screen. */
const unreachable = (page: Page, controls: string): Promise<string[]> =>
    page.evaluate((selector) =>
        [...document.querySelectorAll<HTMLElement>(selector)]
            .filter((control) => control.getBoundingClientRect().width > 0)
            .filter((control) => {
                const box = control.getBoundingClientRect();
                const onTop = document.elementFromPoint(box.left + box.width / 2, box.top + box.height / 2);
                return ! onTop || ! control.contains(onTop) || box.left < 0 || box.right > window.innerWidth;
            })
            .map((control) => (control.innerText || control.getAttribute('aria-label') || control.getAttribute('placeholder') || control.tagName).trim()),
        controls
    );

/** Lets the page act on what was just done. Core's inserter looks at where focus went on the frame after an insert. */
const nextFrames = (page: Page): Promise<void> =>
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
        await draftWithoutEmail(page);
        await openEditor(page);

        await expect(page.locator('.gratora-notice--warning', { hasText: 'Add these blocks before publishing: Email.' })).toBeVisible();
    });

    test('a draft that cannot be published says why on the button', async ({ page }) => {
        await draftWithoutEmail(page);
        await openEditor(page);
        const publish = headerButton(page, 'Publish');
        const ground = (): Promise<string> => publish.evaluate((button) => getComputedStyle(button).backgroundColor);
        const idle = await ground();

        await publish.hover();

        await expect(page.getByRole('tooltip')).toHaveText('Add these blocks first: Email.');
        // It can be pointed at now, and must not answer as if it could be pressed.
        expect(await ground()).toBe(idle);
    });

    test('a live form missing a required block cannot be saved', async ({ page }) => {
        let saves = 0;
        await page.route(formRequest, (route) => {
            if (route.request().method() === 'GET') return route.continue();
            saves++;
            return route.abort();
        });
        await openEditor(page);
        await removeEmail(page);

        await expect(page.locator('.gratora-notice--error', { hasText: 'cannot be saved without these blocks: Email' })).toBeVisible();
        await expect(headerButton(page, 'Save')).toBeDisabled();

        await page.keyboard.press('ControlOrMeta+s');
        await nextFrames(page);

        expect(saves).toBe(0);
    });

    test('a live form that cannot be saved says why on the button', async ({ page }) => {
        await page.route(formRequest, (route) => (route.request().method() === 'GET' ? route.continue() : route.abort()));
        await openEditor(page);
        await removeEmail(page);

        await headerButton(page, 'Save').hover();

        await expect(page.getByRole('tooltip')).toHaveText('Add these blocks first: Email.');
    });

    test('adds a block without a console error', async ({ page }) => {
        const errors = await openEditor(page);
        const before = await canvasBlock(page, 'divider').count();

        await libraryItem(page, 'Divider').click();
        await expect(canvasBlock(page, 'divider')).toHaveCount(before + 1);
        await nextFrames(page);

        expect(errors).toEqual([]);
    });

    test('a block that can be added once takes focus and leaves the library in place', async ({ page }) => {
        await openEditor(page);
        const added = canvasBlock(page, 'terms');

        await libraryItem(page, 'Terms').click();
        await expect(added).toHaveCount(1);
        await expect.poll(() => added.evaluate((block) => block.contains(document.activeElement))).toBe(true);
        await nextFrames(page);

        await expect(libraryItem(page, 'Terms')).toBeInViewport();
    });

    test('the add button at the end of a long form offers its blocks within reach', async ({ page }) => {
        await openEditor(page);
        await page.locator('.gratora-form-editor__canvas').evaluate((canvas) => canvas.scrollTo(0, canvas.scrollHeight));

        await page.locator('.gratora-form-editor__canvas .block-list-appender button').click();

        await expect(page.locator(quickInserterBlocks).first()).toBeVisible();
        await expect.poll(() => unreachable(page, quickInserterBlocks)).toEqual([]);
    });

    // Below 782px WordPress lays the side panels over the content instead of beside it.
    test.describe('on a narrow screen', () => {
        test.use({ viewport: { width: 700, height: 900 } });

        test('nothing lies over the form until a panel is asked for', async ({ page }) => {
            await openEditor(page);
            await expect(library(page)).toHaveCount(0);
            await expect(settingsPanel(page)).toHaveCount(0);

            await canvasBlock(page, 'name').click({ position: { x: 4, y: 4 } });
            await expect(canvasBlock(page, 'name')).toHaveClass(/is-selected/);
            await nextFrames(page);

            await expect(settingsPanel(page)).toHaveCount(0);
        });

        test('one panel is open at a time, across the whole width', async ({ page }) => {
            await openEditor(page);

            await page.getByRole('button', { name: 'Toggle block inserter' }).click();
            await expect(library(page)).toBeVisible();

            await page.getByRole('button', { name: 'Toggle side panel' }).click();
            await expect(library(page)).toHaveCount(0);
            await expect.poll(async () => (await settingsPanel(page).boundingBox())?.width).toBe(700);

            await page.getByRole('button', { name: 'Toggle block inserter' }).click();
            await expect(settingsPanel(page)).toHaveCount(0);
            await expect.poll(async () => (await library(page).boundingBox())?.width).toBe(700);
        });

        test('the panel button shows the settings of the block just picked', async ({ page }) => {
            await openEditor(page);
            await canvasBlock(page, 'name').click({ position: { x: 4, y: 4 } });

            await page.getByRole('button', { name: 'Toggle side panel' }).click();

            await expect(settingsPanel(page).locator('.block-editor-block-inspector')).toBeVisible();
        });

        test('changing the view puts the panel away', async ({ page }) => {
            await openEditor(page);
            await page.getByRole('button', { name: 'Toggle side panel' }).click();
            await expect(settingsPanel(page)).toBeVisible();

            await viewTab(page, 'Preview').click();

            await expect(settingsPanel(page)).toHaveCount(0);
        });

        test('the library closes after adding a block', async ({ page }) => {
            const errors = await openEditor(page);
            await page.getByRole('button', { name: 'Toggle block inserter' }).click();

            await libraryItem(page, 'Divider').click();
            await expect(library(page)).toHaveCount(0);
            await nextFrames(page);

            expect(errors).toEqual([]);
        });
    });

    test('every header control can be reached, whatever the width', async ({ page }) => {
        await openEditor(page);
        const found: Record<number, string[]> = {};

        for (const width of [1280, 1024, 782, 600, 375]) {
            await page.setViewportSize({ width, height: 900 });
            await nextFrames(page);
            const controls = await unreachable(page, headerControls);
            if (controls.length > 0) found[width] = controls;
        }

        expect(found).toEqual({});
    });

    test('on a phone the views are still named for a screen reader', async ({ page }) => {
        await page.setViewportSize({ width: 375, height: 800 });
        await openEditor(page);

        await viewTab(page, 'Settings').click();

        await expect(viewTab(page, 'Settings')).toHaveAttribute('aria-selected', 'true');
    });

    test('a window made narrow puts its panels away', async ({ page }) => {
        await openEditor(page);
        await expect(library(page)).toBeVisible();
        await expect(settingsPanel(page)).toBeVisible();

        await page.setViewportSize({ width: 700, height: 900 });

        await expect(library(page)).toHaveCount(0);
        await expect(settingsPanel(page)).toHaveCount(0);
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
