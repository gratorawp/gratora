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

/**
 * The dialog shown on Deactivate asks why. An answer goes to gratora.net only
 * when one is picked, and it carries nothing that names the site.
 */
final class AReasonForDeactivatingIsSentOnlyWhenGivenTest extends IntegrationTestCase
{
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
     * @param array<string,string> $choice what the dialog posts
     *
     * @return array<string,mixed> what the site answers it
     */
    private function deactivateWith(array $choice): array
    {
        $_POST = $_REQUEST = $choice + ['_wpnonce' => wp_create_nonce('gratora_deactivation_choice')];

        ob_start();
        try {
            $this->dialog()->record();
        } catch (WPAjaxDieContinueException) {
            // The handler answers and stops, as it does for the browser.
        }

        return (array) json_decode((string) ob_get_clean(), true);
    }

    /** @return array<string,mixed> the one body that went to gratora.net */
    private function body(): array
    {
        $this->assertCount(1, $this->sent, 'one request went out');

        return (array) json_decode((string) $this->sent[0]['args']['body'], true);
    }

    public function test_deactivating_without_a_reason_sends_nothing(): void
    {
        $answer = $this->deactivateWith([]);

        $this->assertTrue($answer['success']);
        $this->assertSame([], $this->sent);
    }

    public function test_a_reason_that_is_not_on_the_list_sends_nothing(): void
    {
        $this->deactivateWith(['reason' => 'because', 'comment' => 'No such reason']);

        $this->assertSame([], $this->sent);
    }

    public function test_a_picked_reason_goes_to_gratora_with_the_versions_the_days_and_the_setup_steps(): void
    {
        Plugin::instance()->container->get(CampaignService::class)->create(['title' => 'Spring appeal', 'status' => 'draft']);

        $this->deactivateWith(['reason' => 'missing', 'comment' => "Direct debit\nfor regular donors"]);

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

    public function test_the_request_names_neither_the_site_nor_anyone_on_it(): void
    {
        update_option('blogname', 'River Trust');

        $this->deactivateWith(['reason' => 'other', 'comment' => 'Trying something else']);

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

    public function test_it_does_not_wait_on_gratora_for_long_or_follow_it_elsewhere(): void
    {
        $this->deactivateWith(['reason' => 'broken']);

        $this->assertLessThanOrEqual(3, $this->sent[0]['args']['timeout']);
        $this->assertSame(0, $this->sent[0]['args']['redirection']);
    }

    public function test_a_long_comment_is_cut_to_five_hundred_characters(): void
    {
        $this->deactivateWith(['reason' => 'broken', 'comment' => str_repeat('é', 600)]);

        $this->assertSame(str_repeat('é', 500), $this->body()['comment']);
    }

    public function test_markup_in_a_comment_is_not_sent(): void
    {
        $this->deactivateWith(['reason' => 'broken', 'comment' => 'The <b>form</b> <script>alert(1)</script>never loaded']);

        $this->assertSame('The form never loaded', $this->body()['comment']);
    }

    public function test_a_reason_that_asks_nothing_more_sends_no_comment(): void
    {
        $this->deactivateWith(['reason' => 'temporary', 'comment' => 'Left over from another pick']);

        $this->assertSame('', $this->body()['comment']);
    }

    public function test_a_site_that_never_recorded_when_it_was_switched_on_counts_no_days(): void
    {
        delete_option(Activator::OPT_ACTIVATED_AT);

        $this->deactivateWith(['reason' => 'not_needed']);

        $this->assertSame(0, $this->body()['days_active']);
    }

    public function test_the_delete_choice_is_recorded_even_when_gratora_cannot_be_reached(): void
    {
        $this->answer = new WP_Error('http_request_failed', 'Could not resolve host: gratora.net');

        $answer = $this->deactivateWith(['reason' => 'broken', 'comment' => 'It broke', 'wipe' => '1']);

        $this->assertTrue($answer['success']);
        $this->assertTrue(DataEraser::requested());
        $this->assertCount(1, $this->sent);
    }

    public function test_someone_who_may_not_switch_plugins_off_sends_nothing(): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'editor']));

        $answer = $this->deactivateWith(['reason' => 'broken']);

        $this->assertFalse($answer['success']);
        $this->assertSame([], $this->sent);
    }

    public function test_the_dialog_offers_the_seven_reasons_with_none_picked(): void
    {
        ob_start();
        $this->dialog()->renderDialog();
        $document = new DOMDocument();
        $document->loadHTML('<?xml encoding="utf-8"?>' . ob_get_clean(), LIBXML_NOERROR);

        $offered = [];
        foreach ((new DOMXPath($document))->query('//input[@type="radio"][@name="gratora-deact-reason"]') as $radio) {
            $this->assertFalse($radio->hasAttribute('checked'));
            $offered[] = $radio->getAttribute('value');
        }

        $this->assertSame(
            ['temporary', 'setup', 'broken', 'missing', 'another_plugin', 'not_needed', 'other'],
            $offered
        );
        $this->assertSame($offered, array_keys(DeactivationSurvey::reasons()));
    }
}
