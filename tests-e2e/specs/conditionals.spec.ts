/**
 * Nonmatching conditional blocks have no DOM node; assert count() === 0. Hidden required fields
 * must not block submission.
 */

import { test, expect } from '../fixtures/donor-form';

const CONDITIONAL_FORM_PATH = process.env.GRATORA_E2E_CONDITIONAL_FORM_PATH ?? '';

test.describe('conditional logic', () => {
    test.skip(! CONDITIONAL_FORM_PATH, 'set GRATORA_E2E_CONDITIONAL_FORM_PATH via `wp --require=tests-e2e/cli/E2eSeedCommand.php gratora e2e-seed`');
    test.use({ formPath: CONDITIONAL_FORM_PATH });

    test('conditionally-shown heading is absent until the trigger matches', async ({ donor }) => {
        const heading = donor.form.locator('h3', { hasText: 'CONDITIONAL_HEADING_SOCIAL' });

        // Default render: trigger has no value, condition `=social` fails, no DOM node.
        await expect(heading).toHaveCount(0);

        // Pick "social" - the trigger dropdown is the only `select` on the form.
        await donor.form.locator('select').selectOption('social');
        await expect(heading).toBeVisible();
    });

    test('changing the trigger away from the match hides the block again', async ({ donor }) => {
        const heading = donor.form.locator('h3', { hasText: 'CONDITIONAL_HEADING_SOCIAL' });
        const select  = donor.form.locator('select');

        await select.selectOption('social');
        await expect(heading).toBeVisible();

        await select.selectOption('event');
        // `=social` no longer matches; the heading drops out of the DOM.
        await expect(heading).toHaveCount(0);
    });

    test('!= operator: the comment field appears once any choice is made', async ({ donor }) => {
        const conditionalComment = donor.form
            .locator('.gratora-form__field')
            .filter({ hasText: 'CONDITIONAL_COMMENT_ANY' });

        // Default (empty): `!=''` is false, hidden.
        await expect(conditionalComment).toHaveCount(0);

        // Any non-empty selection: shown.
        await donor.form.locator('select').selectOption('event');
        await expect(conditionalComment).toBeVisible();

        // The textarea inside the now-visible field is interactable.
        await conditionalComment.locator('textarea').fill('Heard about you at a charity gala.');
    });

    test('a required field hidden by its condition does not block submit', async ({ donor }) => {
        // The seeded form's friend-referrer text input is marked required AND
        // has a condition `cond_trigger = friend`. With trigger set to anything
        // else, the required field is gone from the DOM and submit must reach
        // thank-you.
        const friendField = donor.form
            .locator('.gratora-form__field')
            .filter({ hasText: 'How did your friend hear about us?' });

        await donor.selectPresetAt(0);
        await donor.fillName('Cond', 'Hide');
        await donor.fillEmail(`e2e+cond+hide+${Date.now()}@example.com`);

        // Pick "social" - the required friend-referrer text input is hidden.
        await donor.form.locator('select').selectOption('social');
        await expect(friendField).toHaveCount(0);

        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('a now-visible required field DOES block submit when empty', async ({ donor }) => {
        // Flip side of the previous test: with the condition met, the required
        // field is in the DOM and validation must catch the empty value.
        const friendField = donor.form
            .locator('.gratora-form__field')
            .filter({ hasText: 'How did your friend hear about us?' });

        await donor.selectPresetAt(0);
        await donor.fillName('Cond', 'Show');
        await donor.fillEmail(`e2e+cond+show+${Date.now()}@example.com`);

        await donor.form.locator('select').selectOption('friend');
        await expect(friendField).toBeVisible();

        await donor.selectGateway('offline');
        await donor.submit();

        // Submit must NOT have reached thank-you; the success card must stay
        // absent and a validation error must surface on the visible required
        // field.
        await expect(donor.successCard()).toHaveCount(0);
        await expect(
            donor.form.locator('.gratora-form__field-error').filter({ hasText: /\S/ }).first()
        ).toBeVisible({ timeout: 5_000 });
    });
});
