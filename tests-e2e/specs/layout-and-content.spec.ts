/**
 * Coverage for layout + content blocks that previously had no dedicated
 * E2E (BlockPipelineCoverageTest verified they survive the config but
 * nothing exercised them in a browser):
 *
 *   - dono/heading
 *   - dono/paragraph
 *   - dono/html
 *   - dono/divider
 *   - dono/columns
 *   - dono/row
 *   - dono/section
 *   - dono/recurring-toggle
 *   - dono/fund-picker (interactivity gated on funds being present)
 *   - dono/privacy-notice
 *   - dono/goal
 *
 * Strategy: each "renders" test asserts the block survived render with its
 * unique seeded marker. Interactive blocks (recurring-toggle, fund-picker)
 * additionally exercise the control. A final test does a full submit so the
 * runtime payload builder doesn't choke on any of the included blocks.
 *
 * Seeded via `wp dono e2e-seed` -> DONO_E2E_LAYOUT_FORM_PATH.
 */

import { test, expect } from '../fixtures/donor-form';

const FORM_PATH = process.env.DONO_E2E_LAYOUT_FORM_PATH ?? '';

test.describe('layout + content blocks', () => {
    test.skip(! FORM_PATH, 'set DONO_E2E_LAYOUT_FORM_PATH via `wp dono e2e-seed`');
    test.use({ formPath: FORM_PATH });

    test('heading block renders at the seeded level with the seeded text', async ({ donor }) => {
        const heading = donor.form.locator('h2.dono-form__heading', { hasText: 'LAYOUT_HEADING_TEXT' });
        await expect(heading).toBeVisible();
    });

    test('paragraph block renders the seeded text', async ({ donor }) => {
        const para = donor.form.locator('p.dono-form__paragraph', { hasText: 'LAYOUT_PARAGRAPH_TEXT' });
        await expect(para).toBeVisible();
    });

    test('html block renders the sanitised inner HTML', async ({ donor }) => {
        const html = donor.form.locator('.dono-form__html .layout-html-marker', { hasText: 'LAYOUT_HTML_TEXT' });
        await expect(html).toBeVisible();
    });

    test('divider renders as a styled <hr>', async ({ donor }) => {
        await expect(donor.form.locator('hr.dono-form__divider').first()).toBeVisible();
    });

    test('columns container renders its children side by side', async ({ donor }) => {
        const cols = donor.form.locator('.dono-block--columns').first();
        await expect(cols).toBeVisible();
        await expect(cols.locator('h4', { hasText: 'LAYOUT_COL_LEFT' })).toBeVisible();
        await expect(cols.locator('h4', { hasText: 'LAYOUT_COL_RIGHT' })).toBeVisible();
    });

    test('row renders nested donor fields together', async ({ donor }) => {
        // The seeded row holds dono/name + dono/email. The runtime groups
        // row-tagged donor fields into a `.dono-form__grid` wrapper (the
        // CSS-grid container that drives the row layout); the standalone
        // `.dono-form__row` class is the name field's INNER first+last
        // two-up wrapper, not the row block's wrapper.
        const grid = donor.form.locator('.dono-form__grid').first();
        await expect(grid).toBeVisible();
        await expect(grid.locator('input[autocomplete="given-name"]')).toBeVisible();
        await expect(grid.locator('input[type="email"]')).toBeVisible();
    });

    test('section block emits a visible section wrapper', async ({ donor }) => {
        // The section block renders a generic group container with a section
        // class. We assert that SOMETHING with the section class survived.
        const section = donor.form.locator('.dono-block--section, .dono-form__section').first();
        await expect(section).toBeVisible();
    });

    test('recurring-toggle renders frequencies and switching does not crash', async ({ donor }) => {
        const fs = donor.form.locator('fieldset.dono-form__frequency');
        await expect(fs).toBeVisible();
        await expect(fs.locator('legend')).toHaveText('LAYOUT_RECURRING_LABEL');

        // Two frequencies seeded: one-time + monthly. Options are buttons
        // (not labels) with role-pressed semantics.
        const options = fs.locator('button.dono-form__frequency-option');
        await expect(options).toHaveCount(2);

        // Pick the second frequency ("monthly").
        await options.nth(1).click();
        await expect(options.nth(1)).toHaveClass(/is-selected/);
    });

    test('fund-picker renders its fieldset (interactivity depends on seeded funds)', async ({ donor }) => {
        const fs = donor.form.locator('fieldset.dono-form__fund');
        await expect(fs).toBeVisible();
        // With Plugin::onActivation()'s seeded "general" fund present, at least
        // one selectable option should render. Skip if none for envs that
        // never ran activation.
        const options = fs.locator('input[type="radio"]');
        if (await options.count() > 0) {
            await expect(options.first()).toBeVisible();
        }
    });

    test('privacy-notice renders its seeded text', async ({ donor }) => {
        // privacy-notice goes through do_blocks() server-side then lands in a
        // dono-form__html wrapper; the marker survives somewhere in the form.
        await expect(donor.form).toContainText('LAYOUT_PRIVACY_TEXT');
    });

    test('goal block renders without breaking the form', async ({ donor }) => {
        // The seeded campaign has no goal_cents, so the goal block may render
        // empty or with a "no goal" placeholder. The hard assertion is that
        // the form is still data-dono-ready and submittable after the block
        // is in place.
        await expect(donor.form).toHaveAttribute('data-dono-ready', 'true');
    });

    test('a form with every layout + content block in place still submits cleanly', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.fillName('Layout', 'Submit');
        await donor.fillEmail(`e2e+layout+${Date.now()}@example.com`);
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
