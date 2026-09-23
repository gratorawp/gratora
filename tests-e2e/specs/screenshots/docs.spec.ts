/**
 * Capture documentation widgets by visible heading on a seeded site. Set GRATORA_E2E_SHOTS=1
 * and GRATORA_E2E_SHOTS_DIR, then run the screenshots project with this spec.
 *
 * GRATORA_E2E_STORAGE_STATE takes a Playwright storage state holding a wp-admin session,
 * for a site whose login form will not take env credentials. GRATORA_E2E_PORTAL_STATE does
 * the same for a donor portal session; the portal capture skips itself without one.
 */

import path from 'node:path';

import { expect, test, type Locator, type Page } from '@playwright/test';

import { AdminPage } from '../../helpers/AdminPage';
import { SHOTS_DIR, shoot } from '../../helpers/capture';

/** The documentation is shot at 1600 wide, so the 2x files land at 3200. */
const VIEWPORT = { width: 1600, height: 1000 };

/** The setup wizard is a centered app, shot on its own frame rather than the admin one. */
const WIZARD_VIEWPORT = { width: 1500, height: 1100 };

const STORAGE_STATE = process.env.GRATORA_E2E_STORAGE_STATE;

/** Front-end campaign page the block images come from. Needs a cover image and a published form. */
const CAMPAIGN_PATH = process.env.GRATORA_E2E_CAMPAIGN_PATH ?? '/campaigns/demo-annual-fund/';

/** Campaign the block reference images are rendered against. */
const CAMPAIGN_ID = process.env.GRATORA_E2E_CAMPAIGN_ID ?? '3';

/** A single-page form for the builder images, and a wizard form for the multi-step ones. */
const FORM_ID   = process.env.GRATORA_E2E_BUILDER_FORM_ID ?? '13';
const WIZARD_ID = process.env.GRATORA_E2E_WIZARD_FORM_ID ?? '11';

/** The organisation the wizard shots are filled in as, matching the seeded site. */
const ORG = {
    name:    'Wildwater Trust',
    email:   'hello@wildwatertrust.org',
    country: 'United States',
    state:   'Montana',
};

/** The wizard's one forward button, whatever this step calls it. */
async function advanceWizard(page: Page): Promise<void> {
    await page.getByRole('button', { name: /^(Get started|Next|Finish setup)/ }).click();
}

/**
 * Choose from one of the wizard's comboboxes, addressed by the label around it.
 *
 * The control is a filtering text input that renders only the first fifty
 * options, so a country late in the alphabet is not in the DOM until it is
 * typed. Each option carries its code beside it, so the match is made against
 * the label element rather than the option's whole text.
 */
async function pickFrom(page: Page, label: string, option: string): Promise<void> {
    const control = page.locator('.gratora-onboarding__control-label')
        .filter({ hasText: label })
        .getByRole('combobox')
        .first();

    await control.click();
    await control.fill(option);
    await page.waitForTimeout(400);

    // Matched on the option's own label element: "United States" and "United
    // States Minor Outlying Islands" share a prefix, and hasText would take
    // either.
    await page.getByRole('option')
        .filter({ has: page.getByText(option, { exact: true }) })
        .first()
        .click();
    await page.waitForTimeout(300);
}

const admin = (page: string, extra = ''): string => `/wp-admin/admin.php?page=${page}${extra}`;

const shotPath = (name: string): string => path.join(SHOTS_DIR, `${name}.png`);

/** One dashboard widget, addressed by the heading a reader sees. */
function widget(page: Page, heading: string): Locator {
    return page.locator('.gratora-widget-slot').filter({ has: page.getByRole('heading', { name: heading, exact: true }) });
}

/**
 * Unpin wp-admin's chrome before a fullPage capture.
 *
 * The admin menu is position:fixed, so a fullPage shot paints it once at the
 * viewport it was captured from and leaves the rest of the column empty, which
 * reads as a rendering glitch in the middle of the article. Static positioning
 * lets it paint the whole height. The bar goes static for a second reason: an
 * absolute one keeps its top offset and lands over the page heading, taking
 * the heading and its controls out of the shot.
 */
async function unpinAdminChrome(page: Page): Promise<void> {
    await page.addStyleTag({
        content: `#adminmenuback, #adminmenuwrap { position: static !important; }
                  #wpadminbar { position: static !important; }`,
    });
}

/** Drop the admin bar off a front-end page, which a reader of the docs never sees. */
async function hideAdminBar(page: Page): Promise<void> {
    await page.addStyleTag({ content: '#wpadminbar { display: none !important; } html { margin-top: 0 !important; }' });
}

/**
 * Capture one element. Fails rather than writing a wrong image when the target
 * is missing, because a stale screenshot in the docs is worse than a red test.
 */
async function shootElement(page: Page, target: Locator, name: string): Promise<void> {
    await expect(target).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(600);
    await target.screenshot({ path: shotPath(name), animations: 'disabled', caret: 'hide' });
}

/** Document-space box of an element, which a clipped capture is addressed in. */
async function documentBox(target: Locator): Promise<{ x: number; y: number; width: number; height: number }> {
    return target.evaluate((el) => {
        const r = el.getBoundingClientRect();
        return { x: r.x + window.scrollX, y: r.y + window.scrollY, width: r.width, height: r.height };
    });
}

/**
 * Capture a band of the page the full width of the viewport, vertically bounded
 * by one block. The block images are 3200 wide because a block is read in the
 * page around it, not cut out of it.
 */
async function shootBand(page: Page, target: Locator, name: string, pad = 24): Promise<void> {
    await expect(target).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(400);

    const box   = await documentBox(target);
    const width = page.viewportSize()?.width ?? VIEWPORT.width;

    await page.screenshot({
        path: shotPath(name),
        fullPage: true,
        clip: { x: 0, y: Math.max(0, box.y - pad), width, height: box.height + pad * 2 },
        animations: 'disabled',
        caret: 'hide',
    });
}

/** Capture a band of one column, from the top of `from` to the bottom of `to`. */
async function shootSpan(page: Page, column: Locator, from: Locator, to: Locator, name: string, pad = 16): Promise<void> {
    await expect(from).toBeVisible({ timeout: 15_000 });
    await page.waitForTimeout(400);

    const col   = await documentBox(column);
    const start = await documentBox(from);
    const end   = await documentBox(to);

    await page.screenshot({
        path: shotPath(name),
        fullPage: true,
        clip: {
            x: Math.max(0, col.x - pad),
            y: Math.max(0, start.y - pad),
            width: col.width + pad * 2,
            height: end.y + end.height - start.y + pad * 2,
        },
        animations: 'disabled',
        caret: 'hide',
    });
}

/**
 * Server render of a block no page on this site carries, for the images the
 * block reference needs. The markup is the block's own output and nothing is
 * written back: the page it is dropped into is never saved.
 */
async function renderBlock(page: Page, block: string, attributes: Record<string, string | number>): Promise<string> {
    return page.evaluate(async ([name, attrs]) => {
        const nonce = await fetch('/wp-admin/admin-ajax.php?action=rest-nonce', { credentials: 'same-origin' }).then((r) => r.text());
        const query = new URLSearchParams({ context: 'edit' });
        Object.entries(attrs as Record<string, string | number>).forEach(([k, v]) => query.set(`attributes[${k}]`, String(v)));

        const res  = await fetch(`/wp-json/wp/v2/block-renderer/${name}?${query.toString()}`, {
            credentials: 'same-origin',
            headers:     { 'X-WP-Nonce': nonce.trim() },
        });
        const body = await res.json();
        const html = String(body?.rendered ?? '').trim();
        if (html === '') throw new Error(`block-renderer returned nothing for ${name}`);
        return html;
    }, [block, attributes] as [string, Record<string, string | number>]);
}

/** A handed-in session needs no login; the form covers a run without one. */
async function signIn(page: Page): Promise<void> {
    if (! STORAGE_STATE) {
        await new AdminPage(page).login();
        return;
    }
    await page.goto('/wp-admin/');
    await expect(page.locator('#wpadminbar')).toBeVisible({ timeout: 30_000 });
}

/** Put rendered markup in the page's content column and hand back its wrapper. */
async function dropBlock(page: Page, html: string): Promise<Locator> {
    await page.evaluate((markup) => {
        document.getElementById('gratora-shot-host')?.remove();
        const host     = document.createElement('div');
        host.id        = 'gratora-shot-host';
        // Clear of whatever the page ends with, so the band holds one block.
        host.style.margin = '64px 0';
        host.innerHTML = markup;
        document.querySelector('.entry-content')?.append(host);
    }, html);

    const host = page.locator('#gratora-shot-host');
    await host.scrollIntoViewIfNeeded();
    await page.waitForTimeout(400);
    return host;
}

test.describe('documentation screenshots', () => {
    if (STORAGE_STATE) test.use({ storageState: STORAGE_STATE });

    test.beforeEach(async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize(VIEWPORT);
        await signIn(page);
    });

    test('dashboard', async ({ page }) => {
        await page.goto(admin('gratora'));
        await page.waitForLoadState('networkidle');
        // Recharts draws its series from JS, so idle alone still catches the
        // revenue chart mid-sweep.
        await page.waitForTimeout(2_500);

        await shootElement(page, widget(page, 'Key metrics'), 'dashboard-kpi-cards');
        await shootElement(page, widget(page, 'Revenue'), 'dashboard-revenue-chart');
        await shootElement(page, widget(page, 'Needs attention'), 'dashboard-attention-items');
        await shootElement(page, widget(page, 'Top campaigns'), 'dashboard-top-campaigns');
        await shootElement(page, widget(page, 'Recurring revenue'), 'dashboard-recurring-stats');
        await shootElement(page, widget(page, 'Recent donations'), 'dashboard-recent-activity');

        await unpinAdminChrome(page);
        await shoot(page, 'dashboard-overview', true);
    });

    test('lists and details', async ({ page }) => {
        // Every full-screen documentation image is viewport sized, not fullPage:
        // 1600x1000 at 2x is the 3200x2000 the existing set uses.
        await page.goto(admin('gratora-campaigns'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'campaigns-list');

        await page.goto(admin('gratora-donations'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'donations-list');

        await page.goto(admin('gratora-donors'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'donors-list');

        // Open the first row of each list rather than addressing an id, so the
        // capture survives a reseed.
        await page.goto(admin('gratora-donations'));
        await page.waitForLoadState('networkidle');
        const donation = page.locator('#gratora-admin-donations a[href^="#donation/"], #gratora-admin-donations a[href*="view=detail"]').first();
        await expect(donation).toBeVisible({ timeout: 15_000 });
        await donation.click();
        await page.waitForTimeout(2_500);
        await shoot(page, 'donation-detail');

        await page.goto(admin('gratora-donors'));
        await page.waitForLoadState('networkidle');
        const donor = page.locator('#gratora-admin-donors a[href^="#donor/"]').first();
        await expect(donor).toBeVisible({ timeout: 15_000 });
        await donor.click();
        await page.waitForTimeout(2_500);
        await shoot(page, 'donor-detail');
    });

    test('roles settings', async ({ page }) => {
        await page.goto(admin('gratora-settings', '#roles'));
        await page.waitForLoadState('networkidle');
        await expect(page.getByRole('columnheader', { name: 'Administrator' }).or(page.getByText('Administrator', { exact: true }).first())).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(1_500);
        await shoot(page, 'roles-settings');
    });

    test('setup wizard', async ({ page }) => {
        // The later steps are reached by buttons that write the organisation's
        // profile, currency and brand, so walking the whole wizard is opt-in:
        // set GRATORA_E2E_ONBOARDING=1 and put those settings back afterwards.
        // Finishing is safe on a site that has onboarded already, because the
        // one destructive branch in finalize() is guarded on a first run.
        await page.setViewportSize(WIZARD_VIEWPORT);
        await page.goto(admin('gratora-onboarding'));
        await page.waitForLoadState('networkidle');

        const cards = page.locator('#gratora-admin-onboarding').getByRole('button');
        await expect(cards.first()).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(800);

        await page.getByText('Just exploring', { exact: true }).click();
        await page.waitForTimeout(400);
        await shoot(page, 'onboarding-start');

        await page.getByText('Nonprofit or charity', { exact: true }).click();
        await page.waitForTimeout(400);
        await shoot(page, 'onboarding-step1');

        if (! process.env.GRATORA_E2E_ONBOARDING) return;

        await advanceWizard(page);
        await expect(page.getByRole('heading', { name: 'Where are you based?' })).toBeVisible({ timeout: 15_000 });

        // The org profile is empty on a seeded site, and an empty form is a
        // poor illustration of a step about filling one in.
        await page.locator('#gratora-onboarding-name').fill(ORG.name);
        await page.locator('#gratora-onboarding-email').fill(ORG.email);
        await pickFrom(page, 'Country', ORG.country);
        await pickFrom(page, 'State', ORG.state);
        await page.waitForTimeout(600);
        await shoot(page, 'onboarding-step2');

        await advanceWizard(page);
        await expect(page.getByRole('heading', { name: 'Pick a starting look' })).toBeVisible({ timeout: 15_000 });
        // The preview renders its own form, which arrives after the step does.
        await page.waitForTimeout(1_500);
        await shoot(page, 'onboarding-step4');

        await advanceWizard(page);
        await expect(page.getByText('Skip for now', { exact: true })).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(800);
        await shoot(page, 'onboarding-step5');
    });

    test('creating a campaign', async ({ page }) => {
        await page.goto(admin('gratora-campaigns'));
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);

        // The drawer is opened and shot, never submitted.
        await page.getByRole('button', { name: 'Add new campaign' }).first().click();
        await expect(page.getByRole('heading', { name: /new campaign/i })).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(1_200);
        await shoot(page, 'campaign-create-form');
        await page.keyboard.press('Escape');

        // The publish entry only exists on a campaign that is not published yet.
        const draft = page.locator('#gratora-admin-campaigns tr', { hasText: 'Draft' }).first();
        await expect(draft).toBeVisible({ timeout: 15_000 });
        await draft.locator('a[href*="view=detail"]').first().click();
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(2_000);

        await page.getByRole('button', { name: 'Campaign actions' }).click();
        await expect(page.getByRole('menuitem', { name: /publish campaign/i })).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(600);
        await shoot(page, 'campaign-publish');
        await page.keyboard.press('Escape');
    });

    test('campaign page in the editor', async ({ page }) => {
        await page.goto(CAMPAIGN_PATH);
        const editHref = await page.locator('#wp-admin-bar-edit a').first().getAttribute('href');
        expect(editHref, 'campaign page has no edit link; is this session an administrator?').toBeTruthy();

        await page.goto(editHref as string);
        await expect(page.locator('iframe[name="editor-canvas"], .editor-styles-wrapper').first()).toBeVisible({ timeout: 45_000 });
        await page.waitForTimeout(6_000);
        await page.getByRole('button', { name: /^close$/i }).first().click({ timeout: 3_000 }).catch(() => {});
        await page.waitForTimeout(1_000);
        await shoot(page, 'campaign-auto-page');
    });

    test('form builder', async ({ page }) => {
        await page.goto(admin('gratora-forms', `&form=${FORM_ID}`));
        await expect(page.locator('.gratora-form-editor__canvas')).toBeVisible({ timeout: 30_000 });
        await page.waitForTimeout(2_500);
        await shoot(page, 'form-builder-layout');

        await page.locator('.gratora-editor-header__tab', { hasText: 'Preview' }).click();
        await page.waitForTimeout(2_500);
        await shoot(page, 'form-builder-preview');

        await page.locator('.gratora-editor-header__tab', { hasText: 'Settings' }).click();
        await page.waitForTimeout(2_000);
        await shoot(page, 'form-settings-panel');
    });

    test('multi-step form', async ({ page }) => {
        await page.goto(admin('gratora-forms', `&form=${WIZARD_ID}`));
        await expect(page.locator('.gratora-form-editor__canvas')).toBeVisible({ timeout: 30_000 });
        await page.waitForTimeout(2_500);
        await shoot(page, 'multi-step-steps-added');

        const steps = page.locator('[data-type="gratora/steps"]').first();
        await shootElement(page, steps, 'multi-step-steps-block');

        // Selecting the block is what puts Wizard navigation in the inspector.
        await steps.click({ position: { x: 8, y: 8 } });
        const sidebar   = page.locator('.gratora-form-sidebar').first();
        const inspector = page.locator('.block-editor-block-inspector').first();
        await expect(inspector.getByText('Wizard navigation')).toBeVisible({ timeout: 15_000 });
        await page.waitForTimeout(800);
        await shootSpan(page, sidebar, sidebar, inspector, 'multi-step-progress-styles', 0);
    });

    test('campaign blocks', async ({ page }) => {
        await page.goto(CAMPAIGN_PATH);
        await page.waitForLoadState('networkidle');
        await hideAdminBar(page);
        await page.waitForTimeout(800);

        // Every block reference image is the block's own render against a real
        // campaign, put in the page's content column so the shot holds one
        // block and the page around it rather than the column beside it.
        const figures = await Promise.all(['raised', 'donations', 'donors'].map(
            (metric) => renderBlock(page, 'gratora/campaign-stat', { campaignId: CAMPAIGN_ID, metric, size: 'lg' })
        ));
        await shootBand(
            page,
            await dropBlock(page, `<div style="display:flex;gap:32px">${figures.map((html) => `<div style="flex:1">${html}</div>`).join('')}</div>`),
            'campaign-stats',
        );

        const shots: Array<[string, string, Record<string, string | number>]> = [
            ['gratora/campaign-progress', 'campaign-progress', {}],
            ['gratora/donate-button', 'donate-button', { align: 'center' }],
            ['gratora/top-donors', 'top-donors', { limit: 5, showDonorCount: 1 }],
            ['gratora/recent-donations', 'recent-donations', { limit: 5 }],
            ['gratora/supporter-wall', 'supporter-wall', { limit: 20 }],
            ['gratora/donation-form', 'donation-form', {}],
            ['gratora/campaign-grid', 'campaign-grid', { count: 3, orderBy: 'most-funded' }],
        ];

        for (const [block, name, attributes] of shots) {
            const host = await dropBlock(page, await renderBlock(page, block, { campaignId: CAMPAIGN_ID, ...attributes }));
            await shootBand(page, host, name);
        }
    });
});

test.describe('documentation screenshots, donor side', () => {
    // The page and its form are shot the way a donor meets them, with no
    // admin bar and no editor affordances in frame.
    test.use({ storageState: { cookies: [], origins: [] } });

    test.beforeEach(async ({ page }) => {
        test.setTimeout(180_000);
        await page.setViewportSize(VIEWPORT);
    });

    test('campaign page', async ({ page }) => {
        await page.goto(CAMPAIGN_PATH);
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);
        await shoot(page, 'campaign-frontend', true);

        // The switcher is read together with the fields under it, which is how
        // a donor meets it.
        const form     = page.locator('.gratora-form').first();
        const switcher = page.locator('.gratora-form__currency-switcher').first();
        const email    = page.locator('.gratora-form__field').filter({ has: page.locator('input[type="email"]') }).first();
        await shootSpan(page, form, switcher, email, 'currency-switcher-block');
    });
});

test.describe('documentation screenshots, donor portal', () => {
    const portalState = process.env.GRATORA_E2E_PORTAL_STATE;

    test.skip(! portalState, 'no donor portal session; set GRATORA_E2E_PORTAL_STATE');
    test.use({ storageState: portalState ?? { cookies: [], origins: [] } });

    test('portal overview', async ({ page }) => {
        test.setTimeout(120_000);
        await page.setViewportSize(VIEWPORT);
        await page.goto(process.env.GRATORA_E2E_PORTAL_PATH ?? '/donor-portal/');
        await page.waitForLoadState('networkidle');
        await page.waitForTimeout(1_500);

        const portal = page.locator('.gratora-donor-portal').first();
        await expect(portal.getByText('Overview', { exact: true })).toBeVisible({ timeout: 15_000 });
        await shootElement(page, portal, 'donor-portal');
    });
});
