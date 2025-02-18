import { test, expect, P2P } from '../../fixtures/p2p';
import { DonorFormPage } from '../../helpers/DonorFormPage';

/**
 * The donation form embedded on a fundraiser page must accept a real donation
 * end-to-end: the signed fundraiser context is injected server-side (asserted in
 * fundraiser.spec.ts), and a sandbox donation submitted here runs the whole
 * pipeline (intent -> attribution -> confirm -> success). The downstream credit
 * to the fundraiser/team aggregates is covered by the PHP AttributionSyncTest /
 * FundraiserAttributionTest; sandbox donations are test money and stay out of
 * the live thermometer, so this asserts the donor-facing success.
 */
test.describe('P2P fundraiser donation', () => {
    test('a sandbox donation on a fundraiser page completes', async ({ healthyPage }) => {
        const donor = new DonorFormPage(healthyPage);
        await donor.open(P2P.fundraiserPath);

        await donor.selectPresetAt(0);
        await donor.fillName('Gift', 'Giver');
        await donor.fillEmail(`e2e-p2p-donate-${Date.now()}@dono.test`);
        await donor.selectGateway('sandbox');
        await donor.submit();

        await donor.expectThankYou();
    });
});
