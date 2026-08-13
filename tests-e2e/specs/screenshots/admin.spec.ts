/**
 * wp-admin screenshot capture. Not an assertion suite: it walks every Dono
 * admin screen and writes one PNG per screen for docs, design review and the
 * wp.org listing. Donor-facing surfaces are out of scope; specs/visual covers
 * the donation form.
 *
 * Its own opt-in project (DONO_E2E_SHOTS=1) so a normal e2e run never spends
 * minutes writing images. Output lands in tests-e2e/screenshots (gitignored),
 * or DONO_E2E_SHOTS_DIR.
 *
 *   npm run test:shots
 *
 * Screens whose record does not exist yet skip themselves by name rather than
 * fail, so the suite is useful against a half-seeded site.
 */

import { test } from '@playwright/test';

import { AdminPage } from '../../helpers/AdminPage';
import { firstHref, openAdmin, openTab, shoot } from '../../helpers/capture';

const screen = (page: string, extra = ''): string => `/wp-admin/admin.php?page=${page}${extra}`;

const ROOT = {
    dashboard:     '#dono-admin-dashboard > *',
    campaigns:     '#dono-admin-campaigns > *',
    donations:     '#dono-admin-donations > *',
    donors:        '#dono-admin-donors > *',
    subscriptions: '#dono-admin-subscriptions > *',
    funds:         '#dono-admin-funds > *',
    settings:      '#dono-admin-settings > *',
    tools:         '#dono-admin-tools > *',
};

const SETTINGS_TABS = [
    'setup',
    'gateways',
    'organization',
    'brand',
    'email',
    'receipts',
    'currency',
    'numbering',
    'privacy',
    'roles',
];

const TOOLS_TABS = ['maintenance', 'logs', 'system', 'export', 'import'];

// The donor profile keeps its tab in React state, so these are clicked rather
// than addressed; the fragment stays on the profile route throughout.
const DONOR_TABS = ['donations', 'recurring', 'receipts', 'notes', 'consent', 'activity'];

/** Campaign id out of a campaigns-list row link, or null on an empty list. */
async function firstCampaignId(page: import('@playwright/test').Page): Promise<string | null> {
    const href = await firstHref(page, '#dono-admin-campaigns a[href*="view=detail"]');
    return href ? new URLSearchParams(href.split('?')[1] ?? '').get('id') : null;
}

test.describe('admin screenshots', () => {
    test.beforeEach(async ({ page }) => {
        // A single test walks a whole screen family, several page loads deep.
        test.setTimeout(180_000);
        await new AdminPage(page).login();
    });

    test('dashboard', async ({ page }) => {
        await openAdmin(page, screen('dono'), ROOT.dashboard);
        await shoot(page, 'admin-dashboard');
    });

    test('campaigns', async ({ page }) => {
        await openAdmin(page, screen('dono-campaigns'), ROOT.campaigns);
        await shoot(page, 'admin-campaigns-list');

        const id = await firstCampaignId(page);
        test.skip(! id, 'no campaigns on this site; campaign detail not captured');

        for (const tab of ['overview', 'forms', 'settings']) {
            await openAdmin(page, screen('dono-campaigns', `&view=detail&id=${id}&tab=${tab}`), ROOT.campaigns);
            await shoot(page, `admin-campaign-${tab}`);
        }
    });

    test('form builder', async ({ page }) => {
        // Forms have no top-level screen of their own: the list is the campaign
        // detail's Forms tab, and that is where an editor link comes from.
        await openAdmin(page, screen('dono-campaigns'), ROOT.campaigns);
        const id = await firstCampaignId(page);
        test.skip(! id, 'no campaigns on this site; form builder not captured');

        await openAdmin(page, screen('dono-campaigns', `&view=detail&id=${id}&tab=forms`), ROOT.campaigns);
        const href = await firstHref(page, 'a[href*="page=dono-forms&form="]');
        test.skip(! href, 'no forms on this site; form builder not captured');

        const formId = new URLSearchParams((href as string).split('?')[1] ?? '').get('form');
        await openAdmin(page, screen('dono-forms', `&form=${formId}`), '.dono-form-editor__canvas');
        // The editor is a fixed-height app filling the viewport, so a full-page
        // capture would only add empty page below it.
        await shoot(page, 'admin-form-builder');

        await openTab(page, '.dono-editor-header__tab:has-text("Settings")');
        await shoot(page, 'admin-form-builder-settings');
    });

    test('donations', async ({ page }) => {
        await openAdmin(page, screen('dono-donations'), ROOT.donations);
        await shoot(page, 'admin-donations-list');

        const href = await firstHref(page, '#dono-admin-donations a[href*="view=detail"]');
        test.skip(! href, 'no donations on this site; donation detail not captured');

        const reference = new URLSearchParams((href as string).split('?')[1] ?? '').get('reference');
        await openAdmin(page, screen('dono-donations', `&view=detail&reference=${reference}`), ROOT.donations);
        await shoot(page, 'admin-donation-detail');
    });

    test('donors', async ({ page }) => {
        await openAdmin(page, screen('dono-donors'), ROOT.donors);
        await shoot(page, 'admin-donors-list');

        await openAdmin(page, screen('dono-donors', '#insights'), ROOT.donors);
        await shoot(page, 'admin-donors-insights');

        await openAdmin(page, screen('dono-donors'), ROOT.donors);
        const href = await firstHref(page, '#dono-admin-donors a[href^="#donor/"]');
        test.skip(! href, 'no donors on this site; donor profile not captured');

        await openAdmin(page, screen('dono-donors', href as string), ROOT.donors);
        await shoot(page, 'admin-donor-profile');

        for (const tab of DONOR_TABS) {
            await openTab(page, `.dp-tabs a[href="#${tab}"]`);
            await shoot(page, `admin-donor-profile-${tab}`);
        }
    });

    test('subscriptions', async ({ page }) => {
        await openAdmin(page, screen('dono-subscriptions'), ROOT.subscriptions);
        await shoot(page, 'admin-subscriptions-list');
    });

    test('funds', async ({ page }) => {
        await openAdmin(page, screen('dono-funds'), ROOT.funds);
        await shoot(page, 'admin-funds-list');
    });

    test('settings', async ({ page }) => {
        for (const tab of SETTINGS_TABS) {
            await openAdmin(page, screen('dono-settings', `#${tab}`), ROOT.settings);
            await shoot(page, `admin-settings-${tab}`);
        }
    });

    test('tools', async ({ page }) => {
        for (const tab of TOOLS_TABS) {
            await openAdmin(page, screen('dono-tools', `#${tab}`), ROOT.tools);
            await shoot(page, `admin-tools-${tab}`);
        }
    });
});
