import { test, expect, type Page } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

/**
 * The record-a-donation drawer, for the three things the integration tests
 * cannot see: that the admin is told the donation landed, that a duplicate is
 * answerable rather than a dead end, and that a campaign list nobody can read
 * says so instead of looking like an org with no campaigns.
 *
 * The toast matters more than it sounds. A donation is dated to when the money
 * arrived, so a cheque from January entered in July sorts pages down a
 * newest-first list: without the toast the admin clicks Record and watches
 * nothing happen.
 */

const LIST = '/wp-admin/admin.php?page=dono-donations';

/** A donor nobody else in the suite uses, so the duplicate check is about us. */
function uniqueEmail(): string {
    return `cheque-${ process.env.DONO_E2E_RUN_ID ?? 'local' }-${ Date.now() }@example.org`;
}

async function openDrawer(page: Page): Promise<void> {
    await page.getByRole('button', { name: 'Record a donation' }).click();
    await expect(page.getByText('Money that arrived off the site')).toBeVisible();
}

async function fill(page: Page, email: string, amount: string): Promise<void> {
    await page.locator('.dono-rd input[type="email"]').fill(email);
    // Field renders its label as a div, not a <label for>, so the amount input
    // is reached through its own field wrapper rather than by label.
    await page.locator('.dono-field:has(.dono-field__label:text-is("Amount")) input').fill(amount);
}

test.describe('record a donation', () => {
    let admin: AdminPage;

    test.beforeEach(async ({ page }) => {
        admin = new AdminPage(page);
        await admin.login();
        await page.goto(LIST);
    });

    test('recording says so, and names the row', async ({ page }) => {
        await openDrawer(page);
        await fill(page, uniqueEmail(), '125');

        await page.getByRole('button', { name: 'Record donation' }).click();

        // The reference is the whole point: it is how the admin finds a row
        // that did not sort to the top.
        await expect(page.getByText(/Recorded as \S+/)).toBeVisible();
    });

    test('a second identical donation is answerable, not a dead end', async ({ page }) => {
        const email = uniqueEmail();

        await openDrawer(page);
        await fill(page, email, '125');
        await page.getByRole('button', { name: 'Record donation' }).click();
        await expect(page.getByText(/Recorded as \S+/)).toBeVisible();

        // Same donor, same amount, same date.
        await openDrawer(page);
        await fill(page, email, '125');
        await page.getByRole('button', { name: 'Record donation' }).click();

        const warning = page.locator('.dono-notice--warning');
        await expect(warning).toBeVisible();
        await expect(warning).toContainText('already down for this donor');
        // It names what it matched, so the admin can go and look.
        await expect(warning).toContainText(/DONO/i);

        // The button becomes the answer to the question just asked.
        await expect(page.getByRole('button', { name: 'Record it anyway' })).toBeVisible();
    });

    test('editing after the warning describes a different donation, so it clears', async ({ page }) => {
        const email = uniqueEmail();

        await openDrawer(page);
        await fill(page, email, '125');
        await page.getByRole('button', { name: 'Record donation' }).click();
        await expect(page.getByText(/Recorded as \S+/)).toBeVisible();

        await openDrawer(page);
        await fill(page, email, '125');
        await page.getByRole('button', { name: 'Record donation' }).click();
        await expect(page.locator('.dono-notice--warning')).toBeVisible();

        await page.locator('.dono-field:has(.dono-field__label:text-is("Amount")) input').fill('126');

        await expect(page.locator('.dono-notice--warning')).toHaveCount(0);
        await expect(page.getByRole('button', { name: 'Record donation' })).toBeVisible();
    });

    test('a campaign list that cannot be read says so', async ({ page }) => {
        // What a bookkeeper role without dono_manage_campaigns used to get was a
        // blank picker, so every donation they recorded went uncategorised. The
        // route is faked rather than the role, because the failure to surface is
        // the fetch failing, whatever the reason.
        await page.route('**/dono/v1/admin/donations/campaign-options*', (route) =>
            route.fulfill({ status: 403, contentType: 'application/json', body: '{"code":"forbidden"}' })
        );

        await openDrawer(page);

        await expect(page.getByText('Campaigns could not be loaded')).toBeVisible();
        // SearchableSelect puts its placeholder on the input, not in the text.
        await expect(
            page.locator('.dono-field:has(.dono-field__label:text-is("Campaign")) input')
        ).toHaveAttribute('placeholder', 'Unavailable');
    });
});
