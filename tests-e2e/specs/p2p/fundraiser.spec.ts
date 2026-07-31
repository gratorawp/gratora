import { test, expect, P2P } from '../../fixtures/p2p';

/**
 * The public fundraiser page: the identity card with its money readout, the
 * share control, and the donation form with the signed fundraiser attribution
 * context injected into its config so a donation here is credited to the
 * fundraiser.
 */
test.describe('P2P fundraiser page', () => {
    test('renders the hero with the fundraiser name and progress', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.fundraiserPath);

        // The page renders the hero block twice (header band, then the badge
        // band inside the column layout); the identity card is in the first.
        const hero = healthyPage.locator('.dono-p2p-fundraiser').first();
        await expect(hero).toBeVisible();

        const profile = hero.locator('.dp-profile');
        // The eyebrow is what tells a reader this is a person's page rather
        // than a team's ("Team fundraiser").
        await expect(profile.locator('.dp-label')).toHaveText('Fundraiser');
        await expect(
            profile.getByRole('heading', { level: 1, name: P2P.fundraiserName }),
        ).toBeVisible();

        // The money readout: amount raised, the goal line it is measured
        // against, and the bar that only renders once a goal is set.
        const money = profile.locator('.dp-profile__money');
        const amount = money.locator('.dp-profile__amount');
        await expect(amount).toBeVisible();
        // A real total, not the zero a broken aggregate would leave behind.
        await expect(amount).toHaveText(/[1-9]/);
        await expect(money.locator('.dp-profile__goal')).toContainText('goal');

        const track = money.getByRole('progressbar');
        await expect(track).toBeVisible();
        await expect(track).toHaveAttribute('aria-valuenow', /^[1-9]\d*$/);
        await expect(track.locator('i')).toBeVisible();

        // The counts under the bar: each chip is a value plus its label.
        await expect(money.locator('.dp-profile__stats')).toBeVisible();
        const stats = money.locator('.dp-profile__stat');
        await expect(stats.filter({ hasText: 'Donors' })).toHaveCount(1);
        await expect(stats.filter({ hasText: 'Donations' })).toHaveCount(1);

        // Both calls to action: give here, or go start your own page.
        const actions = profile.locator('.dp-profile__actions');
        await expect(actions.getByRole('link', { name: 'Donate' })).toBeVisible();
        const startOwn = actions.getByRole('link', { name: 'Start your own page' });
        await expect(startOwn).toBeVisible();
        await expect(startOwn).toHaveAttribute('href', /\/start\/?$/);
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

    test('offers share targets', async ({ healthyPage }) => {
        // The row of brand buttons collapsed into one control. Where the Web
        // Share API exists the runtime hands off to the OS sheet and there is
        // nothing in the DOM to assert; everywhere else it opens its own
        // popover of targets. Pin the popover branch so the targets are
        // reachable, then check they are the ones the button advertises.
        await healthyPage.addInitScript(() => {
            Object.defineProperty(window.navigator, 'share', {
                value: undefined,
                configurable: true,
            });
        });
        await healthyPage.goto(P2P.fundraiserPath);

        const share = healthyPage.locator('.dp-profile__actions button[data-dono-share]');
        await expect(share).toBeVisible();
        await expect(share).toHaveAttribute('data-share-url', new RegExp(P2P.fundraiserPath));
        await expect(share).toHaveAttribute('data-share-facebook', /facebook\.com\/sharer/);
        await expect(share).toHaveAttribute('data-share-x', /(twitter|x)\.com\/intent/);

        await share.click();
        const sheet = healthyPage.locator('.dp-sharesheet');
        await expect(sheet.getByRole('link', { name: 'Facebook' })).toBeVisible();
        await expect(sheet.getByRole('link', { name: 'X', exact: true })).toBeVisible();
    });
});
