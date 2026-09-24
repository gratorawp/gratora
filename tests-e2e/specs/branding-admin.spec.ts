/**
 * The admin screens that preview a brand show the ground the published page
 * paints and the ink measured on it: the Brand preview and the campaign rail
 * stand the form on the theme's white page, the Develop canvas draws each block
 * the way the runtime stylesheet does, and the token editors offer only the
 * controls that change something.
 *
 * Runs against the QA brand (a dark card under a pale accent), as an
 * administrator, and saves nothing: every write but the editor's own preview
 * and readiness checks is refused. Seed with
 * `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding`
 * and restore the brand afterwards with `--restore`.
 */

import { expect, test, type Frame, type Locator, type Page } from '@playwright/test';

import { AdminPage } from '../helpers/AdminPage';
import { contrast, decodePng, describeInk, expectRgb, inkOf, installInk, parseRgb, sweep, type Ink } from '../helpers/contrast';

const idOf = (name: string): string => process.env[`GRATORA_E2E_BRANDING_${name}_ID`] ?? '';
const pathOf = (name: string): string => process.env[`GRATORA_E2E_BRANDING_${name}_PATH`] ?? '';
const unseeded = (name: string): string =>
    `set GRATORA_E2E_BRANDING_${name} via \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding\``;

const storageState = process.env.GRATORA_E2E_ADMIN_STORAGE_STATE;

test.use({ storageState: storageState || undefined, viewport: { width: 1440, height: 900 } });
test.skip(! storageState && ! process.env.GRATORA_E2E_ADMIN_USER, 'set GRATORA_E2E_ADMIN_USER and GRATORA_E2E_ADMIN_PASS, or GRATORA_E2E_ADMIN_STORAGE_STATE');

const WHITE = 'rgb(255, 255, 255)';
const INK = 'rgb(17, 24, 39)';
const MUTED = 'rgb(107, 114, 128)';
const ACCENT = 'rgb(253, 230, 138)';
const ON_ACCENT = 'rgb(16, 22, 42)';
const SOFT = 'rgb(34, 31, 61)';
const TINT = 'rgb(49, 45, 54)';

const BRAND = '/wp-admin/admin.php?page=gratora-settings&tab=brand';
const campaignScreen = (tab: string): string =>
    `/wp-admin/admin.php?page=gratora-campaigns&view=detail&id=${idOf('CAMPAIGN')}&tab=${tab}`;
const formEditor = (): string => `/wp-admin/admin.php?page=gratora-forms&form=${idOf('CANVAS_FORM')}`;

test.beforeEach(async ({ page }) => {
    await page.addInitScript(installInk);

    // Nothing here is saved. The editor posts its blocks to render Preview and
    // to check readiness; neither writes.
    await page.route(/\/wp-json\/|rest_route=|admin-ajax\.php/, (route) => {
        const request = route.request();
        const method = request.method();
        if (method === 'GET' || method === 'HEAD' || method === 'OPTIONS') return route.continue();
        if (/admin\/forms\/(preview|\d+\/readiness)/.test(request.url())) return route.continue();
        if (/admin-ajax\.php/.test(request.url()) && /action=heartbeat/.test(request.postData() ?? '')) return route.continue();
        return route.abort();
    });

    if (! storageState) {
        await new AdminPage(page).login();
    }
});

async function open(page: Page, url: string, ready: string): Promise<void> {
    await page.goto(url);
    await page.locator(ready).first().waitFor();
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
}

const style = (locator: Locator, property: string, pseudo?: string): Promise<string> =>
    locator.evaluate((el, [prop, pe]) => getComputedStyle(el, pe || null).getPropertyValue(prop), [property, pseudo ?? ''] as const);

function expectInk(ink: Ink, color: string, ground: string, what: string): void {
    expectRgb(ink.color, color, `${what} ink (${describeInk(ink)})`);
    expectRgb(ink.ground, ground, `${what} ground (${describeInk(ink)})`);
}

/** The lowest contrast of an ink against every pixel painted under an element's text. */
async function worstUnder(page: Page, locator: Locator, ink: string): Promise<number> {
    await locator.evaluate((el) => { (el as HTMLElement).style.setProperty('color', 'transparent', 'important'); });
    const png = decodePng(await locator.screenshot());
    await locator.evaluate((el) => { (el as HTMLElement).style.removeProperty('color'); });

    const want = parseRgb(ink);
    let worst = Infinity;
    for (let y = 0; y < png.height; y++) {
        for (let x = 0; x < png.width; x++) {
            worst = Math.min(worst, contrast(want, png.pixel(x, y)));
        }
    }
    return worst;
}

const row = (page: Page, label: string): Locator =>
    page.locator('.gratora-token-editor__row').filter({
        has: page.locator('.gratora-token-editor__label', { hasText: new RegExp(`^${label}$`) }),
    });

async function openGroup(page: Page, title: string): Promise<void> {
    const toggle = page.locator('.gratora-token-editor .components-panel__body-toggle', { hasText: title }).first();
    if ((await toggle.getAttribute('aria-expanded')) !== 'true') await toggle.click();
}

async function setColour(page: Page, label: string, hex: string): Promise<void> {
    await row(page, label).locator('button.gratora-color').click();
    const input = page.locator('.gratora-color-picker-popover input').first();
    await input.fill(hex);
    await input.press('Enter');
    await page.keyboard.press('Escape');
    await expect(page.locator('.gratora-color-picker-popover')).toHaveCount(0);
}

async function pickPreset(page: Page, name: string): Promise<void> {
    await page.locator('.gratora-preset-mgr__row').filter({ hasText: name }).first().click();
    await expect(page.locator('.gratora-preset-editor__name')).toHaveValue(new RegExp(name));
}

test.describe('Brand preview', () => {
    test.beforeEach(async ({ page }) => {
        await open(page, BRAND, '.gratora-style-preview__frame');
    });

    test('stands the form on the white page in page ink', async ({ page }) => {
        const sheet = page.locator('.gratora-style-preview__page');
        expectRgb(parseRgb(await style(sheet, 'background-color')), WHITE, 'preview page ground');
        expectRgb(parseRgb(await style(sheet, 'color')), INK, 'preview page ink');

        expectInk(await inkOf(page.locator('.gratora-style-preview__field-label').first()), MUTED, WHITE, 'field label');
    });

    test('draws the hero as the accent cover under ink measured on it', async ({ page }) => {
        for (const preset of ['QA Dark Pale', 'Site theme']) {
            await pickPreset(page, preset);
            const hero = page.locator('.gratora-style-preview__hero');
            await expect(hero).toHaveClass(/\bis-accent\b/);
            const accent = (await page.locator('.gratora-style-preview__frame').evaluate((el) => getComputedStyle(el).getPropertyValue('--gratora-accent'))).trim();
            const painted = await style(hero, 'background-color');
            expect(await hero.evaluate((el, a) => {
                const probe = document.createElement('i');
                probe.style.color = a;
                el.appendChild(probe);
                const c = getComputedStyle(probe).color;
                probe.remove();
                return c;
            }, accent), `${preset} hero ground`).toBe(painted);
            expect(await style(hero, 'content', '::after'), `${preset} hero scrim`).toMatch(/^(none|normal)$/);

            const title = page.locator('.gratora-style-preview__hero-title');
            expectRgb(parseRgb(await style(title, 'color')), ON_ACCENT, `${preset} hero title`);
            expect(await worstUnder(page, title, ON_ACCENT), `${preset} hero title on every pixel`).toBeGreaterThanOrEqual(14.405);
        }
    });

    test('draws the selected tile in the accent measured on its tint', async ({ page }) => {
        const pairs: Array<[string, string, string]> = [
            ['QA Dark Pale', ACCENT, TINT],
            ['Classic', WHITE, 'rgb(121, 64, 87)'],
            ['Site theme', ON_ACCENT, 'rgb(255, 253, 235)'],
        ];
        for (const [preset, ink, ground] of pairs) {
            await pickPreset(page, preset);
            expectInk(await inkOf(page.locator('.gratora-style-preview__amount.is-sel')), ink, ground, `${preset} selected tile`);
        }
    });

    test('draws the tiles as the published tiles: no border, an inset outline when selected', async ({ page }) => {
        await pickPreset(page, 'QA Dark Pale');
        await openGroup(page, 'Radius');
        for (const stroke of ['1px', '2px']) {
            await row(page, 'Border width').locator('select').selectOption(stroke);
            for (const tile of await page.locator('.gratora-style-preview__amount').all()) {
                expect(await style(tile, 'border-top-width'), `tile border at stroke ${stroke}`).toBe('0px');
            }
        }
        const selected = page.locator('.gratora-style-preview__amount.is-sel');
        expect(await style(selected, 'outline-width')).toBe('2px');
        expect(await style(selected, 'outline-style')).toBe('solid');
        expectRgb(parseRgb(await style(selected, 'outline-color')), ACCENT, 'selected tile outline');
        expect(await style(selected, 'outline-offset')).toBe('-2px');
    });

    test('sizes the field text from Base font size', async ({ page }) => {
        await pickPreset(page, 'QA Dark Pale');
        await openGroup(page, 'Typography');
        for (const [size, px] of [['14px', '11.2px'], ['15px', '12px'], ['16px', '12.8px']]) {
            await row(page, 'Base font size').locator('select').selectOption(size);
            for (const box of await page.locator('.gratora-style-preview__field-box').all()) {
                expect(await style(box, 'font-size'), `field box at ${size}`).toBe(px);
            }
        }
    });

    test('reads every text run on Bold, whose card is a mid red', async ({ page }) => {
        await pickPreset(page, 'Bold');
        const { count, failures } = await sweep(page, '.gratora-style-preview__page');
        expect(count).toBeGreaterThan(0);
        expect(failures.map(describeInk)).toEqual([]);
    });

    test('offers clear only on a colour the preset changed', async ({ page }) => {
        await pickPreset(page, 'Quiet');
        await openGroup(page, 'Buttons');
        await expect(row(page, 'Button background').locator('button.gratora-color')).toBeVisible();
        await expect(page.locator('button[aria-label^="Clear Button"]')).toHaveCount(0);

        await pickPreset(page, 'Bold');
        await expect(row(page, 'Accent').locator('.gratora-color__hex')).toHaveText(/^#0F3D5C$/i);
        await expect(page.locator('button[aria-label="Clear Accent"]')).toHaveCount(0);
        await expect(page.locator('.gratora-save-bar')).toHaveCount(0);

        await pickPreset(page, 'QA Dark Pale');
        await openGroup(page, 'Surface');
        const colourRows = page.locator('.gratora-token-editor__row').filter({ has: page.locator('button.gratora-color') });
        const clears = await colourRows.locator('.gratora-color__clear').count();
        const resets = await colourRows.filter({ has: page.locator('.gratora-color__clear') }).locator('.gratora-token-editor__reset').count();
        expect(clears).toBe(4);
        expect(resets).toBe(clears);

        await page.getByRole('button', { name: 'Clear Accent', exact: true }).click();
        const accent = row(page, 'Accent');
        await expect(accent.locator('.gratora-color__hex')).toHaveText(/^#211D3F$/i);
        await expect(accent.locator('.gratora-color__clear')).toHaveCount(0);
        await expect(accent.locator('.gratora-token-editor__reset')).toHaveCount(0);
    });

    test('says what an empty button text colour does', async ({ page }) => {
        await openGroup(page, 'Buttons');
        await expect(row(page, 'Button text color').locator('.gratora-token-editor__help'))
            .toHaveText('Leave empty to use white or dark text, whichever reads on the accent color.');
    });
});

test.describe('campaign Appearance', () => {
    test.skip(! idOf('CAMPAIGN'), unseeded('CAMPAIGN_ID'));

    test.beforeEach(async ({ page }) => {
        await open(page, `${campaignScreen('settings')}#appearance`, '.gratora-settings-layout__rail .gratora-style-preview__frame');
    });

    const rail = (page: Page): Locator => page.locator('.gratora-settings-layout__rail');

    test('the rail stands the form on the white page, under an accent hero in measured ink', async ({ page }) => {
        const sheet = rail(page).locator('.gratora-style-preview__page');
        expectRgb(parseRgb(await style(sheet, 'background-color')), WHITE, 'rail page ground');
        expectRgb(parseRgb(await style(sheet, 'color')), INK, 'rail page ink');

        const hero = rail(page).locator('.gratora-style-preview__hero');
        await expect(hero).toHaveClass(/\bis-accent\b/);
        const title = hero.locator('.gratora-style-preview__hero-title');
        expectInk(await inkOf(title), ON_ACCENT, ACCENT, 'rail hero title');
        expect(await worstUnder(page, title, ON_ACCENT)).toBeGreaterThanOrEqual(14.405);

        const selected = rail(page).locator('.gratora-style-preview__amount.is-sel');
        expect(await style(selected, 'outline-width')).toBe('2px');
        expectRgb(parseRgb(await style(selected, 'outline-color')), ACCENT, 'rail selected outline');
        expect(await style(selected, 'outline-offset')).toBe('-2px');
    });

    test('the rail draws tiles without a border at any stroke', async ({ page }) => {
        await customize(page);
        await openGroup(page, 'Radius');
        for (const stroke of ['1px', '2px']) {
            await row(page, 'Border width').locator('select').selectOption(stroke);
            for (const tile of await rail(page).locator('.gratora-style-preview__amount').all()) {
                expect(await style(tile, 'border-top-width'), `rail tile at stroke ${stroke}`).toBe('0px');
            }
        }
    });

    test('offers clear only on a colour the campaign overrides', async ({ page }) => {
        await customize(page);
        await expect(row(page, 'Accent').locator('.gratora-color__hex')).toHaveText(/^#FDE68A$/i);
        await expect(page.locator('.gratora-color__clear')).toHaveCount(0);

        await setColour(page, 'Accent', 'ff0000');
        await expect(page.locator('.gratora-color__clear')).toHaveCount(1);

        await page.getByRole('button', { name: 'Clear Accent', exact: true }).click();
        await expect(row(page, 'Accent').locator('.gratora-color__hex')).toHaveText(/^#FDE68A$/i);
        await expect(page.locator('.gratora-color__clear')).toHaveCount(0);
    });

    test('names a background no text reads on', async ({ page }) => {
        await customize(page);
        await openGroup(page, 'Surface');
        await setColour(page, 'Background', '777777');

        await expect(page.locator('.gratora-custom-style-body')).toContainText('Background (#777777) reaches 4.0:1, under the 4.5:1 that text needs.');
    });

    test('the switches are reached and worked from the keyboard', async ({ page }) => {
        const toggle = page.locator('.gratora-custom-style-toggle input[role="switch"]');
        await page.locator('.gratora-custom-style-toggle').locator('xpath=preceding::select[1]').focus();
        await page.keyboard.press('Tab');
        await expect(toggle).toBeFocused();

        const track = page.locator('.gratora-custom-style-toggle .gratora-switch__track');
        expect(parseFloat(await style(track, 'outline-width'))).toBeGreaterThanOrEqual(2);
        expect(await style(track, 'outline-style')).not.toBe('none');

        await page.keyboard.press('Space');
        await expect(toggle).toBeChecked();
        await setColour(page, 'Accent', 'ff0000');

        for (const close of ['Escape', 'Cancel']) {
            await toggle.focus();
            await page.keyboard.press('Space');
            const dialog = page.getByRole('dialog', { name: 'Discard token overrides' });
            await expect(dialog).toBeVisible();
            if (close === 'Escape') await page.keyboard.press('Escape');
            else await dialog.getByRole('button', { name: 'Cancel' }).click();
            await expect(dialog).toHaveCount(0);
            await expect(toggle, `focus after ${close}`).toBeFocused();
            await expect(toggle).toBeChecked();
        }

        for (const title of ['Hide theme header', 'Hide theme footer']) {
            const input = page.locator('.gratora-switch', { has: page.locator(`input[aria-label="${title}"]`) }).locator('input');
            await input.focus();
            await expect(input).toBeFocused();
            const before = await input.isChecked();
            await page.keyboard.press('Space');
            await expect(input).toBeChecked({ checked: ! before });
        }
    });

    test('a pointer anywhere on a switch works it', async ({ page }) => {
        for (const name of ['Customize tokens for this campaign', 'Hide theme header', 'Hide theme footer']) {
            const control = page.getByRole('switch', { name });
            const track = page.locator('.gratora-switch', { has: control }).locator('.gratora-switch__track');
            const box = await track.boundingBox();
            expect(box, name).not.toBeNull();
            const before = await control.isChecked();

            await control.click({ position: { x: 3, y: box!.height / 2 } });
            await expect(control, `${name} from its start`).toBeChecked({ checked: ! before });
            await control.click({ position: { x: box!.width - 3, y: box!.height / 2 } });
            await expect(control, `${name} from its end`).toBeChecked({ checked: before });
        }
    });
});

async function customize(page: Page): Promise<void> {
    const toggle = page.locator('.gratora-custom-style-toggle input[role="switch"]');
    if (! (await toggle.isChecked())) await page.locator('.gratora-custom-style-toggle label.gratora-switch').click();
    await page.locator('.gratora-custom-style-body .gratora-token-editor').waitFor();
}

test.describe('the Develop canvas and Preview', () => {
    test.skip(! idOf('CANVAS_FORM'), unseeded('CANVAS_FORM_ID'));

    test.beforeEach(async ({ page }) => {
        await open(page, formEditor(), '.gratora-form-editor__sheet [data-type="gratora/submit-button"]');
    });

    const block = (page: Page, type: string): Locator => page.locator(`.gratora-form-editor__sheet [data-type="gratora/${type}"]`).first();
    const holding = (scope: Locator, text: string): Locator =>
        scope.locator('span, div').filter({ hasText: new RegExp(`^${text}$`) }).last();

    async function select(page: Page, type: string): Promise<void> {
        const target = block(page, type);
        await target.scrollIntoViewIfNeeded();
        const box = await target.boundingBox();
        if (! box) throw new Error(`${type} has no box`);
        await page.mouse.click(box.x + box.width - 6, box.y + box.height - 4);
    }

    async function styleOption(page: Page, type: string, option: string): Promise<void> {
        await select(page, type);
        await page.locator('.gratora-segmented[aria-label="Style"] button', { hasText: option }).first().click();
    }

    async function preview(page: Page): Promise<Frame> {
        await page.locator('.gratora-editor-header button', { hasText: 'Preview' }).first().click();
        const handle = await page.waitForSelector('iframe.gratora-form-editor__preview-frame');
        await expect.poll(async () => (await handle.contentFrame())?.locator('form.gratora-donation-form[data-gratora-ready]').count() ?? 0, { timeout: 20_000 }).toBeGreaterThan(0);
        const frame = await handle.contentFrame();
        if (! frame) throw new Error('Preview has no document');
        await frame.addScriptTag({ content: `(${installInk.toString()})()` });
        return frame;
    }

    test('the canvas sheet is the white page, in page ink', async ({ page }) => {
        const legend = block(page, 'recurring-toggle').locator('.gratora-block-preview__title');
        expectInk(await inkOf(legend), INK, WHITE, 'canvas legend');

        const frame = await preview(page);
        expectInk(await inkOf(frame.locator('.gratora-form__frequency legend')), INK, WHITE, 'preview legend');
        expectInk(await inkOf(frame.locator('.gratora-heading, .gratora-form h2').first()), INK, WHITE, 'preview heading');
        expectInk(await inkOf(frame.locator('span.gratora-form__label').first()), MUTED, WHITE, 'preview label');
    });

    test('recurring pills sit in one soft track, as in Preview', async ({ page }) => {
        const toggle = block(page, 'recurring-toggle');
        const option = holding(toggle, 'Monthly');
        const track = option.locator('xpath=..');

        expectRgb(parseRgb(await style(track, 'background-color')), SOFT, 'canvas pill track');
        expect(await style(track, 'padding-top')).toBe('4px');
        expect(await style(track, 'border-top-left-radius')).toBe('6px');
        expect(await style(option, 'background-color')).toBe('rgba(0, 0, 0, 0)');
        expectInk(await inkOf(option), WHITE, SOFT, 'canvas unselected pill');
        expect(await style(option, 'font-size')).toBe('13px');
        expect(await style(option, 'padding')).toBe('8px 14px');

        const frame = await preview(page);
        const live = frame.locator('.gratora-form__frequency-option:not(.is-selected)').first();
        expectInk(await inkOf(live), WHITE, SOFT, 'preview unselected pill');
        expect(await style(live, 'font-size')).toBe('13px');
        expect(await style(live, 'padding')).toBe('8px 14px');
        expect(await style(frame.locator('.gratora-form__frequency-options'), 'padding-top')).toBe('4px');
    });

    test('recurring tabs draw the selected tab in ink that reads on the page', async ({ page }) => {
        await styleOption(page, 'recurring-toggle', 'Tabs');
        const toggle = block(page, 'recurring-toggle');
        const check = async (selected: Locator, unselected: Locator, where: string): Promise<void> => {
            expectInk(await inkOf(selected), INK, WHITE, `${where} selected tab`);
            expectRgb(parseRgb(await style(selected, 'border-bottom-color')), INK, `${where} selected underline`);
            expect(await style(selected, 'border-bottom-width')).toBe('2px');
            expectInk(await inkOf(unselected), MUTED, WHITE, `${where} unselected tab`);
            expect(await style(unselected, 'font-size')).toBe('13px');
            expect(await style(unselected, 'font-weight')).toBe('500');
        };
        await check(holding(toggle, 'One-time'), holding(toggle, 'Monthly'), 'canvas');

        const frame = await preview(page);
        await check(
            frame.locator('.gratora-form__frequency-option.is-selected'),
            frame.locator('.gratora-form__frequency-option:not(.is-selected)').first(),
            'preview',
        );
    });

    test('unselected currency pills read in the soft muted ink', async ({ page }) => {
        const switcher = block(page, 'currency-switcher');
        await expect(holding(switcher, 'EUR')).toBeVisible();
        for (const code of ['EUR', 'GBP', 'CHF']) {
            expectRgb(parseRgb(await style(holding(switcher, code), 'color')), 'rgba(255, 255, 255, 0.72)', `canvas ${code}`);
        }
    });

    test('the currency dropdown is drawn as a field', async ({ page }) => {
        await styleOption(page, 'currency-switcher', 'Dropdown');
        const chip = block(page, 'currency-switcher').locator('span', { hasText: '▾' }).first();
        expectInk(await inkOf(chip), ON_ACCENT, WHITE, 'canvas dropdown');
        expect(await style(chip, 'border-top-width')).toBe('1px');
        expectRgb(parseRgb(await style(chip, 'border-top-color')), 'rgb(58, 54, 96)', 'canvas dropdown border');
        expect(await style(chip, 'border-top-left-radius')).toBe('6px');
    });

    test('the donate button reads the button tokens', async ({ page }) => {
        const button = block(page, 'submit-button').locator('[contenteditable]').first();
        expect(await style(button, 'font-weight')).toBe('600');
        expect(await style(button, 'box-shadow')).toBe('rgba(0, 0, 0, 0.08) 0px 1px 2px 0px');
        expect((await button.boundingBox())?.height).toBe(48);
        expect(await style(button, 'padding')).toBe('0px 22px');
    });

    test('amount tiles paint the soft ground, and the selected one its tint', async ({ page }) => {
        const amount = block(page, 'donation-amount');
        const resting = holding(amount, 'A week of meals').locator('xpath=..');
        const selected = holding(amount, 'A month of meals').locator('xpath=..');

        expectRgb(parseRgb(await style(resting, 'background-color')), SOFT, 'canvas tile');
        expectRgb(parseRgb(await style(resting, 'color')), WHITE, 'canvas tile ink');
        expectRgb(parseRgb(await style(selected, 'background-color')), TINT, 'canvas selected tile');
        expectRgb(parseRgb(await style(selected, 'color')), ACCENT, 'canvas selected tile ink');
        expect(await style(selected, 'outline-width')).toBe('2px');
        expectRgb(parseRgb(await style(selected, 'outline-color')), ACCENT, 'canvas selected outline');
        expect(await style(selected, 'outline-offset')).toBe('-2px');
    });
});

test.describe('template picker', () => {
    test.skip(! idOf('CAMPAIGN'), unseeded('CAMPAIGN_ID'));

    async function openPicker(page: Page): Promise<void> {
        await open(page, campaignScreen('forms'), '.gratora-campaign-detail__body');
        await page.getByRole('button', { name: /add new form/i }).first().click();
        await page.locator('.gratora-template-picker__filters [role="tab"]').first().waitFor();
    }

    test('draws a square preset square', async ({ page }) => {
        await page.route(/admin\/forms\/templates/, async (route) => {
            const response = await route.fetch();
            const list = await response.json();
            list.push({
                id: 'e2e-quiet',
                name: 'Quiet fixture',
                description: '',
                category: 'Starter',
                blocks: '<!-- wp:gratora/donation-amount {"presets":[1000,2500]} /--><!-- wp:gratora/name /--><!-- wp:gratora/submit-button /-->',
                settings: { layout: 'inline', style: { preset_id: 'quiet' } },
            });
            await route.fulfill({ response, json: list });
        });
        await openPicker(page);

        const thumb = page.locator('.gratora-template-picker__card', { hasText: 'Quiet fixture' }).locator('.gratora-template-thumb');
        expect((await style(thumb, '--thumb-radius')).trim()).toBe('0px');
        expect(await style(thumb.locator('.gratora-template-thumb__tiles i').first(), 'border-top-left-radius')).toBe('0px');
    });

    test('translates the Campaign tab and puts it last', async ({ page }) => {
        await page.route(/wp-includes\/js\/dist\/i18n(\.min)?\.js/, async (route) => {
            const response = await route.fetch();
            const data = { '': { domain: 'gratora-donation-platform' }, Campaign: ['Kampanja'] };
            await route.fulfill({
                response,
                body: `${await response.text()}\n;wp.i18n.setLocaleData(${JSON.stringify(data)}, 'gratora-donation-platform');`,
            });
        });
        await openPicker(page);

        await expect(page.locator('.gratora-template-picker__filters [role="tab"]'))
            .toHaveText(['All', 'Blank', 'Starter', 'Standard', 'Recurring', 'Wizard', 'Kampanja']);
    });

    test('draws the Blank card icon in the muted tone', async ({ page }) => {
        await openPicker(page);

        expect(await style(page.locator('.gratora-template-thumb--blank svg'), 'color')).toBe('rgb(92, 88, 120)');
    });
});

test.describe('previews agree with the published page', () => {
    test.skip(! idOf('CAMPAIGN') || ! pathOf('CAMPAIGN'), unseeded('CAMPAIGN_ID and _PATH'));

    test('both previews paint the ground the live label stands on, at the same contrast', async ({ page }) => {
        await page.goto(pathOf('CAMPAIGN'));
        const live = await inkOf(page.locator('span.gratora-form__label').first());
        expectRgb(live.ground, WHITE, 'live label ground');

        for (const [url, scope] of [[BRAND, 'body'], [`${campaignScreen('settings')}#appearance`, '.gratora-settings-layout__rail']] as const) {
            await open(page, url, `${scope} .gratora-style-preview__frame`);
            const preview = page.locator(`${scope} .gratora-style-preview`);
            expectRgb(parseRgb(await style(preview.locator('.gratora-style-preview__page'), 'background-color')), WHITE, `${url} page`);
            const label = await inkOf(preview.locator('.gratora-style-preview__field-label').first());
            expect(Math.abs(label.ratio - live.ratio), `${url} label ${describeInk(label)} against live ${describeInk(live)}`).toBeLessThanOrEqual(0.05);
        }
    });
});
