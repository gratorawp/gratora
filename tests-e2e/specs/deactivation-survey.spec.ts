import { test, expect, type Locator, type Page } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

// The dialog is a plain script on WordPress's own Plugins screen, so it is driven there.

const LEFT = 'plugins.php?gratora_e2e_left=1';

/** Opens the dialog with its way out pointed at a page that deactivates nothing. */
async function openDialog(page: Page): Promise<Locator> {
    await new AdminPage(page).login();
    await page.goto('/wp-admin/plugins.php');

    const slug = await page.evaluate(() => (window as unknown as { gratoraDeactivation: { slug: string } }).gratoraDeactivation.slug);
    const link = page.locator(`tr[data-plugin="${slug}"] .deactivate a`);
    await link.evaluate((a, href) => a.setAttribute('href', href), LEFT);
    await link.click();

    const dialog = page.getByRole('dialog', { name: 'Deactivate Gratora' });
    await expect(dialog).toBeVisible();

    return dialog;
}

/** Answers the dialog's request in the site's place and keeps what was asked. */
async function asked(page: Page): Promise<() => URLSearchParams[]> {
    const bodies: URLSearchParams[] = [];
    await page.route('**/admin-ajax.php', async (route) => {
        const body = new URLSearchParams(route.request().postData() ?? '');
        // WordPress asks things of its own on this screen.
        if (body.get('action') !== 'gratora_deactivation_choice') {
            await route.continue();

            return;
        }
        bodies.push(body);
        await route.fulfill({ json: { success: true, data: { wipe: false } } });
    });

    return () => bodies;
}

const reason = (dialog: Locator, label: string): Locator => dialog.getByRole('radio', { name: label });
const box = (dialog: Locator): Locator => dialog.locator('#gratora-deact-comment');
const submit = (dialog: Locator): Locator => dialog.locator('[data-gratora-deact-submit]');

test('the dialog asks why with nothing picked', async ({ page }) => {
    const dialog = await openDialog(page);

    await expect(dialog.getByRole('radio')).toHaveCount(7);
    await expect(dialog.getByRole('radio', { checked: true })).toHaveCount(0);
    await expect(box(dialog)).toBeHidden();
    await expect(dialog.getByRole('button', { name: 'Clear' })).toBeHidden();
    await expect(submit(dialog)).toHaveText('Deactivate');
});

test('a picked reason opens its box under it and says the answer will be sent', async ({ page }) => {
    const dialog = await openDialog(page);

    await reason(dialog, 'It is missing something I need').check();

    await expect(box(dialog)).toBeVisible();
    await expect(box(dialog)).toHaveAttribute('placeholder', 'What is missing?');
    expect(await box(dialog).evaluate((el) => el.previousElementSibling?.textContent?.trim())).toBe('It is missing something I need');
    await expect(submit(dialog)).toHaveText('Send and deactivate');

    await reason(dialog, 'I no longer need it').check();

    await expect(box(dialog)).toBeHidden();
    await expect(submit(dialog)).toHaveText('Send and deactivate');
});

test('Clear takes the answer back', async ({ page }) => {
    const dialog = await openDialog(page);
    await reason(dialog, 'Something did not work').check();
    await box(dialog).fill('The form never loaded');

    await dialog.getByRole('button', { name: 'Clear' }).click();

    await expect(dialog.getByRole('radio', { checked: true })).toHaveCount(0);
    await expect(box(dialog)).toBeHidden();
    await expect(box(dialog)).toHaveValue('');
    await expect(submit(dialog)).toHaveText('Deactivate');
});

test('an answer is forgotten once the dialog is closed', async ({ page }) => {
    const dialog = await openDialog(page);
    await reason(dialog, 'Something else').check();
    await box(dialog).fill('Changed my mind');

    await dialog.getByRole('button', { name: 'Cancel' }).click();
    await expect(dialog).toBeHidden();
    await page.locator(`a[href="${LEFT}"]`).click();

    await expect(dialog.getByRole('radio', { checked: true })).toHaveCount(0);
    await expect(box(dialog)).toHaveValue('');
});

test('deactivating with a reason hands the site the reason and the words', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);
    await reason(dialog, 'It is missing something I need').check();
    await box(dialog).fill('Direct debit');

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies()).toHaveLength(1);
    expect(bodies()[0].get('reason')).toBe('missing');
    expect(bodies()[0].get('comment')).toBe('Direct debit');
});

test('deactivating without a reason hands the site none', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies()).toHaveLength(1);
    expect(bodies()[0].has('reason')).toBe(false);
    expect(bodies()[0].has('comment')).toBe(false);
});

test('words typed for one reason do not travel with a reason that asks for none', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);
    await reason(dialog, 'Something did not work').check();
    await box(dialog).fill('The form never loaded');
    await reason(dialog, 'Only for a while, I am testing or fixing something').check();

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies()[0].get('reason')).toBe('temporary');
    expect(bodies()[0].has('comment')).toBe(false);
});
