import { type Locator, type Page } from '@playwright/test';

/**
 * Page object for the tribute field the dono-tributes add-on contributes to the
 * donation form. Add-on specific, like P2pStartPage: the shared DonorFormPage
 * knows only the fields core itself ships.
 */
export class TributeField {
    readonly page: Page;
    readonly form: Locator;

    constructor(page: Page, form: Locator) {
        this.page = page;
        this.form = form;
    }

    fieldset(): Locator {
        return this.form.locator('.dono-form__tribute').first();
    }

    /**
     * Pick a tribute type by id-keyword or full label text. Built-ins
     * ("honor", "memorial") match the canonical label substring; custom ids
     * (e.g. "celebrate") match the label registered for that id on the form.
     */
    async pick(kindOrLabel: string): Promise<void> {
        const builtin: Record<string, RegExp> = {
            honor:    /honor/i,
            memorial: /memor/i,
        };
        const matcher = builtin[kindOrLabel] ?? new RegExp(kindOrLabel, 'i');
        await this.fieldset().locator('label').filter({ hasText: matcher }).locator('input[type="radio"]').first().check();
    }

    async fillName(value: string): Promise<void> {
        await this.fieldset().locator('input[type="text"]').first().fill(value);
    }
}
