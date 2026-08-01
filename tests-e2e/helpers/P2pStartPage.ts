import { expect, type Locator, type Page } from '@playwright/test';
import { P2P } from '../fixtures/p2p';

/**
 * Page object for the public "start fundraising" page. Wraps the vanilla
 * interactivity from assets/p2p-start.js: the solo/join/create segmented
 * control + reveals, the filterable team list, goal presets, the why-counter,
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

    /** Append a query string, respecting a startPath that already carries one. */
    static withQuery(path: string, query: string): string {
        return path + (path.includes('?') ? '&' : '?') + query;
    }

    /* --- segmented control --- */
    segOption(choice: 'solo' | 'join' | 'create'): Locator {
        return this.page.locator(`.dono-p2p-seg__opt[data-choice="${choice}"]`);
    }
    reveal(choice: 'join' | 'create'): Locator {
        return this.page.locator(`[data-dono-p2p-reveal="${choice}"]`);
    }
    choiceValue(): Locator {
        return this.page.locator('[data-dono-p2p-choice-value]');
    }
    async chooseSegment(choice: 'solo' | 'join' | 'create'): Promise<void> {
        await this.segOption(choice).click();
    }

    /* --- team picker ---
     * A filtered-in-place list of radios, not a dropdown: every team is visible
     * from the start and the search box only hides non-matching rows. The
     * submitted value is the hidden team_id, which the radios drive. */
    teamPicker(): Locator {
        return this.page.locator('[data-dono-p2p-combo]');
    }
    teamSearch(): Locator {
        return this.page.locator('#dono-p2p-team-search');
    }
    teamOptions(): Locator {
        return this.teamPicker().locator('[data-dono-p2p-team-option]');
    }
    teamOption(name: string): Locator {
        return this.teamOptions().filter({ hasText: name });
    }
    teamRadio(name: string): Locator {
        return this.teamOption(name).locator('.dp-team__radio');
    }
    teamEmpty(): Locator {
        return this.teamPicker().locator('.dp-teamlist__empty');
    }
    teamIdValue(): Locator {
        return this.teamPicker().locator('[data-dono-p2p-team-id]');
    }

    /* --- goal presets --- */
    goalPreset(amount: number | string): Locator {
        return this.page.locator(`.dono-p2p-goal-preset[data-goal="${amount}"]`);
    }
    goalPresets(): Locator {
        return this.page.locator('.dono-p2p-goal-preset');
    }
    goalInput(): Locator {
        return this.page.locator('#dono-p2p-goal');
    }

    /* --- why counter --- */
    whyInput(): Locator {
        return this.page.locator('#dono-p2p-why');
    }
    whyCount(): Locator {
        return this.page.locator('[data-dono-p2p-count]');
    }

    /* --- faq --- */
    faqItems(): Locator {
        return this.page.locator('.dp-faq');
    }
    faqQuestion(item: Locator): Locator {
        return item.getByRole('button');
    }
    faqAnswer(item: Locator): Locator {
        return item.locator('.dp-a');
    }

    /* --- fields --- */
    nameInput(): Locator {
        return this.page.locator('#dono-p2p-name');
    }
    emailInput(): Locator {
        return this.page.locator('#dono-p2p-email');
    }
    displayInput(): Locator {
        return this.page.locator('#dono-p2p-display');
    }
    teamNameInput(): Locator {
        return this.page.locator('#dono-p2p-team-name');
    }

    /* --- submit + validation --- */
    submit(): Locator {
        return this.form.locator('button[type="submit"]');
    }
    error(): Locator {
        return this.page.locator('[data-dono-p2p-error]');
    }
    done(): Locator {
        return this.page.locator('[data-dono-p2p-done]');
    }
    doneUrl(): Locator {
        return this.done().locator('[data-dono-p2p-url]');
    }
    doneTitle(): Locator {
        return this.done().locator('.dono-p2p-done__title');
    }
    doneSub(): Locator {
        return this.done().locator('.dono-p2p-done__sub');
    }
    doneShare(): Locator {
        return this.done().locator('[data-dono-p2p-share]');
    }
    async clickSubmit(): Promise<void> {
        await this.submit().click();
    }

    async expectError(): Promise<void> {
        await expect(this.error()).toBeVisible();
        await expect(this.error()).not.toHaveText('');
    }
}
