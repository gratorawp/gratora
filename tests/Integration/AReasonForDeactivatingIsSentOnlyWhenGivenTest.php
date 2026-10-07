<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use DateTimeImmutable;
use DOMDocument;
use DOMXPath;
use Gratora\Admin\DeactivationDialog;
use Gratora\Admin\DeactivationSurvey;
use Gratora\Campaigns\CampaignService;
use Gratora\Core\Activator;
use Gratora\Dashboard\FirstRun;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\FrozenClock;
use Gratora\Foundation\Uninstall\DataEraser;
use WP_Error;
use WPAjaxDieContinueException;
use WPAjaxDieStopException;

/**
 * The dialog shown on Deactivate asks why. An answer goes to gratora.net only
 * when one is picked, in a request of its own that deactivation does not wait
 * for, and it carries nothing that names the site.
 */
final class AReasonForDeactivatingIsSentOnlyWhenGivenTest extends IntegrationTestCase
{
    private const CHOICE = 'gratora_deactivation_choice';
    private const REASON = 'gratora_deactivation_reason';

    /** @var list<array{url:string,args:array<string,mixed>}> */
    private array $sent = [];

    /** What gratora.net answers. */
    private mixed $answer = [
        'response' => ['code' => 201, 'message' => 'Created'],
        'body'     => '{"received":true}',
        'headers'  => [],
        'cookies'  => [],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        update_option('gratora_gateway_config', ['test_mode' => true]);
        update_option(Activator::OPT_ACTIVATED_AT, '2026-09-25T10:00:00+00:00');

        add_filter('pre_http_request', function ($pre, array $args, string $url) {
            $this->sent[] = ['url' => $url, 'args' => $args];

            return $this->answer;
        }, 10, 3);

        add_filter('wp_doing_ajax', '__return_true');
        add_filter('wp_die_ajax_handler', static fn (): callable => static function ($message): void {
            // WordPress refuses a bad nonce with -1 and nothing else.
            if ((string) $message === '-1') {
                throw new WPAjaxDieStopException('-1');
            }
            throw new WPAjaxDieContinueException((string) $message);
        });
    }

    private function dialog(): DeactivationDialog
    {
        return new DeactivationDialog(static fn (): DeactivationSurvey => new DeactivationSurvey(
            Plugin::instance()->container->get(FirstRun::class),
            new FrozenClock(new DateTimeImmutable('2026-10-07T12:00:00+00:00')),
        ));
    }

    /**
     * @param array<string,string> $post what the dialog posts
     *
     * @return array<string,mixed> what the site answers it, empty when it refuses outright
     */
    private function post(string $action, array $post, ?string $nonce = null): array
    {
        $_POST = $_REQUEST = $post + ['_wpnonce' => $nonce ?? wp_create_nonce($action)];

        ob_start();
        try {
            $action === self::REASON ? $this->dialog()->tell() : $this->dialog()->record();
        } catch (WPAjaxDieContinueException | WPAjaxDieStopException) {
            // The handler answers and stops, as it does for the browser.
        }

        return (array) json_decode((string) ob_get_clean(), true);
    }

    /** @param array<string,string> $answer */
    private function tell(array $answer): array
    {
        return $this->post(self::REASON, $answer);
    }

    /** @return array<string,mixed> the one body that went to gratora.net */
    private function body(): array
    {
        $this->assertCount(1, $this->sent, 'one request went out');

        return (array) json_decode((string) $this->sent[0]['args']['body'], true);
    }

    public function test_the_choice_about_the_data_tells_gratora_nothing(): void
    {
        $answer = $this->post(self::CHOICE, ['wipe' => '1', 'reason' => 'broken', 'comment' => 'It broke']);

        $this->assertTrue($answer['success']);
        $this->assertTrue(DataEraser::requested());
        $this->assertSame([], $this->sent);
    }

    public function test_the_choice_about_the_data_is_taken_back_by_the_next_one(): void
    {
        $this->post(self::CHOICE, ['wipe' => '1']);
        $this->post(self::CHOICE, []);

        $this->assertFalse(DataEraser::requested());
    }

    /** @return array<string,array{0:array<string,string>}> */
    public function answersThatAreNone(): array
    {
        return [
            'no reason'                 => [[]],
            'words without a reason'    => [['comment' => 'It broke']],
            'a reason not on the list'  => [['reason' => 'because', 'comment' => 'No such reason']],
            'one that only looks alike' => [['reason' => 'MISSING!']],
        ];
    }

    /**
     * @dataProvider answersThatAreNone
     *
     * @param array<string,string> $answer
     */
    public function test_nothing_is_sent_without_a_reason_from_the_list(array $answer): void
    {
        $this->assertTrue($this->tell($answer)['success']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_picked_reason_goes_to_gratora_with_the_versions_the_days_and_the_setup_steps(): void
    {
        Plugin::instance()->container->get(CampaignService::class)->create(['title' => 'Spring appeal', 'status' => 'draft']);

        $this->tell(['reason' => 'missing', 'comment' => "Direct debit\nfor regular donors"]);

        $this->assertSame([
            'version'     => 1,
            'reason'      => 'missing',
            'comment'     => "Direct debit\nfor regular donors",
            'gratora'     => GRATORA_VERSION,
            'wordpress'   => wp_get_wp_version(),
            'php'         => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
            'days_active' => 12,
            'setup'       => ['page' => true, 'test_donation' => false, 'payments' => false, 'donation' => false],
        ], $this->body());

        $this->assertSame(DeactivationSurvey::ENDPOINT, $this->sent[0]['url']);
        $this->assertSame('https://gratora.net/wp-json/gratora-license/v1/deactivations', DeactivationSurvey::ENDPOINT);
        $this->assertSame('POST', $this->sent[0]['args']['method']);
        $this->assertSame('application/json', $this->sent[0]['args']['headers']['Content-Type']);
    }

    public function test_the_request_carries_neither_the_site_s_address_nor_anyone_on_it(): void
    {
        update_option('blogname', 'River Trust');

        $this->tell(['reason' => 'other', 'comment' => 'Trying something else']);

        $request = $this->sent[0]['args'];
        $this->assertSame('Gratora/' . GRATORA_VERSION, $request['user-agent']);

        $wire = (string) wp_json_encode([$request['user-agent'], $request['headers'], $request['body'], $request['cookies']]);
        foreach ([
            (string) wp_parse_url(home_url(), PHP_URL_HOST),
            (string) get_option('admin_email'),
            wp_get_current_user()->user_email,
            wp_get_current_user()->user_login,
            'River Trust',
        ] as $private) {
            $this->assertStringNotContainsString($private, $wire);
        }
    }

    public function test_the_request_gives_up_after_three_seconds_follows_gratora_nowhere_and_reads_little_back(): void
    {
        $this->tell(['reason' => 'broken']);

        $request = $this->sent[0]['args'];
        $this->assertLessThanOrEqual(3, $request['timeout']);
        $this->assertSame(0, $request['redirection']);
        $this->assertLessThanOrEqual(1024, $request['limit_response_size']);
    }

    public function test_the_words_are_sent_as_they_were_typed(): void
    {
        $typed = "Amounts < 5 don't work & <b>bold</b> 50%after\nif a<b then c>d";

        $this->tell(['reason' => 'broken', 'comment' => wp_slash($typed)]);

        $this->assertSame($typed, $this->body()['comment']);
    }

    public function test_the_words_lose_only_what_is_not_text(): void
    {
        $this->tell(['reason' => 'broken', 'comment' => "  first\r\nsecond\x00\x07\tthird \xC3\x28 "]);

        $this->assertSame("first\nsecond\tthird \u{FFFD}(", $this->body()['comment']);
    }

    public function test_a_long_comment_is_cut_to_five_hundred_characters(): void
    {
        $this->tell(['reason' => 'broken', 'comment' => str_repeat('é', 600)]);

        $this->assertSame(str_repeat('é', 500), $this->body()['comment']);
    }

    // The store refuses a body over four kilobytes, and an escaped emoji is twelve bytes.
    public function test_five_hundred_characters_of_any_kind_fit_in_what_the_store_accepts(): void
    {
        $this->tell(['reason' => 'broken', 'comment' => str_repeat('🙂', 500)]);

        $this->assertLessThan(4096, strlen((string) $this->sent[0]['args']['body']));
        $this->assertSame(str_repeat('🙂', 500), $this->body()['comment']);
    }

    public function test_a_reason_that_asks_nothing_more_sends_no_comment(): void
    {
        $this->tell(['reason' => 'temporary', 'comment' => 'Left over from another pick']);

        $this->assertSame('', $this->body()['comment']);
    }

    /** @return array<string,array{0:mixed}> */
    public function activationDatesThatSayNothing(): array
    {
        return [
            'never recorded' => [null],
            'not a date'     => ['soon'],
            'a zero date'    => ['0000-00-00T00:00:00+00:00'],
            'in the future'  => ['2027-01-01T00:00:00+00:00'],
        ];
    }

    /** @dataProvider activationDatesThatSayNothing */
    public function test_a_site_that_cannot_say_when_it_was_first_switched_on_counts_no_days(mixed $stored): void
    {
        $stored === null ? delete_option(Activator::OPT_ACTIVATED_AT) : update_option(Activator::OPT_ACTIVATED_AT, $stored);

        $this->tell(['reason' => 'not_needed']);

        $this->assertSame(0, $this->body()['days_active']);
    }

    // A site with a broken table is the one whose answer matters most.
    public function test_a_site_that_cannot_work_out_its_setup_still_sends_its_answer(): void
    {
        add_filter('query', static fn (string $sql): string => str_contains($sql, 'gratora_campaigns') ? 'SELECT nothing FROM a_table_that_is_gone' : $sql);
        $GLOBALS['wpdb']->suppress_errors(true);

        $answer = $this->tell(['reason' => 'broken', 'comment' => 'Every screen is blank']);

        $GLOBALS['wpdb']->suppress_errors(false);

        $this->assertTrue($answer['success']);
        $this->assertNull($this->body()['setup']);
        $this->assertSame('Every screen is blank', $this->body()['comment']);
    }

    public function test_the_site_answers_the_dialog_even_when_gratora_cannot_be_reached(): void
    {
        $this->answer = new WP_Error('http_request_failed', 'Could not resolve host: gratora.net');

        $this->assertTrue($this->tell(['reason' => 'broken', 'comment' => 'It broke'])['success']);
        $this->assertCount(1, $this->sent);
    }

    public function test_someone_who_may_not_switch_plugins_off_sends_nothing(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $this->assertFalse($this->tell(['reason' => 'broken'])['success']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_request_the_dialog_did_not_make_sends_nothing(): void
    {
        $this->assertSame([], $this->post(self::REASON, ['reason' => 'broken'], 'not-the-dialog-s-nonce'));
        $this->assertSame([], $this->post(self::REASON, ['reason' => 'broken'], wp_create_nonce(self::CHOICE)));
        $this->assertSame([], $this->sent);
    }

    // Bulk deactivation and WP-CLI go straight here, past the dialog.
    public function test_switching_the_plugin_off_by_any_other_route_tells_gratora_nothing(): void
    {
        Plugin::onDeactivation();

        $this->assertSame([], $this->sent);
    }

    private function rendered(): string
    {
        ob_start();
        $this->dialog()->renderDialog();

        return (string) ob_get_clean();
    }

    /** @return list<string> the reasons the dialog offers, in order */
    private function offered(): array
    {
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>' . $this->rendered(), LIBXML_NOERROR);

        $offered = [];
        foreach ((new DOMXPath($document))->query('//input[@type="radio"][@name="gratora-deact-reason"]') as $radio) {
            $this->assertFalse($radio->hasAttribute('checked'));
            $offered[] = $radio->getAttribute('value');
        }

        return $offered;
    }

    public function test_the_dialog_offers_the_seven_reasons_with_none_picked(): void
    {
        $this->assertStringContainsString('gratora.net', $this->rendered());
        $this->assertSame(
            ['temporary', 'setup', 'broken', 'missing', 'another_plugin', 'not_needed', 'other'],
            $this->offered()
        );
        $this->assertSame($this->offered(), array_keys(DeactivationSurvey::reasons()));
    }

    // Its setup and its days are one site's, and a network holds many.
    public function test_the_network_s_plugins_screen_is_not_asked(): void
    {
        set_current_screen('plugins-network');

        $this->assertSame([], $this->offered());
        $this->assertStringNotContainsString('gratora.net', $this->rendered());
    }

    public function test_a_site_can_switch_the_question_off(): void
    {
        add_filter('gratora.deactivation.ask_why', '__return_false');

        $this->assertSame([], $this->offered());
        $this->assertStringNotContainsString('gratora.net', $this->rendered());
        $this->assertTrue($this->tell(['reason' => 'broken'])['success']);
        $this->assertSame([], $this->sent);
    }
}
