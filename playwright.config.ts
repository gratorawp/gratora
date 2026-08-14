import { defineConfig, devices, type Project } from '@playwright/test';

const baseURL = process.env.DONO_E2E_URL ?? 'http://localhost:10075';

// Visual regression project. Opt-in (DONO_E2E_VISUAL=1) because screenshot
// goldens are rendered on macOS; a default run on another platform would fail
// on missing snapshots rather than catch regressions. Run via npm run
// test:visual / test:visual:update.
const runVisual = !! process.env.DONO_E2E_VISUAL;

// wp-admin screenshot capture (specs/screenshots). Writes PNGs instead of
// asserting, so it is opt-in too: nothing in a normal run wants the minutes it
// spends walking every admin screen. Run via npm run test:shots.
const runShots = !! process.env.DONO_E2E_SHOTS;

const projects: Project[] = [
    {
        name: 'core',
        use: { ...devices['Desktop Chrome'] },
        testIgnore: ['**/specs/visual/**', '**/specs/screenshots/**'],
    },
];

if (runVisual) {
    projects.push({
        name: 'visual',
        use: { ...devices['Desktop Chrome'] },
        testMatch: '**/specs/visual/**',
    });
}

if (runShots) {
    projects.push({
        name: 'screenshots',
        // Fixed viewport so a capture set is comparable run to run, and 2x so
        // the images survive being scaled down for a listing or a doc.
        use: {
            ...devices['Desktop Chrome'],
            viewport:           { width: 1440, height: 900 },
            deviceScaleFactor:  2,
        },
        testMatch: '**/specs/screenshots/**',
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
