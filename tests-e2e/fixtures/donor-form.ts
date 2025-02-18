import { expect, test as base } from '@playwright/test';
import { DonorFormPage } from '../helpers/DonorFormPage';

type Fixtures = {
    donor: DonorFormPage;
};

/**
 * Per-spec override of the form path the `donor` fixture opens. Default empty
 * means "use the canonical form at DONO_E2E_FORM_PATH" (DonorFormPage.open()).
 * Set via `test.use({ formPath: process.env.DONO_E2E_SOMETHING ?? '' })` in a
 * spec that needs a different seeded form.
 */
type Options = {
    formPath: string;
};

/**
 * Console + page errors that signal a broken render. The runtime wraps every
 * step + field renderer in an ErrorBoundary that logs this exact message on
 * componentDidCatch; that catch is invisible to the donor but silently turns
 * fields into empty wrappers (e.g. the setField scope bug fixed in 7d6c64b).
 * Treat its presence in any spec as a hard regression.
 */
const RENDER_HEALTH_PATTERN = /render error contained by boundary|ReferenceError/i;

export const test = base.extend<Fixtures & Options>({
    formPath: ['', { option: true }],
    donor: async ({ page, formPath }, use, testInfo) => {
        const offences: string[] = [];
        page.on('pageerror', (err) => {
            if (RENDER_HEALTH_PATTERN.test(err.message)) offences.push(`pageerror: ${err.message}`);
        });
        page.on('console', (msg) => {
            if (msg.type() !== 'error') return;
            const text = msg.text();
            if (RENDER_HEALTH_PATTERN.test(text)) offences.push(`console.error: ${text}`);
        });

        const donor = new DonorFormPage(page);
        await donor.open(formPath !== '' ? formPath : undefined);
        await use(donor);

        // Surface render-health offences after the spec body so the failure
        // mode is visible even when the body assertions pass.
        if (offences.length > 0) {
            // Attach for debugging.
            await testInfo.attach('render-errors.txt', {
                body: offences.join('\n'),
                contentType: 'text/plain',
            });
            expect(offences, 'donor form rendered without React error-boundary catches').toHaveLength(0);
        }
    },
});

export { expect };
