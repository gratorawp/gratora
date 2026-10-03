import { test, expect, type Locator, type Page } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

// wp-admin styles every checkbox and form label on the page, and only a real
// WordPress page loads those styles beside the plugin's, so these run in the browser.

/** Opens the default fund for editing. Its schedule and its default switch cannot be changed. */
async function editDefaultFund(page: Page): Promise<Locator> {
    await new AdminPage(page).login();
    await page.goto('/wp-admin/admin.php?page=gratora-funds');

    const row = page.locator('.dataviews-view-table tbody tr').filter({ has: page.locator('.gratora-fund-badge--default') });
    await row.locator('.gratora-row__link').click();

    const dialog = page.getByRole('dialog', { name: 'Edit fund' });
    await expect(dialog).toBeVisible();

    return dialog;
}

/** How each switch in the dialog is drawn: its checkbox, its track, and the pointer over it. */
const switches = (dialog: Locator): Promise<{ locked: boolean; checkbox: string; track: string; cursor: string }[]> =>
    dialog.locator('.gratora-switch').evaluateAll((all) => all.map((el) => {
        const input = el.querySelector('input') as HTMLInputElement;

        return {
            locked:   input.disabled,
            checkbox: getComputedStyle(input).opacity,
            track:    getComputedStyle(el.querySelector('.gratora-switch__track') as HTMLElement).opacity,
            cursor:   getComputedStyle(input).cursor,
        };
    }));

test('a locked switch shows no checkbox over it', async ({ page }) => {
    const locked = (await switches(await editDefaultFund(page))).filter((drawn) => drawn.locked);

    expect(locked).toHaveLength(2);
    expect(locked.map((drawn) => drawn.checkbox)).toEqual(['0', '0']);
});

test('a locked switch is dimmed and refuses the pointer', async ({ page }) => {
    const drawn = await switches(await editDefaultFund(page));

    expect(drawn.filter((one) => one.locked).map(({ track, cursor }) => ({ track, cursor }))).toEqual([
        { track: '0.55', cursor: 'not-allowed' },
        { track: '0.55', cursor: 'not-allowed' },
    ]);
    expect(drawn.filter((one) => ! one.locked).map(({ track, cursor }) => ({ track, cursor }))).toEqual([
        { track: '1', cursor: 'pointer' },
    ]);
});

test('every field of the fund dialog is labelled in one style', async ({ page }) => {
    const dialog = await editDefaultFund(page);

    const styles = await dialog.locator('.gratora-fld > label, .gratora-fld > .gratora-fld__label').evaluateAll((labels) => labels.map((label) => {
        const { display, fontWeight, fontSize, marginBottom } = getComputedStyle(label);

        return `${display} ${fontWeight} ${fontSize} ${marginBottom}`;
    }));

    expect(styles).toHaveLength(7);
    expect(styles).toEqual(styles.map(() => styles[0]));
});

test('the sections of the fund dialog are spaced evenly', async ({ page }) => {
    const dialog = await editDefaultFund(page);

    const gaps = await dialog.locator('fieldset.gratora-fset').evaluateAll((sections) => sections.slice(1).map((section, i) => {
        const above = (sections[i].lastElementChild as HTMLElement).getBoundingClientRect().bottom;

        return Math.round((section.querySelector('legend') as HTMLElement).getBoundingClientRect().top - above);
    }));

    expect(gaps).toEqual(gaps.map(() => gaps[0]));
});
