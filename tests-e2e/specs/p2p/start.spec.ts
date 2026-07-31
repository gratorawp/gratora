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
        await expect(healthyPage.getByRole('heading', { name: 'Start fundraising' })).toBeVisible();
        await expect(start.submit()).toHaveText(/create my page/i);

        await expect(start.segOption('solo')).toHaveClass(/is-active/);
        await expect(start.segOption('solo')).toHaveAttribute('aria-checked', 'true');
        await expect(start.choiceValue()).toHaveValue('solo');
        // Neither team reveal is shown until a team choice is made.
        await expect(start.reveal('join')).toBeHidden();
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
        await expect(start.segOption('solo')).toHaveAttribute('aria-checked', 'false');
        await expect(start.choiceValue()).toHaveValue('join');
        await expect(start.reveal('join')).toBeVisible();
        await expect(start.reveal('create')).toBeHidden();
        await expect(start.teamSearch()).toBeVisible();

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

    test('team list filters and selects', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();
        await start.chooseSegment('join');

        // The picker is a browsable list, not a dropdown: every team is on
        // screen as soon as the join reveal opens.
        await expect(start.teamSearch()).toBeVisible();
        const seededTeam = start.teamOption(P2P.teamName);
        await expect(seededTeam).toHaveCount(1);
        await expect(seededTeam).toBeVisible();

        // Filter to a non-matching term -> empty state, no visible options.
        await start.teamSearch().fill('zzzznomatch');
        await expect(start.teamEmpty()).toBeVisible();
        await expect(seededTeam).toBeHidden();

        // Filter back to the seeded team and pick it.
        await start.teamSearch().fill(P2P.teamName.slice(0, 4));
        await expect(start.teamEmpty()).toBeHidden();
        await expect(seededTeam).toBeVisible();
        await seededTeam.click();

        // Selection is carried by the radio + the row's selected styling; the
        // submitted value is the hidden team_id.
        await expect(seededTeam).toHaveClass(/is-selected/);
        await expect(start.teamRadio(P2P.teamName)).toBeChecked();
        await expect(start.teamIdValue()).not.toHaveValue('');
    });

    test('goal presets drive the goal input and back', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        const preset2000 = start.goalPreset(2000);
        await preset2000.click();
        await expect(start.goalInput()).toHaveValue('2000');
        await expect(preset2000).toHaveClass(/is-active/);

        // Typing a matching amount re-activates the corresponding preset.
        await start.goalInput().fill('1000');
        await expect(start.goalPreset(1000)).toHaveClass(/is-active/);
        await expect(preset2000).not.toHaveClass(/is-active/);
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

        const items = start.faqItems();
        const count = await items.count();
        expect(count).toBeGreaterThan(1);

        // The first item starts open; the second starts closed.
        const second = items.nth(1);
        await expect(second).not.toHaveClass(/is-open/);
        await expect(start.faqAnswer(second)).toBeHidden();

        await start.faqQuestion(second).click();
        await expect(second).toHaveClass(/is-open/);
        await expect(start.faqAnswer(second)).toBeVisible();
        await expect(start.faqQuestion(second)).toHaveAttribute('aria-expanded', 'true');
    });

    test('honours ?choice= deep link', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open(P2pStartPage.withQuery(P2P.startPath, 'choice=create'));

        await expect(start.choiceValue()).toHaveValue('create');
        await expect(start.reveal('create')).toBeVisible();
    });

    test('honours ?team= deep link', async ({ healthyPage }) => {
        const start = new P2pStartPage(healthyPage);
        await start.open();

        const teamId = await start.teamRadio(P2P.teamName).getAttribute('value');
        expect(teamId).toBeTruthy();

        // The bare param implies joining. It used to preselect the team while
        // leaving the choice on solo, and the submit handler only sends team_id
        // when the choice is join - so a shared link with only team= created a
        // solo page and silently dropped the team.
        await start.open(P2pStartPage.withQuery(P2P.startPath, 'team=' + teamId));
        await expect(start.teamIdValue()).toHaveValue(String(teamId));
        await expect(start.teamRadio(P2P.teamName)).toBeChecked();
        await expect(start.reveal('join')).toBeVisible();

        // The link a team page actually renders carries the choice too, so the
        // preselected row is on screen and marked.
        await start.open(P2pStartPage.withQuery(P2P.startPath, 'choice=join&team=' + teamId));
        await expect(start.reveal('join')).toBeVisible();
        await expect(start.teamOption(P2P.teamName)).toBeVisible();
        await expect(start.teamOption(P2P.teamName)).toHaveClass(/is-selected/);
        await expect(start.teamIdValue()).toHaveValue(String(teamId));
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
        await start.nameInput().fill('E2E Submitter');
        await start.emailInput().fill(unique);
        await start.displayInput().fill(`E2E ${Date.now()}`);
        await start.clickSubmit();

        await expect(start.done()).toBeVisible({ timeout: 15_000 });
        await expect(start.doneUrl()).not.toHaveValue('');
    });
});
