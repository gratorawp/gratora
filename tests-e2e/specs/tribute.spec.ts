/**
 * `dono/tribute` block - "In honor of" / "In memory of" with name + optional
 * notify email / message / annual repeat. The sub-fields only render once
 * a tribute type radio is selected.
 * Skips itself when the test form lacks the block.
 */

import { test, expect } from '../fixtures/donor-form';

test.describe('tribute block', () => {
    test('renders the honor + memorial radios with no sub-fields until one is picked', async ({ donor }) => {
        const fs = donor.tributeFieldset();
        test.skip(await fs.count() === 0, 'no tribute block on the test form');

        await expect(fs).toBeVisible();
        const radios = fs.locator('input[type="radio"]');
        expect(await radios.count(), 'at least one tribute kind enabled').toBeGreaterThan(0);

        // Pre-selection: the name input only appears once a radio is checked.
        await expect(fs.locator('input[type="text"]')).toHaveCount(0);
    });

    test('picking a tribute kind reveals the name input', async ({ donor }) => {
        const fs = donor.tributeFieldset();
        test.skip(await fs.count() === 0, 'no tribute block on the test form');

        // Prefer "honor" but fall back to whichever radio is present.
        const honor    = fs.locator('label').filter({ hasText: /honor/i }).locator('input[type="radio"]');
        const memorial = fs.locator('label').filter({ hasText: /memor/i }).locator('input[type="radio"]');
        const picker   = (await honor.count()) > 0 ? honor.first() : memorial.first();
        await picker.check();

        await expect(fs.locator('input[type="text"]').first()).toBeVisible();
    });

    test('submitting with a tribute kind but no name surfaces a field error', async ({ donor }) => {
        const fs = donor.tributeFieldset();
        test.skip(await fs.count() === 0, 'no tribute block on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tribute');
        await donor.fillEmail(`e2e+tribute+${Date.now()}@example.com`);
        await donor.pickTribute('honor');
        // Intentionally leave the tribute name blank.
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectFieldError('tribute.name');
        await expect(donor.successCard()).toHaveCount(0);
    });

    test('a complete tribute submission reaches thank-you', async ({ donor }) => {
        const fs = donor.tributeFieldset();
        test.skip(await fs.count() === 0, 'no tribute block on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Tribute');
        await donor.fillEmail(`e2e+tribute+${Date.now()}@example.com`);
        await donor.pickTribute('honor');
        await donor.fillTributeName('Jane Honoree');
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });

    test('a custom tribute type id submits end-to-end', async ({ donor }) => {
        const fs = donor.tributeFieldset();
        test.skip(await fs.count() === 0, 'no tribute block on the test form');

        // The canonical e2e form registers a third type id "celebrate" with
        // the label "In celebration of" alongside the built-in honor/memorial
        // pair. Skips when that label isn't present so the spec stays useful
        // on forms that ship only the defaults.
        const customLabel = fs.locator('label').filter({ hasText: /celebration/i });
        test.skip(await customLabel.count() === 0, 'no custom tribute type on the test form');

        await donor.selectPresetAt(0);
        await donor.fillName('E2E', 'Custom');
        await donor.fillEmail(`e2e+tribute-custom+${Date.now()}@example.com`);
        await donor.pickTribute('celebration');
        await donor.fillTributeName('Anna Milestone');
        await donor.selectGateway('offline');
        await donor.submit();
        await donor.expectThankYou();
    });
});
