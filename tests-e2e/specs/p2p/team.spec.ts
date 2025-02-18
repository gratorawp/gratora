import { test, expect, P2P } from '../../fixtures/p2p';

/**
 * The public team page: team hero with captain + rollup, the member roster, and
 * the donation form carrying the signed team attribution context.
 */
test.describe('P2P team page', () => {
    test('renders the team hero with captain and progress', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.teamPath);

        const hero = healthyPage.locator('.dono-p2p-team').first();
        await expect(hero).toBeVisible();
        await expect(hero.locator('.dono-p2p-fundraiser__name')).toContainText(P2P.teamName);
        await expect(healthyPage.locator('.dono-p2p-team__captain')).toContainText('Cara');
        await expect(healthyPage.locator('.dono-p2p-fundraiser__bar-fill')).toBeVisible();
    });

    test('lists the team members', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.teamPath);

        const cards = healthyPage.locator('.dono-p2p-fcard');
        expect(await cards.count()).toBeGreaterThanOrEqual(2);
        await expect(cards.filter({ hasText: 'Cara Captain' }).first()).toBeVisible();
        await expect(cards.filter({ hasText: 'Joe Member' }).first()).toBeVisible();
    });

    test('embeds the donation form with team attribution context', async ({ healthyPage }) => {
        const res = await healthyPage.request.get(P2P.teamPath);
        expect(res.ok()).toBeTruthy();
        expect(await res.text()).toContain('fundraiser_ctx');

        await healthyPage.goto(P2P.teamPath);
        await expect(healthyPage.locator('form.dono-donation-form').first()).toBeVisible();
    });
});
