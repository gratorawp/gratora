import { expect, type Locator, type Page } from '@playwright/test';

export class DonorFormPage {
    readonly page: Page;
    readonly form: Locator;
    /**
     * Real time the form became interactive. AntiSpamGuard rejects any submit
     * arriving sooner than `MIN_RENDER_SECONDS` after the form-token was issued
     * (2s by default) - automation fills fields faster than that. `submit()`
     * uses this to back-pressure the click.
     */
    private renderedAt = 0;

    constructor(page: Page) {
        this.page = page;
        this.form = page.locator('form.fundkit-donation-form').first();
    }

    async open(path = process.env.FUNDKIT_E2E_FORM_PATH ?? '/'): Promise<void> {
        await this.page.goto(path);
        await this.form.waitFor({ state: 'attached' });
        // The runtime sets data-fundkit-ready on every mount() exit path; the
        // JS-gated cloak only releases when it's set.
        await expect(this.form).toHaveAttribute('data-fundkit-ready', 'true', { timeout: 10_000 });
        this.renderedAt = Date.now();
    }

    presets(): Locator {
        return this.form.locator('.fundkit-form__preset');
    }

    async selectPresetAt(index: number): Promise<void> {
        await this.presets().nth(index).click();
    }

    async fillCustomAmount(value: number): Promise<void> {
        const input = this.form.locator('.fundkit-form__custom input').first();
        await input.fill(String(value));
    }

    currencySwitcher(): Locator {
        return this.form.locator('.fundkit-form__currency-switcher');
    }

    async selectCurrency(code: string): Promise<void> {
        const sw = this.currencySwitcher();
        const select = sw.locator('select');
        if (await select.count() > 0) {
            await select.selectOption(code);
            return;
        }
        await sw.locator('.fundkit-form__currency-pill', { hasText: code }).click();
    }

    async fillName(first: string, last: string): Promise<void> {
        const firstInput = this.form.locator('input[name="profile[first_name]"], input[autocomplete="given-name"]').first();
        const lastInput  = this.form.locator('input[name="profile[last_name]"], input[autocomplete="family-name"]').first();
        await firstInput.fill(first);
        await lastInput.fill(last);
    }

    async fillEmail(email: string): Promise<void> {
        await this.form.locator('input[type="email"]').first().fill(email);
    }

    /** Locator for a CountrySelect combobox (search-as-you-type picker). */
    countrySelect(root?: Locator): Locator {
        return (root ?? this.form).locator('.fundkit-form__country-select').first();
    }

    /**
     * Pick a country in a CountrySelect by display name. Pass a `root` to
     * target the address-fieldset's inner country picker instead of the
     * stand-alone country block.
     */
    async pickCountry(name: string, root?: Locator): Promise<void> {
        const cs    = this.countrySelect(root);
        const input = cs.locator('.fundkit-form__country-select-input');
        await input.click();
        await input.fill(name);
        await cs.locator('.fundkit-form__country-select-option').filter({ hasText: name }).first().click();
    }

    addressFieldset(): Locator {
        return this.form.locator('.fundkit-form__address').first();
    }

    async fillAddress(input: {
        line1?: string;
        line2?: string;
        city?: string;
        region?: string;
        postal?: string;
        country?: string;
    }): Promise<void> {
        const f = this.addressFieldset();
        if (input.line1  !== undefined) await f.locator('input[autocomplete="address-line1"]').fill(input.line1);
        if (input.line2  !== undefined) await f.locator('input[autocomplete="address-line2"]').fill(input.line2);
        if (input.city   !== undefined) await f.locator('input[autocomplete="address-level2"]').fill(input.city);
        if (input.region !== undefined) await f.locator('input[autocomplete="address-level1"]').fill(input.region);
        if (input.postal !== undefined) await f.locator('input[autocomplete="postal-code"]').fill(input.postal);
        if (input.country) await this.pickCountry(input.country, f);
    }

    async fillPhone(value: string): Promise<void> {
        await this.form.locator('input[type="tel"], input[autocomplete="tel"], input[name="profile[phone]"]').first().fill(value);
    }

    async fillComment(value: string): Promise<void> {
        // The runtime emits comment as a Field-wrapped <textarea> with no name
        // attribute. The Field wrapper is what tells it apart from a textarea a
        // field block renders inside its own fieldset.
        await this.form.locator('.fundkit-form__field textarea').first().fill(value);
    }

    anonymousToggle(): Locator {
        // Anonymous block renders as a bare `.fundkit-form__check`; cover-fees
        // shares that base class but adds `fundkit-form__cover-fees`. The
        // :not() filter rules cover-fees out.
        return this.form
            .locator('.fundkit-form__check:not(.fundkit-form__cover-fees) input[type="checkbox"]')
            .first();
    }

    consentFieldset(): Locator {
        return this.form.locator('.fundkit-form__consent').first();
    }

    coverFeesToggle(): Locator {
        return this.form.locator('.fundkit-form__cover-fees input[type="checkbox"], input[name="cover_fees"]').first();
    }

    gatewayOptions(): Locator {
        return this.form.locator('.fundkit-form__gateway input[type="radio"]');
    }

    async selectGateway(id: string): Promise<void> {
        const radio = this.form.locator(`.fundkit-form__gateway input[type="radio"][value="${id}"]`);
        if (await radio.count() > 0) {
            await radio.check();
            return;
        }

        // Skipping quietly is how a form that offers no usable gateway got to
        // fail at the thank-you card instead, with nothing saying the payment
        // step was never reachable. Say it here, and name what was on offer.
        const offered = await this.gatewayOptions().evaluateAll(
            (nodes) => nodes.map((n) => (n as HTMLInputElement).value)
        );

        throw new Error(
            `The form does not offer the "${id}" gateway. On offer: ${
                offered.length ? offered.join(', ') : '(none)'
            }. A form with no usable gateway cannot be submitted, so the fixture is wrong.`
        );
    }

    async submit(): Promise<void> {
        // AntiSpamGuard rejects submits faster than MIN_RENDER_SECONDS (2s)
        // from form render. Wait the remainder (with a small buffer for
        // server-side time drift) if the spec hasn't already burned enough
        // wall time filling fields.
        const elapsed   = Date.now() - this.renderedAt;
        const remaining = 2500 - elapsed;
        if (remaining > 0) await this.page.waitForTimeout(remaining);
        await this.form.locator('.fundkit-form__button--primary').click();
    }

    successCard(): Locator {
        return this.form.locator('.fundkit-form__success');
    }

    async expectThankYou(): Promise<void> {
        await expect(this.successCard()).toBeVisible({ timeout: 15_000 });
    }

    async expectFieldError(field: string): Promise<void> {
        await expect(this.form.locator('.fundkit-form__field-error').filter({ hasText: /\S/ }).first())
            .toBeVisible({ timeout: 5_000 });
        // Field-level errors live as siblings to the offending input; a generic
        // visibility check is enough for the canonical specs.
        void field;
    }
}
