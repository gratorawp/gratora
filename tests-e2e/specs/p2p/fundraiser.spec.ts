import { test, expect, P2P } from '../../fixtures/p2p';

/**
 * The public fundraiser page: hero, progress, share rail, and the donation form
 * with the signed fundraiser attribution context injected into its config so a
 * donation on this page is credited to the fundraiser.
 */
test.describe('P2P fundraiser page', () => {
    test('renders the hero with the fundraiser name and progress', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.fundraiserPath);

        const hero = healthyPage.locator('.dono-p2p-fundraiser').first();
        await expect(hero).toBeVisible();
        await expect(hero.locator('.dono-p2p-fundraiser__name')).toContainText(P2P.fundraiserName);
        await expect(healthyPage.locator('.dono-p2p-fundraiser__bar-fill')).toBeVisible();
        await expect(hero.locator('.dono-p2p-fundraiser__stats')).toBeVisible();
    });

    test('embeds the donation form with attribution context', async ({ healthyPage }) => {
        // The signed context is injected server-side into dono.form.config; the
        // form runtime consumes (and removes) the inline JSON on mount, so assert
        // against the raw SSR response rather than the live DOM.
        const res = await healthyPage.request.get(P2P.fundraiserPath);
        expect(res.ok()).toBeTruthy();
        expect(await res.text()).toContain('fundraiser_ctx');

        await healthyPage.goto(P2P.fundraiserPath);
        await expect(healthyPage.locator('form.dono-donation-form').first()).toBeVisible();
    });

    test('offers share links', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.fundraiserPath);
        await expect(healthyPage.locator('.dono-p2p-share--facebook')).toBeVisible();
        await expect(healthyPage.locator('.dono-p2p-share--x')).toBeVisible();
    });
});
