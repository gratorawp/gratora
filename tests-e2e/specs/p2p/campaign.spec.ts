import { test, expect, P2P } from '../../fixtures/p2p';

/**
 * The peer-to-peer campaign landing page: the thermometer rollup, the
 * fundraiser + team grids, and the "start fundraising" call to action that
 * deep-links into the start page.
 */
test.describe('P2P campaign page', () => {
    test('renders the thermometer rollup', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.campaignPath);

        const thermo = healthyPage.locator('.dono-p2p-thermo').first();
        await expect(thermo).toBeVisible();
        await expect(thermo.locator('.dono-p2p-thermo__raised')).toBeVisible();
        await expect(thermo.locator('.dono-p2p-thermo__goal')).toBeVisible();
    });

    test('lists fundraisers and teams', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.campaignPath);

        await expect(
            healthyPage.locator('.dono-p2p-fcard').filter({ hasText: P2P.fundraiserName }).first(),
        ).toBeVisible();
        await expect(
            healthyPage.locator('.dono-p2p-tcard').filter({ hasText: P2P.teamName }).first(),
        ).toBeVisible();
    });

    test('start CTA leads to the start page', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.campaignPath);

        const cta = healthyPage.locator('.dono-p2p-thermo__btn--secondary').first();
        await expect(cta).toHaveAttribute('href', /\/start\/?$/);
        await cta.click();
        await expect(healthyPage.locator('[data-dono-start]')).toBeVisible();
    });
});
