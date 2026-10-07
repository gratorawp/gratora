import { test, expect, type Locator, type Page, type Response, type Route } from '@playwright/test';
import { AdminPage } from '../helpers/AdminPage';

// The dialog is a plain script on WordPress's own Plugins screen, so it is driven there.

const LEFT = 'plugins.php?gratora_e2e_left=1';
const CHOICE = 'gratora_deactivation_choice';
const REASON = 'gratora_deactivation_reason';

/** The plugin's own Deactivate link, with its way out pointed at a page that deactivates nothing. */
async function harmlessLink(page: Page): Promise<Locator> {
    const slug = await page.evaluate(() => (window as unknown as { gratoraDeactivation: { slug: string } }).gratoraDeactivation.slug);
    const link = page.locator(`tr[data-plugin="${slug}"] .deactivate a`);
    await link.evaluate((a, href) => a.setAttribute('href', href), LEFT);

    return link;
}

/** Whether the site refuses to call gratora.net, which only tests-e2e/mu-plugins makes it do. */
async function refusesCalls(page: Page): Promise<boolean> {
    const screen = await page.request.get('/wp-admin/plugins.php');

    return screen.headers()['x-gratora-e2e'] === 'calls-to-gratora-refused';
}

async function openDialog(page: Page, screen = '/wp-admin/plugins.php'): Promise<Locator> {
    await new AdminPage(page).login();
    await page.goto(screen);
    await (await harmlessLink(page)).click();

    const dialog = page.getByRole('dialog', { name: 'Deactivate Gratora' });
    await expect(dialog).toBeVisible();

    return dialog;
}

type Asked = Record<string, URLSearchParams[]>;

/**
 * Answers the dialog's two requests in the site's place and keeps what each asked. A request named in
 * `held` is answered only when its promise settles, or never.
 */
async function asked(page: Page, held: Record<string, Promise<void>> = {}): Promise<Asked> {
    const bodies: Asked = { [CHOICE]: [], [REASON]: [] };
    await page.route('**/admin-ajax.php', async (route: Route) => {
        const body = new URLSearchParams(route.request().postData() ?? '');
        const action = body.get('action') ?? '';
        // WordPress asks things of its own on this screen.
        if (! (action in bodies)) {
            await route.continue();

            return;
        }
        bodies[action].push(body);
        if (action in held) await held[action];
        await route.fulfill({ json: { success: true } });
    });

    return bodies;
}

const reason = (dialog: Locator, label: string): Locator => dialog.getByRole('radio', { name: label });
const box = (dialog: Locator): Locator => dialog.locator('#gratora-deact-comment');
const submit = (dialog: Locator): Locator => dialog.locator('[data-gratora-deact-submit]');
const said = (dialog: Locator): Locator => dialog.getByRole('status');

test('the dialog asks why with nothing picked', async ({ page }) => {
    const dialog = await openDialog(page);

    await expect(dialog.getByRole('radio')).toHaveCount(7);
    await expect(dialog.getByRole('radio', { checked: true })).toHaveCount(0);
    await expect(box(dialog)).toBeHidden();
    await expect(dialog.getByRole('button', { name: 'Clear' })).toBeHidden();
    await expect(submit(dialog)).toHaveText('Deactivate');
    await expect(said(dialog)).toHaveText('');
});

// A control under the keyboard would take a stray space bar as a pick, or as the order to delete.
test('the dialog opens with the keyboard on the dialog itself, and Tab stays inside it', async ({ page }) => {
    const dialog = await openDialog(page);

    await expect(dialog).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(dialog.getByRole('radio').first()).toBeFocused();

    await page.keyboard.press('Shift+Tab');
    await expect(submit(dialog)).toBeFocused();

    await page.keyboard.press('Tab');
    await expect(dialog.getByRole('radio').first()).toBeFocused();
});

test('the keyboard is brought back when it lands behind the dialog', async ({ page }) => {
    const dialog = await openDialog(page);

    await page.locator('#wpfooter a').first().focus();

    await expect(dialog).toBeFocused();
});

test.describe('on a short screen', () => {
    test.use({ viewport: { width: 1100, height: 520 } });

    test('the dialog opens at its top', async ({ page }) => {
        const dialog = await openDialog(page);

        await expect(dialog.getByRole('heading', { name: 'Deactivate Gratora' })).toBeInViewport();
    });
});

test('a picked reason opens its box under it and says the answer will be sent', async ({ page }) => {
    const dialog = await openDialog(page);

    await reason(dialog, 'It is missing something I need').check();

    await expect(box(dialog)).toBeVisible();
    await expect(box(dialog)).toHaveAttribute('placeholder', 'What is missing?');
    await expect(dialog.getByRole('textbox', { name: 'What is missing?' })).toBeVisible();
    expect(await box(dialog).evaluate((el) => el.previousElementSibling?.textContent?.trim())).toBe('It is missing something I need');
    await expect(submit(dialog)).toHaveText('Send and deactivate');
    await expect(said(dialog)).toHaveText('Your answer will be sent when you deactivate.');

    await reason(dialog, 'I no longer need it').check();

    await expect(box(dialog)).toBeHidden();
    await expect(submit(dialog)).toHaveText('Send and deactivate');
});

test('the question keeps its own name when Clear shows beside it', async ({ page }) => {
    const dialog = await openDialog(page);
    await reason(dialog, 'Something else').check();

    await expect(dialog.getByRole('button', { name: 'Clear' })).toBeVisible();
    await expect(dialog.getByRole('group', { name: 'Why are you switching it off?', exact: true })).toBeVisible();
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
    await expect(said(dialog)).toHaveText('No answer will be sent.');
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

test('deactivating with a reason hands the site the reason and the words, apart from the choice about data', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);
    await reason(dialog, 'It is missing something I need').check();
    await box(dialog).fill('Direct debit');

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies[REASON]).toHaveLength(1);
    expect(bodies[REASON][0].get('reason')).toBe('missing');
    expect(bodies[REASON][0].get('comment')).toBe('Direct debit');
    expect(bodies[CHOICE]).toHaveLength(1);
    expect(bodies[CHOICE][0].has('reason')).toBe(false);
    expect(bodies[CHOICE][0].has('wipe')).toBe(false);
});

test('deactivating without a reason hands the site none', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies[REASON]).toHaveLength(0);
    expect(bodies[CHOICE]).toHaveLength(1);
});

test('words typed for one reason do not travel with a reason that asks for none', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);
    await reason(dialog, 'Something did not work').check();
    await box(dialog).fill('The form never loaded');
    await reason(dialog, 'Only for a while, I am testing or fixing something').check();

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies[REASON][0].get('reason')).toBe('temporary');
    expect(bodies[REASON][0].has('comment')).toBe(false);
});

test('with the delete box ticked the button names the deleting and the answer still goes', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page);
    await reason(dialog, 'I no longer need it').check();
    await dialog.getByRole('checkbox', { name: 'Delete all Gratora data as well' }).check();

    await expect(submit(dialog)).toHaveText('Delete everything and deactivate');
    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies[REASON][0].get('reason')).toBe('not_needed');
    expect(bodies[CHOICE][0].get('wipe')).toBe('1');
});

// The site passes the answer on to gratora.net, which can be slow or away.
test('deactivation does not wait for the answer to be passed on', async ({ page }) => {
    const bodies = await asked(page, { [REASON]: new Promise<void>(() => {}) });
    const dialog = await openDialog(page);
    await reason(dialog, 'Something did not work').check();

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies[REASON]).toHaveLength(1);
});

// By then the choice is with the site, and a dialog that closed or changed would only look called off.
test('once deactivation is under way the dialog stays, says so and takes nothing more', async ({ page }) => {
    let letGo = (): void => {};
    const bodies = await asked(page, { [CHOICE]: new Promise<void>((resolve) => { letGo = resolve; }) });
    const dialog = await openDialog(page);
    await reason(dialog, 'Something did not work').check();

    await submit(dialog).click();

    await expect(dialog).toHaveAttribute('aria-busy', 'true');
    await expect(submit(dialog)).toHaveText('Deactivating…');
    await expect(submit(dialog)).toBeDisabled();
    await expect(dialog.getByRole('checkbox', { name: 'Delete all Gratora data as well' })).toBeDisabled();
    await expect(reason(dialog, 'I no longer need it')).toBeDisabled();
    await expect(dialog.getByRole('button', { name: 'Cancel' })).toBeDisabled();
    await expect(dialog).toBeFocused();

    await page.keyboard.press('Escape');
    await page.locator('.gratora-deact__backdrop').click({ position: { x: 5, y: 5 } });

    await expect(dialog).toBeVisible();
    expect(bodies[CHOICE]).toHaveLength(1);
    expect(bodies[REASON]).toHaveLength(1);

    letGo();
    await page.waitForURL(/gratora_e2e_left=1/);
});

test('a site that never answers does not hold the dialog shut for good', async ({ page }) => {
    await page.clock.install();
    await asked(page, { [CHOICE]: new Promise<void>(() => {}) });
    const dialog = await openDialog(page);

    await submit(dialog).click();
    await expect(dialog).toHaveAttribute('aria-busy', 'true');
    await page.clock.fastForward(10_000);

    await page.waitForURL(/gratora_e2e_left=1/);
});

// Nothing answers in the site's place here: a field the script misnames is one the site refuses or does not read.
test('the site takes both requests as the dialog sends them and passes the answer on', async ({ page }) => {
    // The page stays, so that both answers are read while it is still there.
    await page.route(/gratora_e2e_left=1/, (route) => route.fulfill({ status: 204 }));
    const dialog = await openDialog(page);
    test.skip(! await refusesCalls(page), 'without tests-e2e/mu-plugins this site would pass the answer on to the real gratora.net');
    await reason(dialog, 'It is missing something I need').check();
    await box(dialog).fill('Direct debit');

    const answer = (action: string): Promise<Response> => page.waitForResponse((response) => response.url().includes('admin-ajax.php')
        && new URLSearchParams(response.request().postData() ?? '').get('action') === action);
    const [told, chosen] = await Promise.all([answer(REASON), answer(CHOICE), submit(dialog).click()]);

    // WordPress answers a nonce or a person it does not accept with 403, and an action nobody handles with 400.
    expect(told.status()).toBe(200);
    expect(chosen.status()).toBe(200);
    // Set by the test site as it refuses the call the plugin made to gratora.net.
    expect(told.headers()['x-gratora-e2e-passed-on']).toBe('missing');
});

test('a site that asks nothing still opens the dialog and deactivates', async ({ page }) => {
    const bodies = await asked(page);
    const dialog = await openDialog(page, '/wp-admin/plugins.php?gratora_e2e_no_question=1');
    test.skip(! await refusesCalls(page), 'only tests-e2e/mu-plugins switches the question off on request');

    await expect(dialog.getByRole('radio')).toHaveCount(0);
    await expect(dialog).not.toContainText('gratora.net');
    await page.keyboard.press('Shift+Tab');
    await expect(submit(dialog)).toBeFocused();

    await submit(dialog).click();
    await page.waitForURL(/gratora_e2e_left=1/);

    expect(bodies[REASON]).toHaveLength(0);
    expect(bodies[CHOICE]).toHaveLength(1);
});

// WordPress redraws the rows when the list is searched.
test('the dialog still opens after the list has been searched', async ({ page }) => {
    await new AdminPage(page).login();
    await page.goto('/wp-admin/plugins.php');

    const redrawn = page.waitForResponse((response) => response.url().includes('admin-ajax.php')
        && (response.request().postData() ?? '').includes('search-plugins'));
    await page.locator('#plugin-search-input').pressSequentially('gratora');
    await redrawn;

    const link = await harmlessLink(page);
    await expect(link).toBeVisible();
    await link.click();

    await expect(page.getByRole('dialog', { name: 'Deactivate Gratora' })).toBeVisible();
});
