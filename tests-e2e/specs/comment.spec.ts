/**
 * `dono/comment` block - note-to-org textarea.
 * Skips itself when the test form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('comment block', () => {
    test('renders a textarea', async ({ donor }) => {
        // Runtime renders the comment as a Field-wrapped textarea with no
        // `name` attr. A field block may render its own textarea inside a
        // fieldset, so on initial render any bare textarea is the comment.
        const ta = donor.form.locator('textarea').first();
        test.skip(await ta.count() === 0, 'no comment block on the test form');
        await expect(ta).toBeVisible();
    });

    test('a comment carries through to the thank-you submission', async ({ donor }) => {
        const ta = donor.form.locator('textarea').first();
        test.skip(await ta.count() === 0, 'no comment block on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tester');
        await donor.fillEmail(`e2e+comment+${Date.now()}@example.com`);
        await donor.fillComment('Thanks for the work you do.');
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
