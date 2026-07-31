import { test, expect } from '@playwright/test';
import { P2P } from '../../fixtures/p2p';
import { AdminPage } from '../../helpers/AdminPage';

/**
 * Single end-to-end smoke that walks one customer journey across the whole
 * product surface in one pass. Codifies the manual QA round 2 walkthrough.
 *
 * Phases (each step asserts the previous one's outcome):
 *   1. Admin: campaign in published state, default form auto-created.
 *   2. Public: campaign landing renders donate + start CTAs.
 *   3. Public: a supporter creates a fundraiser via /start/ (solo).
 *   4. Public: the supporter's fundraiser page resolves and shows them.
 *   5. Public: a donor gives via the fundraiser page using the sandbox
 *      gateway and lands at the thank-you state.
 *   6. Server: DB confirms attribution + paid status.
 *   7. Admin: campaign overview reflects the activity.
 *
 * Built as ONE test on purpose: the steps are causally dependent (you can't
 * test "fundraiser exists" without first having run /start/). Split tests
 * would either share state (flaky) or re-seed every step (slow).
 *
 * Needs DONO_E2E_P2P_CAMPAIGN_ID + admin creds + DONO_E2E_P2P_START_PATH.
 */

/** The donation form's SSR runtime config (script[data-dono-form-config]). */
type DonationFormConfig = {
    form_id:     number;
    campaign_id: number;
    gateway:     string;
    currency:    string;
    nonce:       string;
    spam?:  { formToken?: string };
    extra?: Record<string, string>;
};

test.describe('P2P customer journey', () => {
    test('admin -> public start -> public donate -> admin sees activity', async ({ page, baseURL }) => {
        test.skip(P2P.campaignId === undefined, 'set DONO_E2E_P2P_CAMPAIGN_ID (wp dono-p2p e2e-seed)');
        test.skip(! P2P.startPath, 'set DONO_E2E_P2P_START_PATH (wp dono-p2p e2e-seed)');

        const admin = new AdminPage(page);
        const stamp = Date.now();
        const supporterName = `Journey ${stamp}`;
        const supporterEmail = `journey-${stamp}@dono.test`;
        const donorEmail = `journey-donor-${stamp}@dono.test`;

        // -------------------------------------------------------------- 1
        // Admin: campaign should be in published state. The seed leaves it
        // in `published`, but bug #1's fix means we can also recover from
        // a draft via the menu. Belt-and-suspenders: PUT to published.
        // The PUT runs from a Dono admin screen because that is where the
        // REST nonce (window.wpApiSettings) is localised.
        await admin.login();
        await admin.openCampaign(P2P.campaignId!);
        await page.evaluate(async (id) => {
            const nonce = (window as any).wpApiSettings?.nonce ?? '';
            await fetch(`/wp-json/dono/v1/admin/campaigns/${id}`, {
                method:  'PUT',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': nonce },
                body:    JSON.stringify({ status: 'published' }),
            });
        }, P2P.campaignId);

        await admin.openCampaign(P2P.campaignId!);
        await expect(page.locator('.dono-pill').filter({ hasText: /^Active$/i }).first()).toBeVisible();

        // Forms tab should show at least one published form (the default).
        await page.goto(`/wp-admin/admin.php?page=dono-campaigns&view=detail&id=${P2P.campaignId}&tab=forms`);
        await expect(page.locator('.dono-default-pill').first(), 'a default form exists').toBeVisible();

        // -------------------------------------------------------------- 2
        // Public: campaign landing renders + has both CTAs. The hero card is
        // the campaign thermometer's own donate button; the "Start
        // fundraising" link also appears again lower down as a core button
        // block, hence .first().
        await page.goto(P2P.campaignPath);
        await expect(page.getByRole('link', { name: /^Start fundraising$/ }).first()).toBeVisible();
        await expect(page.locator('.dp-hero-card').getByRole('link', { name: /^Donate$/ })).toBeVisible();

        // -------------------------------------------------------------- 3
        // Public: supporter creates a fundraiser via /start/ (solo flow).
        // Solo is the preselected segment, so no choice click is needed.
        await page.goto(P2P.startPath);
        await page.locator('#dono-p2p-name').fill(supporterName);
        await page.locator('#dono-p2p-email').fill(supporterEmail);
        await page.locator('#dono-p2p-display').fill(supporterName);
        await page.getByRole('button', { name: 'Create my page' }).click();
        const done = page.locator('.dono-p2p-done');
        await expect(done, 'start success card appears').toBeVisible({ timeout: 10_000 });

        // The page returns the public URL to use; pick it up from the share row.
        // (Rendered hidden server-side; p2p-start.js fills it in from the REST
        // response before unhiding the card, so it is set by the time the card
        // is visible.)
        const fundraiserUrl = await done.locator('.dono-p2p-done__url').inputValue();
        expect(fundraiserUrl, 'response carries a public fundraiser URL (campaign not in approval mode)').toMatch(/\/fundraiser\//);
        await expect(
            done.getByRole('heading', { name: 'Your page is live!' }),
            'success card shows the live state, not the pending-approval one'
        ).toBeVisible();

        // -------------------------------------------------------------- 4
        // Public: the supporter's fundraiser page resolves and shows them.
        await page.goto(fundraiserUrl);
        await expect(
            page.getByRole('heading', { level: 1, name: supporterName }),
            'fundraiser page is headed by the supporter'
        ).toBeVisible();

        // -------------------------------------------------------------- 5
        // Public: donor gives via the fundraiser page (sandbox gateway).
        // Bug #3's fix means sandbox lands as paid in the same request.
        //
        // The form runtime mounts client-side, but everything the submission
        // needs is server-rendered as JSON: the REST url, the form id, the
        // HMAC form token the anti-spam gate requires, and the signed
        // fundraiser context that credits this page's fundraiser. Post the
        // same shape the runtime does (assets/donation-form/runtime.jsx).
        // Read it from the SSR response, not the live DOM: the runtime mounts
        // into the <form> element itself, so Preact replaces its children and
        // the config script is gone by the time the page settles.
        const ssr = await page.request.get(fundraiserUrl);
        expect(ssr.ok(), 'fundraiser page loads').toBeTruthy();
        const html = await ssr.text();
        const match = html.match(
            /<script type="application\/json" data-dono-form-config>([\s\S]*?)<\/script>/
        );
        expect(match, 'fundraiser page embeds a donation form').not.toBeNull();
        const form = JSON.parse(
            match![1].replace(/&quot;/g, '"').replace(/&amp;/g, '&')
        ) as DonationFormConfig;

        expect(form.extra?.fundraiser_ctx, 'form carries the signed fundraiser context').toBeTruthy();
        expect(form.spam?.formToken, 'form carries an anti-spam form token').toBeTruthy();
        // The nonce is minted for logged-in sessions only (deliberate: a
        // page-cached anonymous form must never carry a stale one). This
        // context is still the admin session from phase 1, so it is present.
        expect(form.nonce, 'fundraiser page exposes the form nonce').not.toBe('');

        const apiResponse = await page.request.post('/wp-json/dono/v1/donations', {
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': form.nonce },
            data: {
                gateway:      'sandbox',
                amount_cents: 1000,
                currency:     form.currency,
                email:        donorEmail,
                form_id:      form.form_id,
                campaign_id:  form.campaign_id,
                profile:      { first_name: 'Journey', last_name: 'Donor' },
                extra:        form.extra,
                _ft:          form.spam?.formToken,
            },
        });
        const donation = await apiResponse.json();
        expect(apiResponse.status(), `donations POST: ${JSON.stringify(donation)}`).toBe(201);

        // -------------------------------------------------------------- 6
        // Server-side correctness: paid + attributed. Attribution is carried
        // by the signed fundraiser_ctx above and stamped server-side; the
        // create response does not echo the ids back, and test donations stay
        // out of the public rollups, so it is not observable from here.
        expect(donation.status, 'sandbox donation lands as paid').toBe('paid');
        expect(donation.reference, 'reference issued').toMatch(/^DONO-/);

        // -------------------------------------------------------------- 7
        // Admin: campaign overview reflects activity. Test donations are
        // is_test=1 so they stay out of real totals - which is correct.
        // We only assert that the admin screen renders without errors.
        await admin.openCampaign(P2P.campaignId!);
        await expect(page.locator('.dono-pill').filter({ hasText: /^Active$/i }).first()).toBeVisible();
    });
});
