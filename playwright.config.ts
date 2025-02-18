import { defineConfig, devices, type Project } from '@playwright/test';

const baseURL = process.env.DONO_E2E_URL ?? 'http://localhost:10075';

// The peer-to-peer specs need the dono-p2p plugin active and the campaign
// seeded (wp dono-p2p e2e-seed, which sets DONO_E2E_P2P_*). Gate them behind a
// dedicated project so a core-only run (or CI without the add-on) never runs
// them unseeded. The core project always runs and ignores specs/p2p.
const runP2p = !! process.env.DONO_E2E_P2P_START_PATH;

const projects: Project[] = [
    {
        name: 'core',
        use: { ...devices['Desktop Chrome'] },
        testIgnore: '**/specs/p2p/**',
    },
];

if (runP2p) {
    projects.push({
        name: 'p2p',
        use: { ...devices['Desktop Chrome'] },
        testMatch: '**/specs/p2p/**',
    });
}

export default defineConfig({
    testDir: './tests-e2e/specs',
    timeout: 30_000,
    fullyParallel: false,
    workers: 1,
    retries: process.env.CI ? 1 : 0,
    reporter: process.env.CI ? 'line' : 'list',
    globalSetup: './tests-e2e/setup/global-setup.ts',
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects,
    outputDir: './test-results',
});
