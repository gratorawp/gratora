import { expect, test as base } from '@playwright/test';

/**
 * Public paths + fixture slugs for the seeded peer-to-peer campaign. Defaults
 * match `wp dono-p2p e2e-seed`; override via env when seeding to different
 * slugs. Keep these in sync with src/Cli/E2eSeed.php in the dono-p2p plugin.
 */
export const P2P = {
    campaignId:     process.env.DONO_E2E_P2P_CAMPAIGN_ID ? Number(process.env.DONO_E2E_P2P_CAMPAIGN_ID) : undefined,
    campaignPath:   process.env.DONO_E2E_P2P_CAMPAIGN_PATH   ?? '/campaigns/p2p-e2e/',
    startPath:      process.env.DONO_E2E_P2P_START_PATH      ?? '/campaigns/p2p-e2e/start/',
    fundraiserPath: process.env.DONO_E2E_P2P_FUNDRAISER_PATH ?? '/campaigns/p2p-e2e/fundraiser/solo-sam/',
    teamPath:       process.env.DONO_E2E_P2P_TEAM_PATH       ?? '/campaigns/p2p-e2e/team/trailblazers/',
    fundraiserName: 'Solo Sam',
    teamName:       'Trailblazers',
};

/**
 * Console + page errors that signal a broken render. The P2P public pages are
 * server-rendered, but they embed the donation-form runtime (which wraps every
 * field in an ErrorBoundary that logs this on componentDidCatch) and ship the
 * start-page vanilla script. Treat either signal as a hard regression.
 */
const RENDER_HEALTH_PATTERN = /render error contained by boundary|ReferenceError/i;

type Fixtures = {
    /** A page that fails the spec on any render-health console/page error. */
    healthyPage: import('@playwright/test').Page;
};

export const test = base.extend<Fixtures>({
    healthyPage: async ({ page }, use, testInfo) => {
        const offences: string[] = [];
        page.on('pageerror', (err) => {
            if (RENDER_HEALTH_PATTERN.test(err.message)) offences.push(`pageerror: ${err.message}`);
        });
        page.on('console', (msg) => {
            if (msg.type() !== 'error') return;
            const text = msg.text();
            if (RENDER_HEALTH_PATTERN.test(text)) offences.push(`console.error: ${text}`);
        });

        await use(page);

        if (offences.length > 0) {
            await testInfo.attach('render-errors.txt', {
                body: offences.join('\n'),
                contentType: 'text/plain',
            });
            expect(offences, 'P2P page rendered without JS render errors').toHaveLength(0);
        }
    },
});

export { expect };
