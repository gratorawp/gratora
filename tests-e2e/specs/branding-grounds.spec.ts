/**
 * Text reads on the ground actually painted behind it. The page ink is the
 * page's, a surface that paints the card, the soft ground, a field or the
 * accent restates its ink from that ground, and the accent drawn as text is
 * measured on the ground it sits on.
 *
 * Runs against the QA brand: a dark card (#15142b) under a pale accent
 * (#fde68a) on the theme's white page, where ink chosen for one ground and
 * drawn on another reads at 1:1. Seed with
 * `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding`
 * and restore the brand afterwards with `--restore`.
 */

import { expect, test, type Locator, type Page } from '@playwright/test';

import { AdminPage } from '../helpers/AdminPage';
import {
    VIEWPORTS,
    contrast,
    decodePng,
    describeInk,
    describeRing,
    expectReadable,
    expectRgb,
    inkOf,
    inksOf,
    installInk,
    parseRgb,
    ringPixels,
    sweep,
    waitForForms,
    type Ink,
} from '../helpers/contrast';

const path = (name: string): string => process.env[`GRATORA_E2E_BRANDING_${name}_PATH`] ?? '';
const unseeded = (name: string): string =>
    `set GRATORA_E2E_BRANDING_${name}_PATH via \`wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding\``;

const WHITE = 'rgb(255, 255, 255)';
const INK = 'rgb(17, 24, 39)';
const MUTED = 'rgb(107, 114, 128)';
const CARD = 'rgb(21, 20, 43)';
const ACCENT = 'rgb(253, 230, 138)';
const ON_ACCENT = 'rgb(16, 22, 42)';
const SOFT = 'rgb(34, 31, 61)';
const CARD_MUTED = 'rgba(255, 255, 255, 0.72)';

/** Every text run Gratora draws, on a page or in a form. */
const GRATORA_TEXT = '.gratora-block, .gratora-donation-form, .dp-layout, .dp-panel, .dp-cover, .dp-display';

test.beforeEach(async ({ page }) => {
    await page.addInitScript(installInk);
});

async function open(page: Page, url: string): Promise<void> {
    await page.goto(url);
    await waitForForms(page);
    // A colour read mid-transition is neither end of it.
    await page.addStyleTag({ content: '*, *::before, *::after { transition: none !important; animation: none !important; }' });
}

async function expectNothingBelowTheBar(page: Page, what: string, roots = GRATORA_TEXT): Promise<void> {
    const { count, failures } = await sweep(page, roots);
    expect(count, `${what}: text runs measured`).toBeGreaterThan(0);
    expect(failures.map(describeInk), `${what}: text runs below the WCAG bar`).toEqual([]);
}

function expectInk(ink: Ink, color: string, ground: string, what: string): void {
    expectRgb(ink.color, color, `${what} ink (${describeInk(ink)})`);
    expectRgb(ink.ground, ground, `${what} ground (${describeInk(ink)})`);
}

/** Controls a ring does not mark: a field shows focus on its border, and a hidden radio on its label. */
const MARKED_ELSEWHERE = 'input:not([type="checkbox"]):not([type="radio"]), textarea, [contenteditable]';

/**
 * Tab through the whole page from its top, once round, and hand each control
 * that takes keyboard focus inside the root to the check.
 */
async function tabThrough(page: Page, root: string, check: (control: Locator, name: string) => Promise<void>, limit = 200): Promise<number> {
    await page.evaluate(() => document.querySelectorAll('[data-e2e-seen]').forEach((el) => el.removeAttribute('data-e2e-seen')));
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    let checked = 0;
    for (let i = 0; i < limit; i++) {
        await page.keyboard.press('Tab');
        const name = await page.evaluate(([rootSel, skip]) => {
            document.querySelectorAll('[data-e2e-focus]').forEach((el) => el.removeAttribute('data-e2e-focus'));
            const el = document.activeElement;
            if (! el || el === document.body || el.hasAttribute('data-e2e-seen')) return null;
            el.setAttribute('data-e2e-seen', '');
            if (! el.closest(rootSel) || el.matches(skip) || getComputedStyle(el).opacity === '0') return '';
            el.setAttribute('data-e2e-focus', '');
            return (el.getAttribute('aria-label') || el.textContent || '').trim().slice(0, 40) || el.tagName;
        }, [root, MARKED_ELSEWHERE]);
        if (name === null) break;
        if (name === '') continue;
        await check(page.locator('[data-e2e-focus]'), name);
        checked++;
    }

    return checked;
}

/** Channels within 12 of the colour: a ring on a fractional edge blends a little into its neighbours. */
function near(actual: [number, number, number, number], expected: string): boolean {
    const want = parseRgb(expected);
    return [0, 1, 2].every((i) => Math.abs(actual[i] - want[i]) <= 12);
}

/**
 * The ring on a focused control: 2px, 2px outside it, in the colour measured on
 * the ground around it, and at least 3:1 against that ground in the pixels.
 */
async function expectRing(page: Page, control: Locator, color: string, what: string, inset = false): Promise<void> {
    await expect(control, what).toHaveCSS('outline-style', 'solid');
    await expect(control, what).toHaveCSS('outline-width', '2px');
    await expect(control, what).toHaveCSS('outline-offset', inset ? '-4px' : '2px');
    const ring = await inkOf(control, 'outline-color');
    expectRgb(ring.color, color, `${what} ring colour`);
    for (const read of await ringPixels(page, control, inset)) {
        expect(near(read.ring, color), `${what} ring pixel ${describeRing(read)}, wanted ${color}`).toBe(true);
        expect(read.ratio, `${what} ring pixels ${describeRing(read)}`).toBeGreaterThanOrEqual(3);
    }
}

/** A hovered control's ink against the ground it paints then, with no filter repainting either. */
async function expectHover(page: Page, control: Locator, color: string, ground: string, what: string): Promise<void> {
    await control.hover();
    await expect(control, what).toHaveCSS('filter', 'none');
    const ink = await inkOf(control);
    expectInk(ink, color, ground, `${what} hovered`);
    expectReadable(ink, `${what} hovered`);
    await page.mouse.move(0, 0);
}

/** Tab from the top of the page until an amount tile has keyboard focus. */
async function tabToFirstTile(page: Page): Promise<void> {
    await page.locator('body').click({ position: { x: 1, y: 1 } });
    for (let i = 0; i < 80; i++) {
        await page.keyboard.press('Tab');
        if (await page.evaluate(() => document.activeElement?.classList.contains('gratora-form__preset') ?? false)) {
            return;
        }
    }
    throw new Error('Tab never reached an amount tile.');
}

test.describe('campaign page on the default preset', () => {
    test.skip(! path('CAMPAIGN'), unseeded('CAMPAIGN'));

    for (const viewport of VIEWPORTS) {
        test(`page ink stays the page's on the theme's white page at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('CAMPAIGN'));

            // The Plain form and the blocks paint no card.
            expectInk(await inkOf(page.locator('span.gratora-form__label').first()), MUTED, WHITE, 'form label');
            for (const ink of await inksOf(page.locator('.gratora-stat__value'))) {
                expectInk(ink, INK, WHITE, 'stat value');
            }
            expectReadable(await inkOf(page.locator('p.dp-body').first()), 'description');
            for (const selector of ['.gratora-stat__label', '.gratora-recent-donations__name', '.gratora-form__label']) {
                const inks = await inksOf(page.locator(selector));
                expect(inks.length, selector).toBeGreaterThan(0);
                inks.forEach((ink) => expectReadable(ink, selector));
            }

            // The pale accent drawn as text stands down to the page ink.
            for (const selector of ['.gratora-progress__value', '.gratora-recent-donations__amount', '.gratora-top-donors__amount']) {
                const inks = await inksOf(page.locator(selector));
                expect(inks.length, selector).toBeGreaterThan(0);
                inks.forEach((ink) => expectInk(ink, INK, WHITE, selector));
            }

            // The currency code reads the field's own muted ink.
            expectInk(await inkOf(page.locator('.gratora-amount__code').first()), 'rgba(16, 22, 42, 0.62)', WHITE, 'currency code');

            await expectNothingBelowTheBar(page, 'campaign page');
        });
    }

    test('an administrator sees the same ink', async ({ browser }) => {
        const storageState = process.env.GRATORA_E2E_ADMIN_STORAGE_STATE;
        test.skip(! storageState && ! process.env.GRATORA_E2E_ADMIN_USER, 'set GRATORA_E2E_ADMIN_USER and GRATORA_E2E_ADMIN_PASS, or GRATORA_E2E_ADMIN_STORAGE_STATE');

        const context = await browser.newContext(storageState ? { storageState } : {});
        const page = await context.newPage();
        await page.addInitScript(installInk);
        if (! storageState) {
            await new AdminPage(page).login();
        }

        for (const viewport of VIEWPORTS) {
            await page.setViewportSize(viewport);
            await open(page, path('CAMPAIGN'));
            await expect(page.locator('#wpadminbar')).toHaveCount(1);
            expectInk(await inkOf(page.locator('.gratora-progress__value').first()), INK, WHITE, 'progress value');
            await expectNothingBelowTheBar(page, `campaign page as an administrator at ${viewport.width}`);
        }
        await context.close();
    });
});

test.describe('plain form on a shortcode page', () => {
    test.skip(! path('PLAIN'), unseeded('PLAIN'));

    for (const viewport of VIEWPORTS) {
        test(`every run reads on the host page and the panels it paints at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('PLAIN'));
            const form = page.locator('form.gratora-donation-form');

            // Nothing paints the card, so the page ink stands on white.
            const label = await inkOf(form.locator('span.gratora-form__label').first());
            expectInk(label, MUTED, WHITE, 'field label');
            expect(label.ratio).toBeCloseTo(4.83, 1);
            for (const ink of await inksOf(form.locator('.gratora-form__check span'))) {
                expectInk(ink, INK, WHITE, 'check label');
            }
            expectInk(await inkOf(form.locator('.gratora-goal__amount strong')), INK, WHITE, 'goal amount');
            expectInk(await inkOf(form.locator('.gratora-goal__meta')), MUTED, WHITE, 'goal meta');

            // The summary panel paints the soft ground and its values read it.
            const values = await inksOf(form.locator('.gratora-form__summary-row dd:not(.gratora-form__summary-amount)'));
            expect(values.length, 'summary values').toBeGreaterThan(0);
            values.forEach((ink) => expectInk(ink, WHITE, 'rgb(34, 31, 61)', 'summary value'));

            expectInk(await inkOf(form.locator('.gratora-amount__code')), 'rgba(16, 22, 42, 0.62)', WHITE, 'currency code');
            expectInk(await inkOf(form.locator('.gratora-form__cover-fees-math')), INK, WHITE, 'cover-fees math');

            // The selected tile's accent is measured on its tint.
            expectInk(await inkOf(form.locator('.gratora-form__preset.is-selected')), ACCENT, 'rgb(49, 45, 54)', 'selected tile');

            expectInk(await inkOf(form.locator('.gratora-form__heading')), INK, WHITE, 'heading');
            expectReadable(await inkOf(form.locator('.gratora-form__frequency legend')), 'recurring legend');

            // The required marker is carried toward the page ink.
            const required = await inkOf(form.locator('.gratora-form__required').first());
            expectInk(required, 'rgb(159, 43, 106)', WHITE, 'required marker');
            expect(required.ratio).toBeCloseTo(6.94, 1);

            await expectNothingBelowTheBar(page, 'plain form');
        });
    }

    test('the tabs draw the selected frequency in ink that reads on the page', async ({ page }) => {
        await open(page, path('PLAIN'));
        const tabs = page.locator('.gratora-form__frequency--tabs');
        await expect(tabs).toBeVisible();

        const selected = tabs.locator('.gratora-form__frequency-option.is-selected');
        expectInk(await inkOf(selected), INK, WHITE, 'selected tab');
        expectRgb(await inkOf(selected, 'border-bottom-color').then((i) => i.color), INK, 'selected tab underline');
        await expect(selected).toHaveCSS('border-bottom-width', '2px');

        const other = tabs.locator('.gratora-form__frequency-option:not(.is-selected)').first();
        expectInk(await inkOf(other), MUTED, WHITE, 'unselected tab');
        await expect(other).toHaveCSS('font-size', '13px');
        await expect(other).toHaveCSS('font-weight', '500');
    });

    test('the keyboard ring on a tile reads on the page around it', async ({ page }) => {
        await open(page, path('PLAIN'));
        await tabToFirstTile(page);

        // Drawn 2px outside the tile, on the host page.
        const tile = page.locator('.gratora-form__preset:focus-visible');
        await expect(tile).toHaveCSS('outline-offset', '2px');
        const ring = await inkOf(tile, 'outline-color');
        expectInk(ring, INK, WHITE, 'focus ring');
        expect(ring.ratio).toBeGreaterThanOrEqual(3);
    });

    test('the keyboard ring on the selected tile reads on the page around it', async ({ page }) => {
        await open(page, path('PLAIN'));
        await tabToFirstTile(page);
        await page.keyboard.press('Space');

        const tile = page.locator('.gratora-form__preset.is-selected:focus-visible');
        await expect(tile).toHaveCount(1);
        await expect(tile).toHaveCSS('outline-offset', '2px');
        const ring = await inkOf(tile, 'outline-color');
        expectInk(ring, INK, WHITE, 'focus ring on the selected tile');
        expect(ring.ratio).toBeGreaterThanOrEqual(3);
    });

    test('the field text follows Base font size', async ({ page }) => {
        await open(page, path('PLAIN'));
        const form = page.locator('form.gratora-donation-form');

        for (const size of ['14px', '15px', '16px']) {
            await form.evaluate((el, s) => (el as HTMLElement).style.setProperty('--gratora-type-size', s), size);
            await expect(form.locator('.gratora-form__field input').first()).toHaveCSS('font-size', size);
        }
    });
});

test.describe('framed form', () => {
    test.skip(! path('FRAME'), unseeded('FRAME'));

    for (const viewport of VIEWPORTS) {
        test(`the frame is one card in the brand's ground at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('FRAME'));
            const frame = page.locator('form.gratora-donation-form');

            await expect(frame).toHaveCSS('background-color', CARD);
            await expect(frame).toHaveCSS('border-top-left-radius', '10px');
            await expect(frame).toHaveCSS('box-shadow', 'rgba(15, 23, 42, 0.06) 0px 12px 32px 0px');

            const shot = decodePng(await frame.screenshot());
            const inset = 10;
            const white: string[] = [];
            const check = (x: number, y: number): void => {
                const [r, g, b] = shot.pixel(x, y);
                if (r === 255 && g === 255 && b === 255) white.push(`${x},${y}`);
            };
            for (let y = 0; y < inset; y++) {
                for (let x = inset; x < shot.width - inset; x++) {
                    check(x, y);
                    check(x, shot.height - 1 - y);
                }
            }
            for (let x = 0; x < inset; x++) {
                for (let y = inset; y < shot.height - inset; y++) {
                    check(x, y);
                    check(shot.width - 1 - x, y);
                }
            }
            expect(white.slice(0, 5), `white pixels within ${inset}px inside the frame edge (${white.length})`).toEqual([]);

            const label = await inkOf(frame.locator('span.gratora-form__label').first());
            expectInk(label, 'rgba(255, 255, 255, 0.72)', CARD, 'field label');
            expect(label.ratio).toBeCloseTo(9.63, 1);

            // The field stays white, and so does its ink.
            expectInk(await inkOf(frame.locator('.gratora-amount__code')), 'rgba(16, 22, 42, 0.62)', WHITE, 'currency code');

            const required = await inkOf(frame.locator('.gratora-form__required').first());
            expectInk(required, 'rgb(225, 108, 166)', CARD, 'required marker');
            expect(required.ratio).toBeCloseTo(5.91, 1);

            await expectNothingBelowTheBar(page, 'framed form');
        });
    }

    test('the keyboard ring on a tile reads on the card around it', async ({ page }) => {
        await open(page, path('FRAME'));
        await tabToFirstTile(page);

        const ring = await inkOf(page.locator('.gratora-form__preset:focus-visible'), 'outline-color');
        expectInk(ring, ACCENT, CARD, 'focus ring');
        expect(ring.ratio).toBeCloseTo(14.45, 1);

        await page.keyboard.press('Space');
        const selected = page.locator('.gratora-form__preset.is-selected:focus-visible');
        await expect(selected).toHaveCSS('outline-offset', '2px');
        expectInk(await inkOf(selected, 'outline-color'), ACCENT, CARD, 'focus ring on the selected tile');
    });
});

test.describe('framed form on Bold', () => {
    test.skip(! path('FRAME_BOLD'), unseeded('FRAME_BOLD'));

    test('the frame takes the preset card shadow', async ({ page }) => {
        await open(page, path('FRAME_BOLD'));
        const frame = page.locator('form.gratora-donation-form');

        await expect(frame).toHaveCSS('background-color', 'rgb(245, 81, 81)');
        await expect(frame).toHaveCSS('box-shadow', 'rgba(0, 0, 0, 0.25) 0px 30px 60px 0px');
        expectReadable(await inkOf(frame.locator('span.gratora-form__label').first()), 'field label on Bold');
    });

    // The pink mixed toward the card ink reads 2.04:1 on the red card, so more of the ink is taken there.
    test('the required marker reads on the red card', async ({ page }) => {
        await open(page, path('FRAME_BOLD'));
        const required = await inkOf(page.locator('.gratora-form__required').first());
        expectInk(required, 'rgb(50, 29, 55)', 'rgb(245, 81, 81)', 'required marker on Bold');
        expectReadable(required, 'required marker on Bold');
    });
});

test.describe('photo cover without a photo', () => {
    test.skip(! path('COVER'), unseeded('COVER'));

    for (const viewport of VIEWPORTS) {
        test(`the cover is the accent under ink measured on it at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('COVER'));
            const cover = page.locator('.dp-cover');

            await expect(cover).toHaveCSS('background-color', ACCENT);
            expect(await cover.evaluate((el) => getComputedStyle(el, '::after').backgroundImage)).toBe('none');
            const title = await inkOf(cover.locator('.dp-cover__body h1'));
            expectInk(title, ON_ACCENT, ACCENT, 'cover title');
            expect(title.ratio).toBeCloseTo(14.41, 1);

            await expectNothingBelowTheBar(page, 'cover');
        });
    }
});

test.describe('accent panel with an empty list', () => {
    test.skip(! path('PANEL'), unseeded('PANEL'));

    test('a guest block reads the panel ink, and an empty card its own', async ({ page }) => {
        await open(page, path('PANEL'));
        const panel = page.locator('.dp-panel--accent');
        const titles = await inksOf(panel.locator('h3.gratora-block__title'));

        // The guest's title on the panel, as the host's.
        expect(titles.map((t) => t.text)).toEqual(['Top donors host', 'Top donors guest']);
        titles.forEach((ink) => expectInk(ink, ON_ACCENT, ACCENT, ink.text));
        expect(titles[1].ratio).toBeCloseTo(14.41, 1);

        // The empty card paints the brand's card, and reads it.
        const cards = panel.locator('.gratora-empty');
        await expect(cards).toHaveCount(2);
        for (let i = 0; i < 2; i++) {
            const card = cards.nth(i);
            const title = await inkOf(card.locator('.gratora-empty__title'));
            expectInk(title, WHITE, CARD, 'empty title');
            expect(title.ratio).toBeCloseTo(18, 0);
            expectInk(await inkOf(card.locator('.gratora-empty__sub')), 'rgba(255, 255, 255, 0.72)', CARD, 'empty sub');
            expectInk(await inkOf(card.locator('.gratora-empty__icon')), 'rgba(255, 255, 255, 0.72)', CARD, 'empty icon');
        }

        await expectNothingBelowTheBar(page, 'accent panel');
    });

    test('an empty card in a cover reads its own ground', async ({ page }) => {
        await open(page, path('PANEL'));
        const cards = page.locator('.dp-cover .gratora-empty');
        await expect(cards).toHaveCount(2);
        for (let i = 0; i < 2; i++) {
            const card = cards.nth(i);
            expectInk(await inkOf(card.locator('.gratora-empty__title')), WHITE, CARD, 'empty title in a cover');
            expectInk(await inkOf(card.locator('.gratora-empty__sub')), CARD_MUTED, CARD, 'empty sub in a cover');
        }

        await expectNothingBelowTheBar(page, 'cover with empty lists', '.dp-cover');
    });
});

test.describe('lists in an accent panel and a cover', () => {
    test.skip(! path('LISTS'), unseeded('LISTS'));

    for (const viewport of VIEWPORTS) {
        test(`a soft piece inside a card reads the card's soft ground at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('LISTS'));

            for (const surface of ['.dp-panel--accent', '.dp-cover']) {
                const root = page.locator(surface);

                // The panel re-inks its soft ground; a card inside paints the brand's card again.
                const ranks = await inksOf(root.locator('.gratora-top-donors__podium-rank'));
                expect(ranks.map((r) => r.text), `${surface}: the host's three ranks and the guest's one`).toEqual(['2', '1', '3', '1']);
                ranks.forEach((ink) => {
                    expectInk(ink, WHITE, SOFT, `${surface} podium rank ${ink.text}`);
                    expect(ink.ratio).toBeCloseTo(15.77, 1);
                });
                const anonymous = await inkOf(root.locator('.gratora-top-donors__podium-tier .gratora-avatar--anon'));
                expectInk(anonymous, CARD_MUTED, SOFT, `${surface} anonymous avatar`);
                expectReadable(anonymous, `${surface} anonymous avatar`);

                for (const ink of await inksOf(root.locator('.gratora-top-donors__podium-name:not(.is-anonymous), .gratora-supporter-wall__name, .gratora-campaign-card__title'))) {
                    expectInk(ink, WHITE, CARD, `${surface} ${ink.where}`);
                }
                expectInk(await inkOf(root.locator('.gratora-empty__title')), WHITE, CARD, `${surface} empty title`);

                await expectNothingBelowTheBar(page, `lists in ${surface}`, surface);
            }
        });
    }
});

test.describe('guest blocks on a white-ground host', () => {
    test.skip(! path('WHITE_HOST'), unseeded('WHITE_HOST'));

    for (const viewport of VIEWPORTS) {
        test(`a guest's figures read on the page they are on at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('WHITE_HOST'));

            for (const selector of ['.gratora-progress__value', '.gratora-stat__value', 'h3.gratora-block__title', '.gratora-recent-donations__amount', '.gratora-top-donors__amount']) {
                const inks = await inksOf(page.locator(selector));
                expect(inks.length, selector).toBeGreaterThan(0);
                inks.forEach((ink) => expectInk(ink, INK, WHITE, selector));
            }
            for (const selector of ['.gratora-progress__caption', '.gratora-progress__target', '.gratora-stat__label']) {
                const inks = await inksOf(page.locator(selector));
                expect(inks.length, selector).toBeGreaterThan(0);
                inks.forEach((ink) => expectInk(ink, MUTED, WHITE, selector));
            }

            await expectNothingBelowTheBar(page, 'white-ground host');
        });
    }
});

test.describe('grids on a Bold host', () => {
    test.skip(! path('BOLD_HOST'), unseeded('BOLD_HOST'));

    test('each card measures its accent on the card it is drawn on', async ({ page }) => {
        await open(page, path('BOLD_HOST'));

        // Muted ink on Bold's red card is measured.
        expect(await page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--gratora-on-bg-muted').trim())).toBe('rgba(16,22,42,.86)');
        const sub = await inkOf(page.locator('.gratora-empty__sub'));
        expectRgb(sub.ground, 'rgb(245, 81, 81)', 'empty card on Bold');
        expect(sub.ratio, describeInk(sub)).toBeGreaterThanOrEqual(4.5);

        // The guest states its tint unset rather than inheriting the host's.
        const guest = page.locator('section.gratora-block--grid.e2e-grid-guest');
        const host = page.locator('section.gratora-block--grid.e2e-grid-host');
        expect(await guest.evaluate((el) => getComputedStyle(el).getPropertyValue('--gratora-accent-soft'))).toBe('');
        expect(await guest.getAttribute('style')).toContain('--gratora-accent-soft:initial;');

        const palePill = await inkOf(guest.locator('.gratora-campaign-card', { hasText: 'Branding Pale' }).locator('.gratora-campaign-card__pct'));
        expectInk(palePill, ACCENT, 'rgb(49, 45, 54)', 'pale pill in the QA grid');
        expect(palePill.ratio).toBeCloseTo(10.81, 1);

        const onDark = await inkOf(guest.locator('.gratora-campaign-card', { hasText: 'Branding Bold' }).locator('.gratora-campaign-card__link'));
        expectInk(onDark, WHITE, CARD, 'navy card link on the QA card');
        expect(onDark.ratio).toBeCloseTo(18, 0);
        const onRed = await inkOf(host.locator('.gratora-campaign-card', { hasText: 'Branding Page' }).locator('.gratora-campaign-card__link'));
        expectInk(onRed, INK, 'rgb(245, 81, 81)', 'pale card link on the Bold card');
        expect(onRed.ratio).toBeCloseTo(5.22, 1);

        // Every card, whatever campaign it shows.
        for (const ink of await inksOf(page.locator('.gratora-campaign-card__link'))) expectReadable(ink, 'card link');
        for (const ink of await inksOf(page.locator('.gratora-campaign-card__pct'))) expectReadable(ink, 'card percentage');

        await expectNothingBelowTheBar(page, 'Bold host');
    });
});

test.describe('grid on a page with no campaign', () => {
    test.skip(! path('WHITE_GRID'), unseeded('WHITE_GRID'));

    test('a pale card measures its tint on the white card', async ({ page }) => {
        await open(page, path('WHITE_GRID'));

        const pill = await inkOf(page.locator('.gratora-campaign-card', { hasText: 'Branding Page' }).locator('.gratora-campaign-card__pct'));
        expectInk(pill, ON_ACCENT, 'rgb(255, 252, 241)', 'pale pill on a white grid');
        expect(pill.ratio).toBeCloseTo(17.47, 1);
        for (const ink of await inksOf(page.locator('.gratora-campaign-card__link'))) expectReadable(ink, 'card link');
        for (const ink of await inksOf(page.locator('.gratora-campaign-card__pct'))) expectReadable(ink, 'card percentage');
    });
});

test.describe('Classic accent panel', () => {
    test.skip(! path('CLASSIC'), unseeded('CLASSIC'));

    test('muted ink on the accent is measured, and the selected tile reads its tint', async ({ page }) => {
        await open(page, path('CLASSIC'));
        const panel = page.locator('.dp-panel--accent');

        for (const selector of ['.gratora-progress__caption', '.gratora-progress__target', '.gratora-stat__label']) {
            const inks = await inksOf(panel.locator(selector));
            expect(inks.length, selector).toBeGreaterThan(0);
            inks.forEach((ink) => {
                expectInk(ink, 'rgba(255, 255, 255, 0.74)', 'rgb(69, 46, 245)', selector);
                expect(ink.ratio, describeInk(ink)).toBeGreaterThanOrEqual(4.5);
            });
        }

        const form = page.locator('form.gratora-donation-form').first();
        if (await form.locator('.gratora-form__preset.is-selected').count() === 0) {
            await form.locator('.gratora-form__preset').first().click();
            await page.mouse.move(0, 0);
        }
        const tile = await inkOf(form.locator('.gratora-form__preset.is-selected'));
        expectInk(tile, WHITE, 'rgb(121, 64, 87)', 'selected tile on Classic');
        expect(tile.ratio).toBeCloseTo(7.82, 1);

        await expectNothingBelowTheBar(page, 'Classic campaign');
    });
});

test.describe('Site theme preset', () => {
    test.skip(! path('THEME'), unseeded('THEME'));

    test('the selected tile reads its tint', async ({ page }) => {
        await open(page, path('THEME'));
        const form = page.locator('form.gratora-donation-form').first();
        if (await form.locator('.gratora-form__preset.is-selected').count() === 0) {
            await form.locator('.gratora-form__preset').first().click();
            await page.mouse.move(0, 0);
        }

        const tile = await inkOf(form.locator('.gratora-form__preset.is-selected'));
        expect(tile.ratio, describeInk(tile)).toBeGreaterThanOrEqual(4.5);
        const accent = await form.evaluate((el) => getComputedStyle(el).getPropertyValue('--gratora-accent').trim().toLowerCase());
        if (accent === '#ffee58') {
            expectInk(tile, ON_ACCENT, 'rgb(255, 253, 235)', 'selected tile on the twentytwentyfive accent');
        }
    });
});

test.describe('donate button modal', () => {
    test.skip(! path('MODAL'), unseeded('MODAL'));

    for (const viewport of VIEWPORTS) {
        test(`the panel fits the viewport and its form reads the card at ${viewport.width}`, async ({ page }) => {
            await page.setViewportSize(viewport);
            await open(page, path('MODAL'));
            await page.locator('.gratora-donate-button').click();
            const panel = page.locator('.gratora-donate-modal__panel');
            await expect(panel).toBeVisible();
            await expect(panel.locator('form.gratora-donation-form')).toHaveAttribute('data-gratora-ready', /.*/);

            const fits = await panel.evaluate((el) => el.getBoundingClientRect().bottom <= window.innerHeight);
            expect(fits, 'panel bottom within the viewport').toBe(true);

            const label = await inkOf(panel.locator('span.gratora-form__label').first());
            expectInk(label, 'rgba(255, 255, 255, 0.72)', CARD, 'field label in the modal');
            await expectNothingBelowTheBar(page, 'donate modal');
        });
    }
});

test.describe('guest button and form on a Quiet host', () => {
    test.skip(! path('QUIET_HOST'), unseeded('QUIET_HOST'));

    // Quiet sets a transparent button under dark text; the guest leaves both to its accent.
    test('the guest\'s donate button and its modal draw the guest\'s own buttons', async ({ page }) => {
        await open(page, path('QUIET_HOST'));

        const trigger = page.locator('.gratora-donate-button');
        await expect(trigger).toHaveCSS('background-color', ACCENT);
        expectInk(await inkOf(trigger), ON_ACCENT, ACCENT, 'donate button');

        await trigger.click();
        const panel = page.locator('.gratora-donate-modal__panel');
        await expect(panel).toBeVisible();
        await expect(panel.locator('form.gratora-donation-form')).toHaveAttribute('data-gratora-ready', /.*/);

        const submit = panel.locator('.gratora-form__button--primary').first();
        await expect(submit).toHaveCSS('background-color', ACCENT);
        const ink = await inkOf(submit);
        expectInk(ink, ON_ACCENT, ACCENT, 'modal submit');
        expect(ink.ratio).toBeCloseTo(14.41, 1);

        await expectNothingBelowTheBar(page, 'Quiet host with its modal open');
    });

    test('a form of another campaign draws its own button', async ({ page }) => {
        await open(page, path('QUIET_HOST'));

        const submit = page.locator('form.gratora-donation-form:not(.gratora-donate-modal form) .gratora-form__button--primary').first();
        await expect(submit).toHaveCSS('background-color', ACCENT);
        expectInk(await inkOf(submit), ON_ACCENT, ACCENT, 'the form\'s own submit');
    });
});

test.describe('the campaign page foundation an add-on draws with', () => {
    test.skip(! path('LAYOUT'), unseeded('LAYOUT'));

    // A theme heading takes the colour it inherits, not the ink token the card restates.
    test('text that only inherits reads the card it sits in', async ({ page }) => {
        await open(page, path('LAYOUT'));

        const card = page.locator('.e2e-layout-card');
        await expect(card).toHaveCSS('background-color', CARD);
        expectInk(await inkOf(card.locator('h3')), WHITE, CARD, 'theme heading in a card');
        expectInk(await inkOf(page.locator('.dp-profile .dp-display')), WHITE, CARD, 'name on a profile');

        await expectNothingBelowTheBar(page, 'layout cards', '.dp-card, .dp-profile');
    });

    test('an avatar and a tag read the tint they paint', async ({ page }) => {
        await open(page, path('LAYOUT'));

        // The accent mixed into white; the accent darkened on it read 2.03:1.
        for (const selector of ['.dp-avatar', '.dp-tag', '.dp-profile__avatar']) {
            const ink = await inkOf(page.locator(selector).first());
            expectInk(ink, ON_ACCENT, 'rgb(255, 252, 241)', selector);
            expect(ink.ratio, describeInk(ink)).toBeGreaterThanOrEqual(4.5);
        }
    });

    test('a share item reads the card, and hovered the tint it paints', async ({ page }) => {
        await open(page, path('LAYOUT'));

        const item = page.locator('.dp-sharesheet__item').first();
        expectInk(await inkOf(item), WHITE, CARD, 'share item');
        await item.hover();
        const hovered = await inkOf(item);
        expectInk(hovered, ON_ACCENT, 'rgb(255, 252, 241)', 'share item hovered');
        expectReadable(hovered, 'share item hovered');
    });
});

const BOLD_ACCENT = 'rgb(15, 61, 92)';
const CLASSIC_ACCENT = 'rgb(69, 46, 245)';
const CORAL_DARK = 'rgb(191, 63, 63)';
const QA_DARK = 'rgb(197, 179, 108)';

test.describe('keyboard rings', () => {
    test('every control in a Plain form rings in the page ink', async ({ page }) => {
        test.skip(! path('PLAIN'), unseeded('PLAIN'));
        await open(page, path('PLAIN'));

        const n = await tabThrough(page, 'form.gratora-donation-form', (control, name) => expectRing(page, control, INK, `plain form ${name}`));
        expect(n, 'controls the ring was read on').toBeGreaterThanOrEqual(8);
    });

    test('every control in a framed form rings in the accent measured on the card', async ({ page }) => {
        test.skip(! path('FRAME'), unseeded('FRAME'));
        await open(page, path('FRAME'));

        const n = await tabThrough(page, 'form.gratora-donation-form', (control, name) => expectRing(page, control, ACCENT, `framed form ${name}`));
        expect(n, 'controls the ring was read on').toBeGreaterThanOrEqual(8);
    });

    test('a pill rings on the soft track it sits in', async ({ page }) => {
        test.skip(! path('PILLS'), unseeded('PILLS'));
        await open(page, path('PILLS'));

        const tracks = '.gratora-form__frequency-options, .gratora-form__currency-pills';
        let pills = 0;
        await tabThrough(page, 'form.gratora-donation-form', async (control, name) => {
            const onTrack = await control.evaluate((el, sel) => !! el.closest(sel), tracks);
            if (onTrack) pills++;
            await expectRing(page, control, onTrack ? ACCENT : INK, `pills form ${name}`);
        });
        expect(pills, 'pills the ring was read on').toBeGreaterThanOrEqual(2);
    });

    for (const viewport of VIEWPORTS) {
        test(`a grid card rings in the ink of the panel and the cover it sits in at ${viewport.width}`, async ({ page }) => {
            test.skip(! path('LISTS'), unseeded('LISTS'));
            await page.setViewportSize(viewport);
            await open(page, path('LISTS'));

            let cards = 0;
            await tabThrough(page, '.dp-panel--accent, .dp-cover', async (control, name) => {
                cards++;
                await expectRing(page, control, ON_ACCENT, `card ${name}`);
            }, 200);
            expect(cards, 'cards the ring was read on').toBeGreaterThanOrEqual(2);
        });
    }

    test('on Classic the panel rings in its ink and the page in the accent', async ({ page }) => {
        test.skip(! path('CLASSIC_PANEL'), unseeded('CLASSIC_PANEL'));
        await open(page, path('CLASSIC_PANEL'));

        const inPanel = await tabThrough(page, '.dp-panel--accent', (control, name) => expectRing(page, control, WHITE, `Classic panel ${name}`));
        expect(inPanel, 'panel controls the ring was read on').toBeGreaterThanOrEqual(3);
        const onPage = await tabThrough(page, '.e2e-on-page', (control, name) => expectRing(page, control, CLASSIC_ACCENT, `Classic page ${name}`));
        expect(onPage).toBe(1);
    });

    // Bold chose a navy ring beside its navy accent: it reads on the page and not on the panel it paints.
    test('on Bold the chosen ring stands on the page and gives way on the panel', async ({ page }) => {
        test.skip(! path('BOLD_PANEL'), unseeded('BOLD_PANEL'));
        await open(page, path('BOLD_PANEL'));

        const inPanel = await tabThrough(page, '.dp-panel--accent', (control, name) => expectRing(page, control, WHITE, `Bold panel ${name}`));
        expect(inPanel, 'panel controls the ring was read on').toBeGreaterThanOrEqual(3);
        const onPage = await tabThrough(page, '.e2e-on-page', (control, name) => expectRing(page, control, BOLD_ACCENT, `Bold page ${name}`));
        expect(onPage).toBe(1);
    });

    test('the campaign page buttons ring on the card, and on a photo inside the button', async ({ page }) => {
        test.skip(! path('LAYOUT'), unseeded('LAYOUT'));
        await open(page, path('LAYOUT'));

        const onCard = await tabThrough(page, '.dp-profile, .e2e-flat-hero, .dp-sharesheet', (control, name) => expectRing(page, control, ACCENT, `card ${name}`));
        expect(onCard, 'card controls the ring was read on').toBeGreaterThanOrEqual(5);

        // The page cannot measure a photo, so the white button rings inside itself in its own ink.
        const onPhoto = await tabThrough(page, '.e2e-photo-hero', (control, name) => expectRing(page, control, INK, `photo ${name}`, true));
        expect(onPhoto).toBe(1);
    });
});

test.describe('hovered buttons', () => {
    test('on the QA brand each hover reads the ground it paints', async ({ page }) => {
        test.skip(! path('LAYOUT'), unseeded('LAYOUT'));
        await open(page, path('LAYOUT'));

        await expectHover(page, page.locator('.dp-profile .dp-btn:not(.dp-btn--ghost)'), ON_ACCENT, QA_DARK, 'profile donate');
        await expectHover(page, page.locator('.dp-profile .dp-btn--ghost'), WHITE, CARD, 'profile ghost');
        await expectHover(page, page.locator('.e2e-flat-hero .dp-btn'), ON_ACCENT, QA_DARK, 'flat hero in a card');
        await expectHover(page, page.locator('.e2e-photo-hero .dp-btn'), WHITE, SOFT, 'white button on a photo');
    });

    // The dark ink measured on #f55151 reads 3.42:1 on the darker fill a hover paints; white reads 5.25:1 there.
    test('under a mid-tone accent the hover takes the ink measured on its fill', async ({ page }) => {
        test.skip(! path('CORAL'), unseeded('CORAL'));
        await open(page, path('CORAL'));

        await expectHover(page, page.locator('.dp-cta .wp-block-button__link'), WHITE, CORAL_DARK, 'call to action');
        await expectHover(page, page.locator('.e2e-flat-hero .dp-btn'), WHITE, CORAL_DARK, 'flat hero on the page');
        await expectHover(page, page.locator('.dp-profile .dp-btn:not(.dp-btn--ghost)'), WHITE, CORAL_DARK, 'profile donate');
        await expectHover(page, page.locator('.dp-profile .dp-btn--ghost'), WHITE, CARD, 'profile ghost');
        await expectHover(page, page.locator('.gratora-donate-button'), WHITE, CORAL_DARK, 'donate button');
        await expectHover(page, page.locator('form.gratora-donation-form:not(.gratora-donate-modal form) .gratora-form__button--primary').first(), WHITE, CORAL_DARK, 'form submit');
    });

    // #10162a reads 5.01:1 on this orange soft ground and 4.41:1 on the fill 8% darker, so the tile lightens instead.
    test('a hovered tile keeps a fill its ink reads on', async ({ page }) => {
        test.skip(! path('CORAL'), unseeded('CORAL'));
        await open(page, path('CORAL'));

        const tile = page.locator('form.gratora-donation-form:not(.gratora-donate-modal form) .gratora-form__preset:not(.is-selected)').first();
        expectInk(await inkOf(tile), ON_ACCENT, 'rgb(232, 89, 12)', 'tile');
        await expectHover(page, tile, ON_ACCENT, 'rgb(234, 102, 31)', 'tile');
    });

    test('a donate button on Classic and on Bold reads its hover fill, in the panel and on the page', async ({ page }) => {
        for (const [name, dark] of [['CLASSIC_PANEL', 'rgb(54, 36, 191)'], ['BOLD_PANEL', 'rgb(12, 48, 72)']] as const) {
            test.skip(! path(name), unseeded(name));
            await open(page, path(name));
            for (const where of ['.e2e-in-panel', '.e2e-on-page']) {
                await expectHover(page, page.locator(`${where} .gratora-donate-button`), WHITE, dark, `${name} ${where}`);
            }
        }
    });
});

const PORTAL = process.env.GRATORA_E2E_BRANDING_PORTAL_URL ?? '';
const PORTAL_UNSEEDED = 'set GRATORA_E2E_BRANDING_PORTAL_URL, a single-use link each run of `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed-branding` prints';
const NO_MOTION = '*, *::before, *::after { transition: none !important; animation: none !important; }';
const RED = 'rgb(185, 28, 28)';
const CARD_RED = 'rgb(205, 92, 92)';

/** Answer one portal route with a body of the test's choosing, leaving the rest to the server. Returns the undo. */
async function answer(page: Page, route: string, status: number, body: unknown, method = 'GET'): Promise<() => Promise<void>> {
    const url = new RegExp(`/wp-json/gratora/v1/portal/${route}(\\?.*)?$`);
    await page.route(url, (r) => (
        r.request().method() === method
            ? r.fulfill({ status, contentType: 'application/json', body: JSON.stringify(body) })
            : r.fallback()
    ));

    return () => page.unroute(url);
}

test.describe('donor portal signed out', () => {
    test.skip(! PORTAL, PORTAL_UNSEEDED);

    test('the links read on the page', async ({ page }) => {
        await page.goto(new URL(PORTAL).pathname);
        const signin = page.locator('.gratora-donor-portal .dp-signin');
        await expect(signin).toBeVisible({ timeout: 15_000 });
        await page.addStyleTag({ content: NO_MOTION });

        const create = signin.locator('.dp-link');
        await expect(create).toHaveText('Create an account');
        expectInk(await inkOf(create), INK, WHITE, 'create an account');
        await create.click();
        await expect(create).toHaveText('Sign in');
        expectInk(await inkOf(create), INK, WHITE, 'sign in');

        await expectNothingBelowTheBar(page, 'signed-out portal', '.gratora-donor-portal');
    });

    test('an error reads on the page in the red that reads there', async ({ page }) => {
        await page.goto(new URL(PORTAL).pathname);
        await expect(page.locator('.dp-signin')).toBeVisible({ timeout: 15_000 });
        await answer(page, 'send-link', 500, { message: 'The link could not be sent.' }, 'POST');

        await page.locator('.dp-signin input[type="email"]').fill('nobody@example.test');
        await page.locator('.dp-signin button[type="submit"]').click();
        const error = page.locator('.dp-signin__error');
        await expect(error).toHaveText('The link could not be sent.');
        expectInk(await inkOf(error), RED, WHITE, 'sign-in error');
    });

    test('the email field paints the field ground under its ink', async ({ page }) => {
        await page.goto(new URL(PORTAL).pathname);
        const email = page.locator('.dp-signin input[type="email"]');
        await email.fill('nobody@example.test');
        expectInk(await inkOf(email), ON_ACCENT, WHITE, 'email field');
    });
});

test.describe('donor portal', () => {
    test.skip(! PORTAL, PORTAL_UNSEEDED);
    test.describe.configure({ mode: 'serial' });

    let page: Page;
    let root: Locator;

    /** Opens a tab afresh, so it loads what the routes answer now. */
    const tab = async (name: string): Promise<void> => {
        const target = root.getByRole('tab', { name, exact: true });
        if (await target.getAttribute('aria-selected') === 'true') {
            await root.getByRole('tab', { name: 'Overview', exact: true }).click();
        }
        await target.click();
    };

    test.beforeAll(async ({ browser }) => {
        page = await browser.newPage({ viewport: { width: 1280, height: 720 } });
        await page.addInitScript(installInk);
        await page.goto(PORTAL);
        root = page.locator('.gratora-donor-portal');
        await expect(root.locator('.dp-kpi').first()).toBeVisible({ timeout: 15_000 });
        await page.addStyleTag({ content: NO_MOTION });
    });

    test.afterAll(async () => {
        await page?.close();
    });

    test('what paints the card or the soft ground reads it, and the root reads the page', async () => {
        // The root paints nothing, the figures sit on the soft ground.
        expectInk(await inkOf(root.locator('.dp__head h1')), INK, WHITE, 'greeting');
        expectInk(await inkOf(root.locator('.dp-kpi__value').first()), WHITE, SOFT, 'figure');
        await expectNothingBelowTheBar(page, 'portal overview', '.gratora-donor-portal');

        // A wide tab paints the soft ground when active or hovered, a narrow one nothing.
        const active = root.locator('.dp__tab.is-active');
        expectInk(await inkOf(active), ACCENT, SOFT, 'active tab');
        const other = root.locator('.dp__tab:not(.is-active)').first();
        await other.hover();
        expectInk(await inkOf(other), WHITE, SOFT, 'hovered tab');
        await page.mouse.move(0, 0);

        await page.setViewportSize({ width: 390, height: 844 });
        expectInk(await inkOf(active), INK, WHITE, 'active tab on a narrow screen');
        expectRgb((await inkOf(active, 'border-bottom-color')).color, INK, 'active tab underline on a narrow screen');
        await other.hover();
        expectInk(await inkOf(other), INK, WHITE, 'hovered tab on a narrow screen');
        await page.mouse.move(0, 0);
        await expectNothingBelowTheBar(page, 'portal overview on a narrow screen', '.gratora-donor-portal');
        await page.setViewportSize({ width: 1280, height: 720 });

        await tab('Donations');
        const row = root.locator('.dp-list__row').first();
        await expect(row).toBeVisible();
        expectInk(await inkOf(row), WHITE, CARD, 'donation row');
        await expectNothingBelowTheBar(page, 'portal donations', '.gratora-donor-portal');

        await row.click();
        const detail = root.locator('.dp-modal__panel');
        await expect(detail).toBeVisible();
        expectInk(await inkOf(detail), WHITE, CARD, 'donation detail');
        await expectNothingBelowTheBar(page, 'portal donation detail', '.gratora-donor-portal');
        await detail.locator('.dp-modal__close').click();
        await expect(detail).toHaveCount(0);
    });

    test('a link and a saved note read the accent measured on their ground', async () => {
        await tab('Profile');
        await root.locator('.dp-form__actions .dp-action.is-primary').click();
        const saved = root.locator('.dp-form__saved');
        await expect(saved).toBeVisible();
        expectInk(await inkOf(saved), INK, WHITE, 'saved note on the page');

        // A tab that could not load offers to try again, on the page.
        const donations = await answer(page, 'donations', 500, { message: 'The donations could not be loaded.' });
        await tab('Donations');
        const retry = root.locator('.dp-error .dp-link');
        await expect(retry).toHaveText('Try again');
        expectInk(await inkOf(retry), INK, WHITE, 'try again');
        await donations();

        // In a row the link stands on the card, where the pale accent reads.
        const receipts = await answer(page, 'receipts', 200, { items: [{ id: 9001, receipt_number: 'R-9001', issued_at: '2026-09-01T10:00:00Z' }], total: 1 });
        await tab('Receipts & tax');
        const download = root.locator('.dp-list__row .dp-link');
        await expect(download).toHaveText('Download');
        expectInk(await inkOf(download), ACCENT, CARD, 'download link in a row');
        await receipts();
    });

    test('the keyboard ring on a row reads on the page around it', async () => {
        await tab('Donations');
        await expect(root.locator('.dp-list__row').first()).toBeVisible();
        for (let i = 0; i < 30 && ! await page.evaluate(() => document.activeElement?.classList.contains('dp-list__row') ?? false); i++) {
            await page.keyboard.press('Tab');
        }

        // Drawn 2px outside the row, on the page.
        const row = root.locator('.dp-list__row:focus-visible');
        await expect(row).toHaveCount(1);
        await expect(row).toHaveCSS('outline-offset', '2px');
        const ring = await inkOf(row, 'outline-color');
        expectInk(ring, INK, WHITE, 'row focus ring');
        expect(ring.ratio).toBeGreaterThanOrEqual(3);
    });

    test('the statement controls ring on the soft card they sit in', async () => {
        await tab('Receipts & tax');
        await expect(root.locator('.dp-card__row select')).toBeVisible();

        const n = await tabThrough(page, '.gratora-donor-portal .dp-card', (control, name) => expectRing(page, control, ACCENT, `statement ${name}`));
        expect(n, 'statement controls the ring was read on').toBeGreaterThanOrEqual(2);
    });

    // A dark card beside the soft ground as it ships, as the server would state it for #f8fafb.
    test('a hovered row and a hovered country read the soft ground they paint', async () => {
        const shipped = await page.addStyleTag({
            content: '.gratora-donor-portal{--gratora-bg-soft:#f8fafb;--gratora-on-soft:#10162a;--gratora-on-soft-muted:rgba(16,22,42,.62);--gratora-on-soft-accent:#10162a;--gratora-on-soft-danger:#b91c1c}',
        });
        const PALE_SOFT = 'rgb(248, 250, 251)';
        try {
            await tab('Donations');
            const row = root.locator('.dp-list__row').first();
            await expect(row).toBeVisible();
            expectInk(await inkOf(row), WHITE, CARD, 'row');
            await row.hover();
            const hovered = await inkOf(row);
            expectInk(hovered, ON_ACCENT, PALE_SOFT, 'hovered row');
            expectReadable(hovered, 'hovered row');
            await page.mouse.move(0, 0);

            await tab('Profile');
            await root.locator('.dp-country input').click();
            const option = root.locator('.dp-country__list button').first();
            await option.hover();
            expectInk(await inkOf(option), ON_ACCENT, PALE_SOFT, 'hovered country');
            expectInk(await inkOf(option.locator('.dp-country__code')), 'rgba(16, 22, 42, 0.62)', PALE_SOFT, 'hovered country code');
            await page.mouse.move(0, 0);
            await page.keyboard.press('Escape');
        } finally {
            await shipped.evaluate((el) => el.remove());
        }
    });

    test('danger reads on the ground it stands on', async () => {
        // On the page the red reads, 6.47:1, and stands.
        const donations = await answer(page, 'donations', 500, { message: 'The donations could not be loaded.' });
        await tab('Donations');
        const onPage = root.locator('.dp__main > .dp-error');
        await expect(onPage).toBeVisible();
        expectInk(await inkOf(onPage), RED, WHITE, 'error on the page');
        await donations();

        // A receipt that will not download says so in its row, on the card.
        const receipts = await answer(page, 'receipts', 200, { items: [{ id: 9001, receipt_number: 'R-9001', issued_at: '2026-09-01T10:00:00Z' }], total: 1 });
        const download = await answer(page, 'receipts/9001/download-url', 500, { message: 'The receipt could not be prepared.' });
        const statement = await answer(page, 'annual-statement/\\d+', 500, { message: 'The statement could not be prepared.' });
        await tab('Receipts & tax');
        await root.locator('.dp-list__row .dp-link').click();
        const inRow = root.locator('.dp-list__row .dp-error');
        await expect(inRow).toHaveText('The receipt could not be prepared.');
        expectInk(await inkOf(inRow), CARD_RED, CARD, 'error in a row');
        expect((await inkOf(inRow)).ratio).toBeGreaterThanOrEqual(4.5);

        // The annual statement sits on the soft ground.
        await root.locator('.dp-card .dp-action.is-primary').click();
        const onSoft = root.locator('.dp-card .dp-error');
        await expect(onSoft).toHaveText('The statement could not be prepared.');
        expectInk(await inkOf(onSoft), 'rgb(210, 107, 107)', SOFT, 'error on the soft ground');
        await receipts();
        await download();
        await statement();

        // Deleting the account is offered on a card, and its hover paints a pale red of its own.
        await tab('Profile');
        const destructive = root.locator('.dp-action.is-destructive');
        expectInk(await inkOf(destructive), CARD_RED, CARD, 'delete my account');
        await destructive.hover();
        expectInk(await inkOf(destructive), RED, 'rgb(254, 242, 242)', 'delete my account hovered');
        await page.mouse.move(0, 0);

        // The confirming button in the dialog reads on the dialog's card once it is enabled.
        await destructive.click();
        const dialog = root.locator('.dp-modal__panel');
        await expect(dialog).toBeVisible();
        const confirm = dialog.locator('.dp-action--danger');
        await expect(confirm).toBeDisabled();
        expectInk(await inkOf(confirm), CARD_RED, CARD, 'confirm delete');
        await dialog.getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(dialog).toHaveCount(0);

        // A plan's cancel action and a refused change, on the plan's dialog.
        const plans = await answer(page, 'recurring', 200, [{
            id: 9002, amount_cents: 2500, currency: 'USD', interval_count: 1, interval_unit: 'month',
            status: 'active', next_payment_at: '2026-10-01T10:00:00Z', can_pause: true,
        }]);
        const refused = await answer(page, 'recurring/9002/action', 422, { message: 'The processor refused the change.' }, 'POST');
        await tab('Recurring');
        await root.locator('.dp-list__row').click();
        const sheet = root.locator('.dp-modal__panel');
        expectInk(await inkOf(sheet.locator('.dp-action--danger')), CARD_RED, CARD, 'cancel subscription');
        await sheet.getByRole('button', { name: 'Skip next charge' }).click();
        const sheetError = sheet.locator('.dp-error');
        await expect(sheetError).toHaveText('The processor refused the change.');
        expectInk(await inkOf(sheetError), CARD_RED, CARD, 'error in a dialog');
        await sheet.locator('.dp-modal__close').click();
        await expect(sheet).toHaveCount(0);
        await plans();
        await refused();
    });

    test('a field paints the field ground under the field ink, wherever it stands', async () => {
        await tab('Profile');
        const first = root.locator('.dp-form__row input').first();
        expectInk(await inkOf(first), ON_ACCENT, WHITE, 'first name on the page');
        expectInk(await inkOf(root.locator('.dp-form input[type="email"]')), CARD_MUTED, SOFT, 'the email that cannot change');

        // Typed into on the dialog's dark card.
        await root.locator('.dp-action.is-destructive').click();
        const confirm = root.locator('.dp-modal__panel .dp-form input');
        await confirm.fill('abc');
        expectInk(await inkOf(confirm), ON_ACCENT, WHITE, 'typed confirmation');
        await root.locator('.dp-modal__panel').getByRole('button', { name: 'Cancel', exact: true }).click();
        await expect(confirm).toHaveCount(0);

        // The statement year sits on the soft ground.
        await tab('Receipts & tax');
        expectInk(await inkOf(root.locator('.dp-card__row select')), ON_ACCENT, WHITE, 'statement year');

        const plans = await answer(page, 'recurring', 200, [{
            id: 9002, amount_cents: 2500, currency: 'USD', interval_count: 1, interval_unit: 'month',
            status: 'active', next_payment_at: '2026-10-01T10:00:00Z', can_pause: true,
        }]);
        await tab('Recurring');
        const sheet = root.locator('.dp-modal__panel');

        await root.locator('.dp-list__row').click();
        await sheet.getByRole('button', { name: 'Change amount' }).click();
        expectInk(await inkOf(sheet.locator('.gratora-amount__input')), ON_ACCENT, WHITE, 'new amount');
        expectInk(await inkOf(sheet.locator('.gratora-amount__code')), 'rgba(16, 22, 42, 0.62)', WHITE, 'currency code');
        await sheet.locator('.dp-modal__close').click();
        await expect(sheet).toHaveCount(0);

        await root.locator('.dp-list__row').click();
        await sheet.getByRole('button', { name: 'Cancel subscription' }).click();
        await sheet.getByRole('button', { name: 'Continue to cancel' }).click();
        const reason = sheet.locator('textarea');
        await reason.fill('Moving abroad');
        expectInk(await inkOf(reason), ON_ACCENT, WHITE, 'reason');
        await sheet.locator('.dp-modal__close').click();
        await expect(sheet).toHaveCount(0);
        await plans();
    });

    test('a consent with new terms reads the pale ground it paints', async () => {
        const PALE = 'rgb(255, 251, 235)';
        const consents = await answer(page, 'consents', 200, [
            { key: 'e2e-news', label: 'Newsletter', description: 'News about the work, once a month.', granted: true, required: false, stale: true, has_record: true, occurred_at: '2026-01-05T10:00:00Z' },
            { key: 'e2e-terms', label: 'Terms', description: 'The terms every donor accepts.', granted: true, required: true, stale: false, has_record: true, occurred_at: '2026-01-05T10:00:00Z' },
        ]);
        await tab('Consents');
        const stale = root.locator('.dp-consent.is-stale');
        await expect(stale).toHaveCSS('background-color', PALE);

        expectInk(await inkOf(stale.locator('strong')), ON_ACCENT, PALE, 'stale label');
        for (const selector of ['.dp-consent__desc', '.dp-consent__meta']) {
            const ink = await inkOf(stale.locator(selector));
            expectInk(ink, 'rgba(16, 22, 42, 0.62)', PALE, selector);
            expect(ink.ratio, describeInk(ink)).toBeGreaterThanOrEqual(4.5);
        }

        // Checked, the box fills with the ink that reads on the pale ground rather than the pale accent.
        const box = stale.locator('input[type="checkbox"]');
        await expect(box).toBeChecked();
        expectRgb(parseRgb(await box.evaluate((el) => getComputedStyle(el).accentColor)), ON_ACCENT, 'stale checkbox fill');
        const square = await box.boundingBox();
        if (! square) throw new Error('The stale checkbox has no box.');
        const shot = decodePng(await page.screenshot());
        const y = Math.round(square.y + square.height / 2);
        const fill = shot.pixel(Math.ceil(square.x) + 2, y);
        const pale = shot.pixel(Math.floor(square.x) - 2, y);
        expect(contrast(fill, pale), `stale checkbox ${fill.join(',')} on ${pale.join(',')}`).toBeGreaterThanOrEqual(3);

        const keep = stale.locator('.dp-consent__confirm');
        expectInk(await inkOf(keep), ON_ACCENT, PALE, 'keep as is');
        await keep.hover();
        expectInk(await inkOf(keep), ON_ACCENT, PALE, 'keep as is hovered');
        const edge = await inkOf(keep, 'border-top-color');
        expect(edge.ratio, `hovered edge ${describeInk(edge)}`).toBeGreaterThanOrEqual(3);
        await page.mouse.move(0, 0);

        await expectNothingBelowTheBar(page, 'consents', '.gratora-donor-portal');
        await consents();
    });
});

test('the measuring agrees with WCAG', () => {
    expect(contrast([255, 255, 255, 1], [0, 0, 0, 1])).toBeCloseTo(21, 5);
    expect(contrast([107, 114, 128, 1], [255, 255, 255, 1])).toBeCloseTo(4.83, 2);
});
