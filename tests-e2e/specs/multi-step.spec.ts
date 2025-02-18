/**
 * Multi-step wizard: validates that pages advance only when their fields
 * validate, that values persist across Back/Next, that the step indicator
 * tracks the current page, and that a full pass through every page reaches
 * thank-you.
 *
 * Requires a separate multi-step form whose path lives in
 * `DONO_E2E_MULTI_STEP_FORM_PATH`. The seed (`wp dono e2e-seed`) creates one
 * with these pages:
 *   step 1: dono/donation-amount (presets, required)
 *   step 2: dono/name + dono/email (both required)
 *   step 3: dono/payment-gateways + dono/submit-button
 *
 * Skipped when the env var is not set so single-page setups stay green.
 */

import { test, expect } from '../fixtures/donor-form';

const MULTI_STEP_FORM_PATH = process.env.DONO_E2E_MULTI_STEP_FORM_PATH ?? '';

test.describe('multi-step wizard', () => {
    test.skip(! MULTI_STEP_FORM_PATH, 'set DONO_E2E_MULTI_STEP_FORM_PATH via `wp dono e2e-seed`');
    test.use({ formPath: MULTI_STEP_FORM_PATH });

    test('donor step renders fields after continuing from amount', async ({ donor }) => {
        // Regression for the setField-out-of-scope bug (commit 7d6c64b): the
        // donor step's wrapper existed but its field subtree was swallowed by
        // ErrorBoundary, leaving zero controls inside.
        await donor.selectPresetAt(0);
        await donor.form.locator('.dono-form__button--primary').click();

        const donorStep = donor.form.locator('[data-step="donor"]');
        await expect(donorStep).toBeVisible();

        const controls = donorStep.locator('input:not([type="hidden"]), select, textarea, [role="combobox"]');
        await expect(controls.first()).toBeVisible({ timeout: 5_000 });
        const count = await controls.count();
        expect(count, 'donor step rendered at least one form control').toBeGreaterThan(0);
    });

    test('Next does not advance when the amount is cleared to zero', async ({ donor }) => {
        // The form auto-selects the first preset on mount, so the donor starts
        // with a valid amount. To exercise the validation path we explicitly
        // zero out the amount via the custom input.
        await donor.fillCustomAmount(0);

        const firstDot = donor.form.locator('.dono-form__progress-dot').nth(0);
        await expect(firstDot).toHaveClass(/is-current/);

        await donor.form.locator('.dono-form__button--primary').click();

        // Still on step 1: first dot still current, donor step absent.
        await expect(firstDot).toHaveClass(/is-current/);
        await expect(donor.form.locator('[data-step="donor"]')).toHaveCount(0);
    });

    test('Back navigation preserves filled values across steps', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.form.locator('.dono-form__button--primary').click();

        // On step 2; fill name + email.
        await donor.fillName('Backward', 'Compatible');
        await donor.fillEmail(`e2e+back+${Date.now()}@example.com`);

        // Back to step 1.
        await donor.form.locator('.dono-form__button--secondary').click();
        const firstDot = donor.form.locator('.dono-form__progress-dot').nth(0);
        await expect(firstDot).toHaveClass(/is-current/);
        // Preset 0 is still the selected one.
        await expect(donor.form.locator('.dono-form__preset').nth(0)).toHaveClass(/is-selected/);

        // Forward again - the name/email values are still in place.
        await donor.form.locator('.dono-form__button--primary').click();
        await expect(donor.form.locator('input[autocomplete="given-name"]').first()).toHaveValue('Backward');
        await expect(donor.form.locator('input[autocomplete="family-name"]').first()).toHaveValue('Compatible');
    });

    test('step indicator labels match the seeded step titles', async ({ donor }) => {
        // The seeded wizard uses page titles "Your donation", "Your info",
        // "Confirm" - ProgressBar puts each title onto the dot's aria-label.
        const dots = donor.form.locator('.dono-form__progress-dot');
        await expect(dots.nth(0)).toHaveAttribute('aria-label', 'Your donation');
        await expect(dots.nth(1)).toHaveAttribute('aria-label', 'Your info');
        await expect(dots.nth(2)).toHaveAttribute('aria-label', 'Confirm');

        // The first dot is current; the others aren't.
        await expect(dots.nth(0)).toHaveClass(/is-current/);
        await expect(dots.nth(1)).not.toHaveClass(/is-current/);
    });

    test('current-step dot advances after a successful Next', async ({ donor }) => {
        const dots = donor.form.locator('.dono-form__progress-dot');
        await expect(dots.nth(0)).toHaveClass(/is-current/);

        await donor.selectPresetAt(0);
        await donor.form.locator('.dono-form__button--primary').click();

        await expect(dots.nth(0)).toHaveClass(/is-done/);
        await expect(dots.nth(1)).toHaveClass(/is-current/);
    });

    test('a missing required field on step 2 blocks the move to step 3', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.form.locator('.dono-form__button--primary').click();
        // On donor step; leave name + email blank, try to advance.
        await donor.form.locator('.dono-form__button--primary').click();

        // Still on the donor step: second dot still current, donor step
        // wrapper still visible, no gateway radios from step 3.
        await expect(donor.form.locator('.dono-form__progress-dot').nth(1)).toHaveClass(/is-current/);
        await expect(donor.form.locator('[data-step="donor"]')).toBeVisible();
        await expect(donor.gatewayOptions()).toHaveCount(0);
        // A field error has surfaced.
        await expect(
            donor.form.locator('.dono-form__field-error').filter({ hasText: /\S/ }).first()
        ).toBeVisible({ timeout: 5_000 });
    });

    test('a full pass through all steps reaches thank-you', async ({ donor }) => {
        await donor.selectPresetAt(0);
        await donor.form.locator('.dono-form__button--primary').click();

        await donor.fillName('Multi', 'Step');
        await donor.fillEmail(`e2e+multi+${Date.now()}@example.com`);
        await donor.form.locator('.dono-form__button--primary').click();

        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});

test.describe('multi-step wizard health', () => {
    test.skip(! MULTI_STEP_FORM_PATH, 'set DONO_E2E_MULTI_STEP_FORM_PATH via `wp dono e2e-seed`');
    test.use({ formPath: MULTI_STEP_FORM_PATH });

    test('no render-error console output on the donor step', async ({ donor }) => {
        // Render-health offences are checked by the fixture teardown; this
        // test exercises the same regression path (continue from amount ->
        // donor step) for explicit redundancy with the original 7d6c64b case.
        await donor.selectPresetAt(0);
        await donor.form.locator('.dono-form__button--primary').click();
        await donor.form.locator('[data-step="donor"]').waitFor({ state: 'visible' });
        await donor.page.waitForTimeout(500);
    });
});
