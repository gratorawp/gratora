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

        // The money readout: an amount raised, the goal line it is measured
        // against, and the bar that only renders once a goal is set.
        const raised = thermo.locator('.dp-raised');
        await expect(raised).toBeVisible();
        await expect(raised).toHaveText(/\d/);
        const goal = thermo.locator('.dp-goal');
        await expect(goal).toBeVisible();
        await expect(goal).toContainText('goal');
        await expect(thermo.locator('.dp-bar')).toBeVisible();

        // The counts beside the bar: each chip is a value plus its label.
        const stats = thermo.locator('.dp-stat');
        await expect(stats.filter({ hasText: 'Fundraisers' })).toHaveCount(1);
        await expect(stats.filter({ hasText: 'Donations' })).toHaveCount(1);
    });

    test('lists fundraisers and teams', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.campaignPath);

        // Each leaderboard row is one link to the entity's own page.
        const fundraiserRow = healthyPage.locator(
            `.dono-p2p-grid-section a.dp-row[href*="${P2P.fundraiserPath}"]`,
        );
        await expect(fundraiserRow).toHaveCount(1);
        await expect(fundraiserRow.locator('.dp-row-name')).toContainText(P2P.fundraiserName);
        await expect(fundraiserRow.locator('.dp-row-amt')).toHaveText(/\d/);

        const teamRow = healthyPage.locator(
            `.dono-p2p-grid-section a.dp-row[href*="${P2P.teamPath}"]`,
        );
        await expect(teamRow).toHaveCount(1);
        await expect(teamRow.locator('.dp-row-name')).toContainText(P2P.teamName);
        await expect(teamRow.locator('.dp-row-amt')).toHaveText(/\d/);
    });

    test('start CTA leads to the start page', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.campaignPath);

        // The layout also carries a core button block pointing at the same
        // place, so scope to the hero's own call to action.
        const cta = healthyPage
            .locator('.dono-p2p-thermo .dp-hero-top')
            .getByRole('link', { name: 'Start fundraising' });
        await expect(cta).toHaveAttribute('href', /\/start\/?$/);
        await cta.click();
        await expect(healthyPage.locator('[data-dono-start]')).toBeVisible();
    });
});
