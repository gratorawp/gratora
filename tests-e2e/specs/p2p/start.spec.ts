import { test, expect, P2P } from '../../fixtures/p2p';
import { P2pStartPage } from '../../helpers/P2pStartPage';

/**
 * The public "start fundraising" page interactivity. This page regressed twice
 * when the single block was split into hero/form/steps/faq sections (the script
 * was scoped to the first .dono-p2p-start wrapper instead of the document), so
 * every interactive control gets a guard here.
 */
test.describe('P2P start page', () => {
    test('renders the form with solo selected by default', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        await expect(start.form).toBeVisible();
        await expect(start.segOption('solo')).toHaveClass(/is-active/);
        await expect(start.choiceValue()).toHaveValue('solo');
        // Neither team reveal is shown until a team choice is made.
        await expect(start.reveal('create')).toBeHidden();
    });

    test('segmented control switches choice and reveals', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        // The "join a team" option only renders when the campaign has teams,
        // which the seed guarantees.
        await expect(start.segOption('join')).toBeVisible();

        await start.chooseSegment('join');
        await expect(start.segOption('join')).toHaveClass(/is-active/);
        await expect(start.segOption('join')).toHaveAttribute('aria-checked', 'true');
        await expect(start.choiceValue()).toHaveValue('join');
        await expect(start.reveal('join')).toBeVisible();
        await expect(start.reveal('create')).toBeHidden();

        await start.chooseSegment('create');
        await expect(start.segOption('create')).toHaveClass(/is-active/);
        await expect(start.choiceValue()).toHaveValue('create');
        await expect(start.reveal('create')).toBeVisible();
        await expect(start.reveal('join')).toBeHidden();
        await expect(start.teamNameInput()).toBeVisible();

        await start.chooseSegment('solo');
        await expect(start.choiceValue()).toHaveValue('solo');
        await expect(start.reveal('join')).toBeHidden();
        await expect(start.reveal('create')).toBeHidden();
    });

    test('keyboard activates a segment', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        await start.segOption('create').focus();
        await healthyPage.keyboard.press('Enter');
        await expect(start.choiceValue()).toHaveValue('create');
        await expect(start.reveal('create')).toBeVisible();
    });

    test('team combo opens, filters and selects', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();
        await start.chooseSegment('join');

        await start.comboInput().click();
        await expect(start.comboMenu()).toBeVisible();

        const seededTeam = start.comboOptions().filter({ hasText: P2P.teamName });
        await expect(seededTeam).toHaveCount(1);

        // Filter to a non-matching term -> empty state, no visible options.
        await start.comboInput().fill('zzzznomatch');
        await expect(start.combo().locator('.dps-combo__empty')).toBeVisible();

        // Filter back to the seeded team and pick it.
        await start.comboInput().fill(P2P.teamName.slice(0, 4));
        await expect(seededTeam).toBeVisible();
        await seededTeam.click();
        await expect(start.comboInput()).toHaveValue(P2P.teamName);
        await expect(start.teamIdValue()).not.toHaveValue('');
    });

    test('goal chips drive the goal input and back', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        const chip2500 = healthyPage.locator('.dps-chip[data-goal="2500"]');
        await chip2500.click();
        await expect(start.goalInput()).toHaveValue('2500');
        await expect(chip2500).toHaveClass(/is-active/);

        // Typing a matching amount re-activates the corresponding chip.
        await start.goalInput().fill('1000');
        await expect(healthyPage.locator('.dps-chip[data-goal="1000"]')).toHaveClass(/is-active/);
        await expect(healthyPage.locator('.dps-chip[data-goal="2500"]')).not.toHaveClass(/is-active/);
    });

    test('why counter tracks length', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        await expect(start.whyCount()).toHaveText('0');
        await start.whyInput().fill('Running for clean water');
        await expect(start.whyCount()).toHaveText(String('Running for clean water'.length));
    });

    test('faq accordion toggles a closed item', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        const accs = start.accordions();
        const count = await accs.count();
        expect(count).toBeGreaterThan(1);

        // The first item starts open; the second starts closed.
        const second = accs.nth(1);
        await expect(second).not.toHaveClass(/is-open/);
        await second.locator('.dps-acc__q').click();
        await expect(second).toHaveClass(/is-open/);
        await expect(second.locator('.dps-acc__a')).toBeVisible();
        await expect(second.locator('.dps-acc__q')).toHaveAttribute('aria-expanded', 'true');
    });

    test('honours ?choice= deep link', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open(P2P.startPath + (P2P.startPath.includes('?') ? '&' : '?') + 'choice=create');

        await expect(start.choiceValue()).toHaveValue('create');
        await expect(start.reveal('create')).toBeVisible();
    });

    test('honours ?team= deep link', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();
        await start.chooseSegment('join');

        const seededTeam = start.comboOptions().filter({ hasText: P2P.teamName });
        const teamId = await seededTeam.getAttribute('data-id');
        expect(teamId).toBeTruthy();

        await start.open(P2P.startPath + (P2P.startPath.includes('?') ? '&' : '?') + 'team=' + teamId);
        await expect(start.teamIdValue()).toHaveValue(String(teamId));
        await expect(start.comboInput()).toHaveValue(P2P.teamName);
    });

    test.describe('client validation', () => {
        test('requires a valid email', async ({ healthyPage }) => {
            const start = new P2pStartPage(healthyPage);
            await start.open();
            await start.clickSubmit();
            await start.expectError();
            await expect(start.done()).toBeHidden();
        });

        test('join requires a team', async ({ healthyPage }) => {
            const start = new P2pStartPage(healthyPage);
            await start.open();
            await start.emailInput().fill('e2e-validate@dono.test');
            await start.chooseSegment('join');
            await start.clickSubmit();
            await start.expectError();
        });

        test('create requires a team name', async ({ healthyPage }) => {
            const start = new P2pStartPage(healthyPage);
            await start.open();
            await start.emailInput().fill('e2e-validate@dono.test');
            await start.chooseSegment('create');
            await start.clickSubmit();
            await start.expectError();
        });
    });

    // Opt-in: actually creates a fundraiser + donor and sends a welcome email,
    // so it stays off against a shared Local site and runs in hermetic CI.
    test('creates a solo page on submit', async ({ healthyPage }) => {
        test.skip(! process.env.DONO_E2E_P2P_SUBMIT, 'set DONO_E2E_P2P_SUBMIT=1 to run the side-effecting submit');
        const start = new P2pStartPage(healthyPage);
        await start.open();

        const unique = `e2e-submit-${Date.now()}@dono.test`;
        await start.page.locator('#dps-name').fill('E2E Submitter');
        await start.emailInput().fill(unique);
        await start.page.locator('#dps-display').fill(`E2E ${Date.now()}`);
        await start.clickSubmit();

        await expect(start.done()).toBeVisible({ timeout: 15_000 });
        await expect(start.done().locator('[data-dps-url]')).not.toHaveValue('');
    });
});
