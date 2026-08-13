import path from 'node:path';
import { type Page } from '@playwright/test';

import { settle } from './visual';

/** Capture output root. Gitignored; override with DONO_E2E_SHOTS_DIR. */
export const SHOTS_DIR = process.env.DONO_E2E_SHOTS_DIR
    ? path.resolve(process.env.DONO_E2E_SHOTS_DIR)
    : path.resolve(__dirname, '..', 'screenshots');

/**
 * Resolve once the DOM has held still for `quietMs`. Recharts animates its
 * paths from JS, so freezing CSS animation does not stop a dashboard chart
 * mid-sweep, and quiescence also covers a REST response that lands after the
 * page reports idle. Resolves rather than throws at the cap, so a screen with
 * something permanently animating is still captured.
 */
export async function waitForQuiet(page: Page, quietMs = 400, capMs = 8_000): Promise<void> {
    await page.evaluate(
        ([quiet, cap]: [number, number]) =>
            new Promise<void>((resolve) => {
                let idle = 0;
                let hard = 0;
                const finish = (): void => {
                    observer.disconnect();
                    clearTimeout(idle);
                    clearTimeout(hard);
                    resolve();
                };
                const observer = new MutationObserver(() => {
                    clearTimeout(idle);
                    idle = window.setTimeout(finish, quiet);
                });
                idle = window.setTimeout(finish, quiet);
                hard = window.setTimeout(finish, cap);
                observer.observe(document.body, {
                    subtree:        true,
                    childList:      true,
                    attributes:     true,
                    characterData:  true,
                });
            }),
        [quietMs, capMs] as [number, number],
    );
}

/** True when only the fragment differs, which does not reload the document. */
function sameDocument(from: string, to: string): boolean {
    if (! from.startsWith('http')) return false;
    const a = new URL(from);
    const b = new URL(to, from);
    return a.origin === b.origin && a.pathname === b.pathname && a.search === b.search;
}

/**
 * Open a wp-admin screen and wait until it is safe to capture. Dono routes
 * several screens by fragment, and a hash-only goto keeps the mounted React
 * tree, so the previous tab's scroll offset would ride into the shot: reload
 * in that case to get a first mount every time.
 */
export async function openAdmin(page: Page, url: string, ready?: string): Promise<void> {
    const hashOnly = sameDocument(page.url(), url);

    await page.goto(url, { waitUntil: 'domcontentloaded' });
    if (hashOnly) await page.reload({ waitUntil: 'domcontentloaded' });

    if (ready) await page.waitForSelector(ready, { state: 'visible' });
    await page.waitForLoadState('networkidle');
    await waitForQuiet(page);
    await settle(page);
}

/** Click a tab that routes in React state rather than the URL, then settle. */
export async function openTab(page: Page, selector: string): Promise<void> {
    await page.locator(selector).first().click();
    await page.waitForLoadState('networkidle');
    await waitForQuiet(page);
    await settle(page);
}

/** First href matching `selector`, or null when the list rendered no rows. */
export async function firstHref(page: Page, selector: string): Promise<string | null> {
    const link = page.locator(selector).first();
    if (await link.count() === 0) return null;
    return link.getAttribute('href');
}

/** Write a capture. Returns the absolute path so a run can report what it made. */
export async function shoot(page: Page, name: string, fullPage = true): Promise<string> {
    await settle(page);
    const file = path.join(SHOTS_DIR, `${name}.png`);
    await page.screenshot({ path: file, fullPage, animations: 'disabled', caret: 'hide' });
    return file;
}
