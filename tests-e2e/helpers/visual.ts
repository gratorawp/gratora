import { type Page } from '@playwright/test';

/**
 * Settle a page before a screenshot: web fonts loaded (a capture racing
 * document.fonts produces metric-different text) and two animation frames so
 * any just-committed React render has painted.
 */
export async function settle(page: Page): Promise<void> {
    await page.evaluate(async () => {
        await document.fonts.ready;
        await new Promise((r) => requestAnimationFrame(() => requestAnimationFrame(r)));
    });
}
