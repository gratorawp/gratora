import { test, expect } from '@playwright/test';

/**
 * Re-opening a magic link from the mailbox is routine, and the token behind it
 * is single use: the second open answers 401 while the session cookie the first
 * open set is still valid. The portal has to keep the donor signed in on that
 * second open rather than showing the sign-in screen.
 *
 * Needs its own fresh single-use link in DONO_E2E_PORTAL_REOPEN_URL (the p2p
 * portal spec consumes DONO_E2E_PORTAL_URL, so the two cannot share one); the
 * donor's admin profile shows one.
 */
test.describe('Donor portal magic link', () => {
    test('re-opening the same link keeps the donor signed in', async ({ page }) => {
        const link = process.env.DONO_E2E_PORTAL_REOPEN_URL;
        test.skip(! link, 'set DONO_E2E_PORTAL_REOPEN_URL to a fresh single-use portal link');

        await page.goto(link!);

        const tabs = page.getByRole('tab', { name: 'Overview' });
        await expect(tabs).toBeVisible({ timeout: 15_000 });

        // Same context, so the cookie from the first exchange rides along while
        // the token in the URL is already spent.
        await page.goto(link!);

        await expect(tabs).toBeVisible({ timeout: 15_000 });
        await expect(page.getByRole('button', { name: 'Send sign-in link' })).toBeHidden();
        await expect(page.getByText('Sign-in link is invalid or expired.')).toBeHidden();
    });
});
