import { test, expect, P2P } from '../../fixtures/p2p';

/**
 * The public team page: the team identity card with its captain and rollup, the
 * member roster, and the donation form carrying the signed team attribution
 * context. The page is a core-block layout around the plugin's own sections, so
 * the headings and copy between them belong to the organiser, not to us - these
 * assert the sections and the text a visitor actually reads.
 */
test.describe('P2P team page', () => {
    test('renders the team hero with captain and progress', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.teamPath);

        const hero = healthyPage.locator('.dono-p2p-team').first();
        await expect(hero).toBeVisible();
        await expect(hero.getByText('Team fundraiser')).toBeVisible();
        await expect(hero.getByRole('heading', { level: 1 })).toHaveText(P2P.teamName);
        await expect(hero.locator('.dp-profile__meta')).toContainText('Captained by Cara Captain');

        const teamAmount = hero.locator('.dp-profile__amount');
        await expect(teamAmount).toBeVisible();
        await expect(teamAmount).toContainText('raised');
        const bar = hero.getByRole('progressbar');
        await expect(bar).toBeVisible();
        await expect(bar.locator('i')).toBeVisible();
    });

    test('lists the team members', async ({ healthyPage }) => {
        await healthyPage.goto(P2P.teamPath);

        const roster = healthyPage.locator('section.dono-p2p-roster');
        await expect(roster).toBeVisible();

        const rows = roster.locator('.dp-row');
        expect(await rows.count()).toBeGreaterThanOrEqual(2);

        const captain = rows.filter({ hasText: 'Cara Captain' }).first();
        await expect(captain).toBeVisible();
        // The roster row carries the captain marking: "Captain, N donors".
        await expect(captain.locator('.dp-row-sub')).toContainText('Captain');
        await expect(rows.filter({ hasText: 'Joe Member' }).first()).toBeVisible();
    });

    test('embeds the donation form with team attribution context', async ({ healthyPage }) => {
        // The signed context is injected server-side into dono.form.config; the
        // form runtime consumes (and removes) the inline JSON on mount, so assert
        // against the raw SSR response rather than the live DOM.
        const res = await healthyPage.request.get(P2P.teamPath);
        expect(res.ok()).toBeTruthy();

        const html = await res.text();
        const signed = /"fundraiser_ctx":"([^"]+)"/.exec(html)?.[1];
        expect(signed, 'the team page injects a signed attribution context').toBeTruthy();

        // FundraiserContext::toSigned() is base64({"f":id,"t":id}) + '.' + hmac;
        // a team page attributes to the team with no individual fundraiser.
        const payload = signed!.replace(/\\\//g, '/').split('.')[0];
        const claim = JSON.parse(Buffer.from(payload, 'base64').toString('utf8'));
        expect(claim.t).toBeGreaterThan(0);
        expect(claim.f).toBe(0);

        await healthyPage.goto(P2P.teamPath);
        const form = healthyPage.locator('form.dono-donation-form').first();
        // The form is cloaked until the runtime mounts and sets data-dono-ready.
        await expect(form).toHaveAttribute('data-dono-ready', 'true', { timeout: 10_000 });
        await expect(form).toBeVisible();
    });
});
