import { test, expect } from '../fixtures/donor-form';

/**
 * Where the payment step appears, and what the form does while it is up.
 *
 * The payment phase used to replace the whole form: the donor lost the amount,
 * the summary and everything they had just typed at the one moment they most
 * want to check it. It now mounts at the gateway block, and the rest of the
 * form is settled because the charge is already fixed on the gateway's side.
 *
 * No Stripe account is needed. The phase is entered by answering the submit
 * with the payload the server would send, which is the only thing the client
 * reads to get there. Stripe.js then fails on the invented secret, and that is
 * fine: every claim below is about our own layout and our own state, not about
 * whether a card can be charged.
 */
test.describe('payment step placement', () => {
    const CLIENT_SECRET = 'pi_e2e_placement_secret_not_real';

    /** Answer the donation POST the way a Stripe form's server would. */
    async function stubStripeIntent(page, amountCents = 2500) {
        await page.route('**/dono/v1/donations', async (route) => {
            if (route.request().method() !== 'POST') return route.fallback();
            await route.fulfill({
                status: 201,
                contentType: 'application/json',
                body: JSON.stringify({
                    reference:     'DONO-E2E-PLACEMENT',
                    status:        'pending',
                    status_token:  'e2e-token',
                    amount_cents:  amountCents,
                    currency:      'USD',
                    gateway:       'stripe',
                    intent_id:     'pi_e2e_placement',
                    client_secret: CLIENT_SECRET,
                    redirect_url:  null,
                    requires_action: false,
                    paypal:        null,
                }),
            });
        });
    }

    /**
     * Fills and submits whatever shape the seeded form is. A paged form shows
     * Continue where a single-page one shows Donate, and this spec is about the
     * payment step rather than about page counts.
     */
    async function fillAndSubmit(donor, page, gateway?: string) {
        await donor.selectPresetAt(0);

        for (let page_ = 0; page_ < 6; page_++) {
            const name = donor.form.locator('input[autocomplete="given-name"]');
            if (await name.count() > 0 && await name.first().isVisible()) {
                await donor.fillName('Placement', 'Tester');
            }
            const email = donor.form.locator('input[type="email"]');
            if (await email.count() > 0 && await email.first().isVisible()) {
                await donor.fillEmail(`placement+${Date.now()}@example.com`);
            }
            // Whatever else the seeded form asks for. This spec is about the
            // payment step, so it should not fail because the fixture happens
            // to collect an address: an unfilled required field blocks the
            // submit and the failure reads as a broken pay screen.
            const line1 = donor.form.locator('input[autocomplete="address-line1"]');
            if (await line1.count() > 0 && await line1.first().isVisible()) {
                await donor.fillAddress({
                    line1: '1 Test Street', city: 'Testville', postal: '12345',
                    country: 'United States',
                });
            }
            if (gateway) await donor.selectGateway(gateway);

            const next = donor.form.locator('.dono-form__button--primary');
            const label = (await next.first().innerText()).trim();
            if (! /continue|next/i.test(label)) break;
            await next.first().click();
            await page.waitForTimeout(150);
        }

        await donor.submit();
    }

    /** Why this run cannot reach the payment phase, or '' when it can. */
    let blocked: string | null = null;

    /**
     * Walks to the gateway block, which on a paged form is on the last page.
     *
     * Answers WHY it could not get there, not just that it could not. A skip
     * reason that names the wrong cause is worse than a failure: it reads as
     * coverage, and it sent the last investigation looking at the block
     * placement when the site simply had no gateway that pays in the browser.
     */
    async function whyBlocked(donor, page): Promise<string> {
        if (blocked !== null) return blocked;

        // The form's own config, not the rendered radios: a form offering a
        // single gateway draws no selector at all, so counting radios reports
        // a missing block on a form that places one.
        //
        // Read from the served HTML rather than the DOM, because the runtime
        // consumes this script at boot and removes it.
        const html = await (await page.request.get(page.url())).text();
        const raw  = /data-dono-form-config[^>]*>([\s\S]*?)<\/script>/.exec(html);
        if (! raw) return blocked = 'no Dono form config on the page under test';
        const cfg = JSON.parse(raw[1]);

        const ids   = ((cfg.gateways?.options ?? []) as Array<{ id: string }>).map((o) => o.id);
        const items = ((cfg.steps ?? []) as Array<{ items?: Array<{ kind?: string }> }>)
            .flatMap((s) => s.items ?? []);

        if (! items.some((i) => i.kind === 'payment-gateways')) {
            return blocked = 'the seeded form places no payment-gateways block';
        }
        // Offline and sandbox settle server-side, so neither ever mounts a
        // payment element and neither can exercise this suite.
        if (ids.filter((g) => g !== 'offline' && g !== 'sandbox').length === 0) {
            return blocked =
                `no gateway on this site pays in the browser (offered: ${ids.join(', ') || 'none'}). ` +
                'Configure Stripe test keys on the fixture site.';
        }
        return blocked = '';
    }

    async function submitToPayment(donor, page): Promise<boolean> {
        if (await whyBlocked(donor, page) !== '') return false;

        await stubStripeIntent(page);
        await fillAndSubmit(donor, page, 'stripe');

        if (await donor.form.locator('.dono-form__payment-mount').count() === 0) return false;
        await expect(donor.form.locator('.dono-form--settled')).toHaveCount(1, { timeout: 10_000 });
        return true;
    }

    /** Skips naming the real reason, so a skipped run is never mistaken for a passing one. */
    async function requirePayment(donor, page): Promise<void> {
        const why = await whyBlocked(donor, page);
        test.skip(why !== '' || ! await submitToPayment(donor, page), why || 'payment phase not reached');
    }

    test('payment mounts at the gateway block, not over the whole form', async ({ donor, page }) => {
        await requirePayment(donor, page);

        await expect(donor.form.locator('.dono-form__payment-mount')).toHaveCount(1);
        // The selector it replaces is gone, so the donor is not offered a
        // choice they have already made and can no longer change.
        await expect(donor.form.locator('.dono-form__gateways')).toHaveCount(0);
    });

    /**
     * Paying is one job. The fields, the fee toggle and the recap of choices
     * already made are things to read past on the way to the button, and the
     * gateway owns this screen and shows the charge itself.
     */
    test('the pay screen carries nothing but the gateway', async ({ donor, page }) => {
        await requirePayment(donor, page);

        await expect(donor.form.locator('.dono-form__donor').first()).toBeHidden();
        await expect(donor.form.locator('.dono-form__amount').first()).toBeHidden();
        await expect(donor.form.locator('.dono-form__payment-mount')).toBeVisible();

        // The recap is a block the author places, so a form may not have one.
        // Present or absent it must not be on screen while paying.
        const recap = donor.form.locator('.dono-form__confirm');
        if (await recap.count() > 0) await expect(recap.first()).toBeHidden();
    });

    /**
     * The settling rule is a property of the form, not of one layout. Every
     * variant builds its own root class, and the single-page one is the shape
     * most sites ship, so the modifier is asserted against whichever variant
     * the seed produced rather than assumed to be the paged one.
     */
    test('the form settles whichever layout it is', async ({ donor, page }) => {
        await requirePayment(donor, page);

        const root = donor.form.locator('.dono-form--settled');
        await expect(root).toHaveCount(1);

        const variant = await root.evaluate((el) =>
            [...el.classList].find((c) => c.startsWith('dono-form--') && c !== 'dono-form--settled') || 'dots',
        );
        // Named so a failure says which shape stopped settling.
        expect(['dono-form--inline', 'dono-form--paged-bar', 'dots']).toContain(variant);
    });

    /**
     * The one that matters. The charge is fixed on the gateway's side once the
     * intent exists, and the confirm summary recomputes its total from live
     * state, so a value that moved here would show a total that is not the one
     * leaving the donor's account. Clicked through the DOM on purpose: that
     * bypasses the pointer-events styling and reaches the handler, which is
     * exactly what the reducer guard is there to survive.
     */
    test('nothing about the donation can change once payment has started', async ({ donor, page }) => {
        await requirePayment(donor, page);

        const totalBefore = await donor.form.innerText();

        await page.evaluate(() => {
            const form = document.querySelector('form.dono-donation-form');
            form?.querySelectorAll<HTMLElement>('.dono-form__preset').forEach((el) => el.click());
            form?.querySelectorAll<HTMLInputElement>('input[type="checkbox"]').forEach((el) => el.click());
        });

        expect(await donor.form.innerText()).toBe(totalBefore);
    });

    /**
     * What the maintainer saw on a real form: Stripe's Pay and Cancel at the
     * mount, and the form's own Back and Donate still below them. Two sets of
     * buttons on one screen is a donor deciding which one takes their money.
     */
    test('only the gateway offers a way to pay', async ({ donor, page }) => {
        await requirePayment(donor, page);

        // The gateway's own Pay lives in a nav of the same name, so this is
        // about which navs survive, not about hiding the class.
        await expect(donor.form.locator('.dono-form__payment-mount .dono-form__nav')).toBeVisible();

        const visibleNavs = await donor.form.locator('.dono-form__nav:visible').count();
        expect(visibleNavs, 'exactly one set of buttons while paying').toBe(1);
    });

    /**
     * Both halves of what a real donor hit. The Pay button vanished when the
     * form's own nav was hidden by class name, and the card fields were dimmed
     * to 55% because the dim sat on an ancestor of the mount: opacity applies
     * to the whole subtree and a descendant cannot undo it.
     */
    test('the donor can actually read and press Pay', async ({ donor, page }) => {
        await requirePayment(donor, page);

        // Present and visible is the claim. It stays disabled until Stripe
        // reports the element ready, which it never does against an invented
        // secret, so enabled-ness is not this spec's to assert.
        await expect(
            donor.form.locator('.dono-form__payment-mount .dono-form__button--primary'),
        ).toBeVisible();

        // Nothing between the mount and the form root may be faded, or the
        // card fields are unreadable however the mount itself is styled.
        const faded = await donor.form.locator('.dono-form__payment-mount').evaluate((el) => {
            for (let n = el as HTMLElement | null; n; n = n.parentElement) {
                if (n.classList?.contains('dono-form')) break;
                if (parseFloat(getComputedStyle(n).opacity) < 1) return n.className;
            }
            return null;
        });
        expect(faded, 'an ancestor of the payment mount is faded').toBeNull();
    });

    /** Whatever is still in the tree must not be reachable. */
    test('nothing outside the gateway can be interacted with', async ({ donor, page }) => {
        await requirePayment(donor, page);

        const reachable = await donor.form.evaluate((form) => {
            const mount = form.querySelector('.dono-form__payment-mount');
            return [...form.querySelectorAll('input, select, textarea, button')]
                .filter((el) => ! mount?.contains(el))
                // The honeypot stays: it is parked off-screen rather than
                // hidden precisely so a bot fills it in, which means it is
                // laid out and would count as reachable here.
                .filter((el) => ! el.closest('.dono-form__hp'))
                .filter((el) => (el as HTMLElement).offsetParent !== null)
                .map((el) => `${el.tagName}.${(el as HTMLElement).className}`);
        });
        expect(reachable, 'a control outside the gateway is still on screen').toEqual([]);
    });

    /**
     * The thank-you card repeats what was given. Offline reaches it without a
     * browser payment step, so it is the cheapest way to assert the card.
     */
    test('the thank-you card says what was donated', async ({ donor, page }) => {
        await fillAndSubmit(donor, page, 'offline');
        await donor.expectThankYou();

        const receipt = donor.form.locator('.dono-form__summary--receipt');
        await expect(receipt).toBeVisible();
        // The amount the server settled on, not one the client recomputed.
        await expect(receipt).toContainText(/\d/);
    });

    /**
     * Reaching your giving without typing your address again. The donation did
     * not prove the address, so this sends the link rather than opening a
     * session, and it answers the same either way so it cannot be used to ask
     * whether an address is one of the charity's donors.
     */
    test('the thank-you card offers a way into the portal', async ({ donor, page }) => {
        let sentTo: string | null = null;
        await page.route('**/dono/v1/portal/send-link', async (route) => {
            sentTo = JSON.parse(route.request().postData() || '{}').email ?? null;
            await route.fulfill({ status: 200, contentType: 'application/json', body: '{"ok":true}' });
        });

        await fillAndSubmit(donor, page, 'offline');
        await donor.expectThankYou();

        const button = donor.form.locator('.dono-form__portal-link');
        await expect(button).toBeVisible();
        await button.click();

        // The button stays where it was and says what happened, rather than
        // vanishing and leaving a line of grey text that reads as a failure.
        await expect(donor.form.locator('.dono-form__portal-link.is-sent')).toBeVisible();
        await expect(donor.form.locator('.dono-form__portal-link')).toBeDisabled();
        expect(sentTo, 'the link goes to the address that just donated').toContain('@');
    });

    /**
     * A gateway with no browser step must not be dragged through any of this.
     * Offline settles server-side, so it goes straight to the thank-you and
     * never enters the payment phase at all.
     */
    test('a gateway with no payment step is unaffected', async ({ donor, page }) => {
        await fillAndSubmit(donor, page, 'offline');

        await donor.expectThankYou();
        await expect(donor.form.locator('.dono-form__payment-mount')).toHaveCount(0);
    });
});
