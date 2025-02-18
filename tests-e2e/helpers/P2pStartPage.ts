import { expect, type Locator, type Page } from '@playwright/test';
import { P2P } from '../fixtures/p2p';

/**
 * Page object for the public "start fundraising" page. Wraps the vanilla
 * interactivity from assets/p2p-start.js: the solo/join/create segmented
 * control + reveals, the searchable team combo, goal chips, the why-counter,
 * the FAQ accordion, and submit-time client validation.
 */
export class P2pStartPage {
    readonly page: Page;
    readonly form: Locator;

    constructor(page: Page) {
        this.page = page;
        this.form = page.locator('[data-dono-start]');
    }

    async open(path = P2P.startPath): Promise<void> {
        await this.page.goto(path);
        await this.form.waitFor({ state: 'visible' });
    }

    /* --- segmented control --- */
    segOption(choice: 'solo' | 'join' | 'create'): Locator {
        return this.page.locator(`.dps-seg__opt[data-choice="${choice}"]`);
    }
    reveal(choice: 'join' | 'create'): Locator {
        return this.page.locator(`[data-dps-reveal="${choice}"]`);
    }
    choiceValue(): Locator {
        return this.page.locator('[data-dps-choice-value]');
    }
    async chooseSegment(choice: 'solo' | 'join' | 'create'): Promise<void> {
        await this.segOption(choice).click();
    }

    /* --- team combo --- */
    combo(): Locator {
        return this.page.locator('[data-dps-combo]');
    }
    comboInput(): Locator {
        return this.combo().locator('.dps-combo__input');
    }
    comboMenu(): Locator {
        return this.combo().locator('.dps-combo__menu');
    }
    comboOptions(): Locator {
        return this.combo().locator('.dps-combo__opt');
    }
    teamIdValue(): Locator {
        return this.combo().locator('[data-dps-team-id]');
    }

    /* --- goal chips --- */
    chips(): Locator {
        return this.page.locator('.dps-chip');
    }
    goalInput(): Locator {
        return this.page.locator('#dps-goal');
    }

    /* --- why counter --- */
    whyInput(): Locator {
        return this.page.locator('#dps-why');
    }
    whyCount(): Locator {
        return this.page.locator('[data-dps-count]');
    }

    /* --- faq --- */
    accordions(): Locator {
        return this.page.locator('.dps-acc');
    }

    /* --- submit + validation --- */
    emailInput(): Locator {
        return this.page.locator('#dps-email');
    }
    teamNameInput(): Locator {
        return this.page.locator('#dps-team-name');
    }
    submit(): Locator {
        return this.form.locator('.dps-submit');
    }
    error(): Locator {
        return this.page.locator('[data-dps-error]');
    }
    done(): Locator {
        return this.page.locator('[data-dps-done]');
    }
    async clickSubmit(): Promise<void> {
        await this.submit().click();
    }

    async expectError(): Promise<void> {
        await expect(this.error()).toBeVisible();
        await expect(this.error()).not.toHaveText('');
    }
}
