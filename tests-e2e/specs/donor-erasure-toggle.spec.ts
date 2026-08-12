import { test, expect, type Page, type Locator } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

/**
 * The switch that decides whether Dono erases donors nobody asked about. The
 * integration tests prove the sweep obeys it; these prove an admin can see what
 * it is about to do before it does it, which is the part that only exists on
 * screen.
 *
 * The reunite window is asserted to stay put deliberately. It governs every
 * redaction, including a donor deleting their own account, so it is not part of
 * what the switch hides, and a future tidy that "completes" the grouping would
 * hide a control that is still in force.
 */

const PRIVACY = '/wp-admin/admin.php?page=dono-settings&tab=privacy';

const TOGGLE = 'Erase inactive donors automatically';
const YEARS = 'Erase donors inactive for (years)';
const WINDOW = 'Reunite window after redaction (days)';

/**
 * The Switch puts its accessible name on the label wrapping the input, and the
 * input itself is hidden behind the track, so state is read from the input and
 * flipped by clicking what a person clicks.
 */
function switchFor(page: Page): Locator {
    return page.locator(`.dono-switch[aria-label="${ TOGGLE }"]`);
}

function toggleState(page: Page): Locator {
    return switchFor(page).locator('input');
}

/** A FormRow, so a label and its input are reached together. */
function field(page: Page, label: string): Locator {
    return page.locator(`.dono-form-row:has(.dono-form-row__label:has-text("${ label }"))`);
}

async function openPrivacy(page: Page): Promise<void> {
    await page.goto(PRIVACY);
    await expect(page.getByText('Donor data handling')).toBeVisible();
}

test.describe('automatic donor erasure', () => {
    test.beforeEach(async ({ page }) => {
        await new AdminPage(page).login();
    });

    test('the years field follows the switch', async ({ page }) => {
        await openPrivacy(page);

        // Whatever this site has saved, drive it to off first: the shipped
        // default is pinned by the integration suite, and a stored option would
        // make an assertion about it pass without testing anything.
        if (await toggleState(page).isChecked()) {
            await switchFor(page).click();
        }
        await expect(toggleState(page)).not.toBeChecked();
        await expect(field(page, YEARS)).toHaveCount(0);

        await switchFor(page).click();
        await expect(field(page, YEARS)).toBeVisible();

        await switchFor(page).click();
        await expect(field(page, YEARS)).toHaveCount(0);
    });

    test('the reunite window stays out in front of the switch', async ({ page }) => {
        await openPrivacy(page);

        // Visible while erasure is off, because a donor deleting their own
        // account still lands in it.
        await expect(field(page, WINDOW)).toBeVisible();
        await expect(field(page, WINDOW).locator('input')).toHaveValue('90');
    });

    test('switching it on reveals the window and what it would take', async ({ page }) => {
        await openPrivacy(page);
        if (! await toggleState(page).isChecked()) {
            await switchFor(page).click();
        }
        await expect(toggleState(page)).toBeChecked();

        const years = field(page, YEARS);
        await expect(years).toBeVisible();
        await expect(years.locator('input')).toHaveValue('7');

        // The count is the whole point of showing it before saving. It is
        // debounced and then fetched, so it arrives after the field does.
        await expect(
            page.getByText(/past this window|due for erasure|reach this window/)
        ).toBeVisible({ timeout: 15_000 });
    });

    test('clearing the window does not fall back to erasing everyone', async ({ page }) => {
        await openPrivacy(page);
        if (! await toggleState(page).isChecked()) {
            await switchFor(page).click();
        }
        await expect(toggleState(page)).toBeChecked();

        const input = field(page, YEARS).locator('input');
        await input.fill('');
        await input.blur();

        // A cleared box means no window. Falling back to 1 would make an
        // unfinished edit the most destructive setting the field can hold.
        await expect(input).toHaveValue('');
    });
});
