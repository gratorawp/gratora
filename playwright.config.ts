import { defineConfig, devices, type Project } from '@playwright/test';

const baseURL = process.env.DONO_E2E_URL ?? 'http://localhost:10075';

// The peer-to-peer specs need the dono-p2p plugin active and the campaign
// seeded (wp dono-p2p e2e-seed, which sets DONO_E2E_P2P_*). Gate them behind a
// dedicated project so a core-only run (or CI without the add-on) never runs
// them unseeded. The core project always runs and ignores specs/p2p.
const runP2p = !! process.env.DONO_E2E_P2P_START_PATH;

// Visual regression project. Opt-in (DONO_E2E_VISUAL=1) because screenshot
// goldens are rendered on macOS; a default run on another platform would fail
// on missing snapshots rather than catch regressions. Run via npm run
// test:visual / test:visual:update.
const runVisual = !! process.env.DONO_E2E_VISUAL;

const projects: Project[] = [
    {
        name: 'core',
        use: { ...devices['Desktop Chrome'] },
        testIgnore: ['**/specs/p2p/**', '**/specs/visual/**'],
    },
];

if (runP2p) {
    projects.push({
        name: 'p2p',
        use: { ...devices['Desktop Chrome'] },
        testMatch: '**/specs/p2p/**',
        testIgnore: '**/specs/visual/**',
    });
}

if (runVisual) {
    projects.push({
        name: 'visual',
        use: { ...devices['Desktop Chrome'] },
        testMatch: '**/specs/visual/**',
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
    // Goldens are committed; {platform} keeps per-OS renders side by side so a
    // Linux CI runner can grow its own set without clobbering the macOS ones.
    snapshotPathTemplate: '{testDir}/visual/__screenshots__/{platform}/{arg}{ext}',
    expect: {
        toHaveScreenshot: {
            animations: 'disabled',
            caret: 'hide',
            scale: 'css',
            maxDiffPixels: 120,
            stylePath: './tests-e2e/specs/visual/vrt.css',
        },
    },
    use: {
        baseURL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        video: 'off',
    },
    projects,
    outputDir: './test-results',
});
