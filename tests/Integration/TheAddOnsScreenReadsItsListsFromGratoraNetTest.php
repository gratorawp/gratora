<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use DateTimeImmutable;
use DateTimeZone;
use Gratora\Admin\Addons\AddonsCatalog;
use Gratora\Admin\Pages\AddonsPage;
use Gratora\Foundation\Container\Container;
use Gratora\Foundation\License\LicenseService;
use Gratora\Foundation\Modules\GratoraModule;
use Gratora\Foundation\Modules\ModuleManager;
use Gratora\Foundation\Time\FrozenClock;
use WP_Error;
use WP_Scripts;

/**
 * The Add-ons screen asks gratora.net for its cards, plans and offer when it
 * is opened, shows nothing it has not checked, and falls back to the list the
 * plugin ships with.
 */
final class TheAddOnsScreenReadsItsListsFromGratoraNetTest extends IntegrationTestCase
{
    private const NOW = '2026-10-06 12:00:00';

    /** @var list<array{url:string,args:array<string,mixed>}> */
    private array $requests = [];

    private ?WP_Scripts $scriptsBefore = null;

    protected function setUp(): void
    {
        parent::setUp();
        remove_all_filters('gratora.addons.remote');
        $this->requests       = [];
        $this->scriptsBefore  = $GLOBALS['wp_scripts'] ?? null;
        $GLOBALS['wp_scripts'] = new WP_Scripts();
    }

    protected function tearDown(): void
    {
        $GLOBALS['wp_scripts'] = $this->scriptsBefore;
        remove_all_filters('pre_http_request');
        remove_all_filters('gratora.addons.remote');
        parent::tearDown();
    }

    /** @param array<string,mixed>|WP_Error $response */
    private function gratoraNetAnswers(array|WP_Error $response): void
    {
        remove_all_filters('pre_http_request');
        add_filter('pre_http_request', function ($pre, array $args, string $url) use ($response) {
            $this->requests[] = ['url' => $url, 'args' => $args];

            return $response;
        }, 10, 3);
    }

    /** @param array<string,mixed> $feed */
    private function gratoraNetSends(array $feed): void
    {
        $this->gratoraNetAnswers(self::ok((string) wp_json_encode($feed)));
    }

    /** @return array<string,mixed> */
    private static function ok(string $body, int $code = 200): array
    {
        return ['response' => ['code' => $code, 'message' => ''], 'body' => $body, 'headers' => [], 'cookies' => []];
    }

    /**
     * @param  array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function card(string $slug, array $over = []): array
    {
        return array_merge([
            'slug'        => $slug,
            'file'        => 'gratora-' . $slug . '.php',
            'name'        => ucfirst($slug),
            'description' => 'What ' . $slug . ' does.',
            'icon'        => 'ticket',
            'url'         => 'https://gratora.net/add-ons/' . $slug . '/',
            'free'        => false,
        ], $over);
    }

    /**
     * @param  list<string>        $addons
     * @param  array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function plan(string $slug, array $addons, array $over = []): array
    {
        return array_merge([
            'slug'   => $slug,
            'name'   => ucfirst($slug),
            'sites'  => 1,
            'addons' => $addons,
            'url'    => 'https://gratora.net/pricing/',
        ], $over);
    }

    /**
     * @param  array<string,mixed> $over
     * @return array<string,mixed>
     */
    private static function feed(array $over = []): array
    {
        return array_merge(['version' => 1, 'addons' => [self::card('events'), self::card('tributes')]], $over);
    }

    private function page(?LicenseService $license = null, string $now = self::NOW): AddonsPage
    {
        return new AddonsPage(
            new AddonsCatalog(new FrozenClock(new DateTimeImmutable($now, new DateTimeZone('UTC')))),
            $license ?? new LicenseService()
        );
    }

    /** @return array<string,mixed> */
    private function screen(?LicenseService $license = null, string $now = self::NOW): array
    {
        return $this->page($license, $now)->screen();
    }

    /** @return array<string, array<string,mixed>> */
    private function cards(): array
    {
        return array_column($this->screen()['addons'], null, 'slug');
    }

    private function aSiteWithAPaidAddOn(): LicenseService
    {
        $module = new class () implements GratoraModule {
            public function id(): string { return 'fake'; }
            public function name(): string { return 'Fake Add-on'; }
            public function version(): string { return '1.0.0'; }
            public function requires(): array { return []; }
            public function isLicensed(): bool { return true; }
            public function tier(): string { return GratoraModule::TIER_PRO; }
            public function boot(Container $container): void {}
            public function migrations(): array { return []; }
        };

        $modules = new ModuleManager(new Container());
        $modules->register($module);
        $modules->bootAll();

        return new LicenseService($modules);
    }

    public function test_the_screen_shows_what_gratora_net_sent_in_the_order_it_was_sent(): void
    {
        $this->gratoraNetSends(self::feed(['addons' => [self::card('tributes'), self::card('events')]]));

        $screen = $this->screen();

        $this->assertSame('remote', $screen['source']);
        $this->assertSame(['tributes', 'events'], array_column($screen['addons'], 'slug'));
        $this->assertSame('What tributes does.', $screen['addons'][0]['description']);
    }

    public function test_the_lists_are_asked_for_once_a_day(): void
    {
        $this->gratoraNetSends(self::feed());

        $this->screen();
        $second = $this->screen();

        $this->assertCount(1, $this->requests);
        $this->assertSame('remote', $second['source']);
        $this->assertEqualsWithDelta(time() + DAY_IN_SECONDS, (int) get_option('_transient_timeout_gratora_addons_catalog'), 5);
    }

    /** @return array<string, array{mixed}> */
    public function whatAnotherVersionMayHaveKept(): array
    {
        return [
            'cards and nothing else'      => [['addons' => [self::card('connect')]]],
            'a shape this version is not' => [['shape' => 99, 'addons' => [self::card('connect')], 'plans' => 'three', 'offer' => 'yes']],
            'a list of cards'             => [[self::card('connect')]],
            'text'                        => ['{"addons":[]}'],
        ];
    }

    /** @dataProvider whatAnotherVersionMayHaveKept */
    public function test_what_another_version_kept_is_not_trusted(mixed $kept): void
    {
        set_transient('gratora_addons_catalog', $kept, DAY_IN_SECONDS);
        $this->gratoraNetSends(self::feed(['plans' => [self::plan('standard', ['events'])]]));

        $screen = $this->screen();

        $this->assertCount(1, $this->requests);
        $this->assertSame(['events', 'tributes'], array_column($screen['addons'], 'slug'));
        $this->assertSame(['standard'], array_column($screen['plans'], 'slug'));
    }

    public function test_a_card_gratora_net_sent_is_matched_to_the_plugin_the_site_has(): void
    {
        wp_cache_set('plugins', ['' => [
            'gratora-events/gratora-events.php'       => ['Name' => 'Events', 'Version' => '1.0.0'],
            'gratora-tributes-main/gratora-tributes.php' => ['Name' => 'Tributes', 'Version' => '1.0.0'],
        ]], 'plugins');
        update_option('active_plugins', ['gratora-tributes-main/gratora-tributes.php']);
        $this->gratoraNetSends(self::feed(['addons' => [self::card('events'), self::card('tributes'), self::card('connect')]]));

        $cards = $this->cards();
        wp_cache_delete('plugins', 'plugins');

        $this->assertSame(['installed', 'active', 'available'], [$cards['events']['status'], $cards['tributes']['status'], $cards['connect']['status']]);
        $this->assertStringContainsString('plugin=gratora-events%2Fgratora-events.php', $cards['events']['activateUrl']);
        $this->assertArrayNotHasKey('file', $cards['events']);
    }

    /** @return array<string, array{array<string,mixed>|WP_Error}> */
    public function answersThatCannotBeUsed(): array
    {
        return [
            'no answer in time'        => [new WP_Error('http_request_failed', 'cURL error 28: Operation timed out')],
            'a server error'           => [self::ok('{"version":1,"addons":[]}', 500)],
            'a redirect'               => [self::ok('', 302)],
            'not json'                 => [self::ok('<html>Maintenance</html>')],
            'a list, not an object'    => [self::ok('[1,2,3]')],
            'a version it cannot read' => [self::ok((string) wp_json_encode(self::feed(['version' => 2])))],
            'no version'               => [self::ok((string) wp_json_encode(['addons' => [self::card('events')]]))],
            'more than it will read'   => [self::ok((string) wp_json_encode(self::feed(['padding' => str_repeat('x', 70000)])))],
            'no card that can be shown' => [self::ok((string) wp_json_encode(self::feed(['addons' => [self::card('events', ['url' => 'https://example.com/'])]])))],
            'cards that are no list'   => [self::ok((string) wp_json_encode(self::feed(['addons' => 'all of them'])))],
        ];
    }

    /**
     * @dataProvider answersThatCannotBeUsed
     * @param array<string,mixed>|WP_Error $answer
     */
    public function test_a_site_that_gets_no_usable_answer_shows_the_list_it_ships_with(array|WP_Error $answer): void
    {
        $this->gratoraNetAnswers($answer);

        $screen = $this->screen();

        $this->assertSame('builtin', $screen['source']);
        $this->assertSame([], $screen['plans']);
        $this->assertNull($screen['offer']);
        $this->assertCount(10, $screen['addons']);
        $this->assertContains('donation-recovery', array_column($screen['addons'], 'slug'));
        $this->assertSame(['', ''], [$screen['addons'][0]['plan'], $screen['addons'][9]['plan']]);

        $this->screen();

        $this->assertCount(1, $this->requests, 'A failed request was repeated on the next visit.');
        $this->assertEqualsWithDelta(time() + HOUR_IN_SECONDS, (int) get_option('_transient_timeout_gratora_addons_catalog'), 5);
    }

    /** @return array<string, array{array<string,mixed>|string|int}> */
    public function cardsThatBreakARule(): array
    {
        return [
            'a link to another site'     => [self::card('events', ['url' => 'https://example.com/add-ons/events/'])],
            'a link to a lookalike site' => [self::card('events', ['url' => 'https://gratora.net.example.com/'])],
            'a link that carries a login' => [self::card('events', ['url' => 'https://gratora.net@example.com/'])],
            'a link without https'       => [self::card('events', ['url' => 'http://gratora.net/add-ons/events/'])],
            'a link without a scheme'    => [self::card('events', ['url' => '//gratora.net/add-ons/events/'])],
            'a link with a login for the site' => [self::card('events', ['url' => 'https://someone@gratora.net/add-ons/events/'])],
            'a link to another port'     => [self::card('events', ['url' => 'https://gratora.net:8443/add-ons/events/'])],
            'a slug with a line break after it' => [self::card('events', ['slug' => "tributes\n"])],
            'a file with a line break after it' => [self::card('events', ['file' => "gratora-events.php\n"])],
            'a script for a link'        => [self::card('events', ['url' => 'javascript:alert(1)'])],
            'a link too long'            => [self::card('events', ['url' => 'https://gratora.net/' . str_repeat('a', 200)])],
            'a file outside the pattern' => [self::card('events', ['file' => '../../wp-config.php'])],
            'a file of another plugin'   => [self::card('events', ['file' => 'akismet.php'])],
            'a slug with a path in it'   => [self::card('events', ['slug' => 'events/../x'])],
            'no name'                    => [self::card('events', ['name' => ''])],
            'a name too long'            => [self::card('events', ['name' => str_repeat('n', 61)])],
            'a name that is no text'     => [self::card('events', ['name' => ['Events']])],
            'a description too long'     => [self::card('events', ['description' => str_repeat('d', 201)])],
            'no description'             => [array_diff_key(self::card('events'), ['description' => 1])],
            'no link'                    => [array_diff_key(self::card('events'), ['url' => 1])],
            'a card that is no card'     => ['events'],
            'a number for a card'        => [7],
        ];
    }

    /**
     * @dataProvider cardsThatBreakARule
     * @param array<string,mixed>|string|int $card
     */
    public function test_a_card_that_breaks_a_rule_is_dropped_and_the_rest_are_shown(array|string|int $card): void
    {
        $this->gratoraNetSends(self::feed(['addons' => [$card, self::card('tributes')]]));

        $screen = $this->screen();

        $this->assertSame('remote', $screen['source']);
        $this->assertSame(['tributes'], array_column($screen['addons'], 'slug'));
    }

    public function test_markup_in_what_was_sent_never_reaches_the_screen(): void
    {
        $this->gratoraNetSends(self::feed([
            'addons' => [self::card('events', [
                'name'        => '<b>Event</b> <script>alert(1)</script>Tickets',
                'description' => "Sell <a href=\"https://example.com\">tickets</a>\n to events.",
            ])],
        ]));

        $events = $this->cards()['events'];

        $this->assertSame('Event Tickets', $events['name']);
        $this->assertSame('Sell tickets to events.', $events['description']);
    }

    public function test_a_slug_sent_twice_is_shown_once(): void
    {
        $this->gratoraNetSends(self::feed(['addons' => [self::card('events'), self::card('events', ['name' => 'Again'])]]));

        $screen = $this->screen();

        $this->assertCount(1, $screen['addons']);
        $this->assertSame('Events', $screen['addons'][0]['name']);
    }

    public function test_only_the_first_thirty_cards_are_read(): void
    {
        $cards = [];
        for ($i = 1; $i <= 35; $i++) {
            $cards[] = self::card('addon-' . $i);
        }
        $this->gratoraNetSends(self::feed(['addons' => $cards]));

        $slugs = array_column($this->screen()['addons'], 'slug');

        $this->assertCount(30, $slugs);
        $this->assertSame('addon-30', end($slugs));
    }

    public function test_an_icon_the_plugin_does_not_know_does_not_cost_the_card(): void
    {
        $this->gratoraNetSends(self::feed(['addons' => [
            self::card('events', ['icon' => 'party-popper']),
            self::card('tributes', ['icon' => ['rose']]),
            self::card('connect', ['icon' => '"><svg onload=alert(1)>']),
            self::card('gift-aid', ['icon' => "landmark\n"]),
        ]]));

        $cards = $this->cards();

        $this->assertSame('party-popper', $cards['events']['icon']);
        $this->assertSame('', $cards['tributes']['icon']);
        $this->assertSame('', $cards['connect']['icon']);
        $this->assertSame('', $cards['gift-aid']['icon']);
    }

    public function test_the_request_says_nothing_about_the_site(): void
    {
        $this->gratoraNetSends(self::feed());

        $this->screen();

        $this->assertCount(1, $this->requests);
        ['url' => $url, 'args' => $args] = $this->requests[0];

        $this->assertSame('https://gratora.net/wp-json/gratora-license/v1/addons', $url);
        $this->assertSame('GET', $args['method']);
        $this->assertSame('Gratora/' . GRATORA_VERSION, $args['user-agent']);
        $this->assertStringNotContainsString((string) wp_parse_url(home_url(), PHP_URL_HOST), (string) wp_json_encode($args));
        $this->assertEmpty($args['body']);
        $this->assertEmpty($args['cookies']);
        $this->assertSame([], array_diff_key((array) $args['headers'], ['Accept' => 1]));
        $this->assertSame(0, $args['redirection']);
        $this->assertSame(65537, $args['limit_response_size']);
        $this->assertTrue($args['reject_unsafe_urls']);
        $this->assertLessThanOrEqual(3, $args['timeout']);
    }

    public function test_only_opening_the_add_ons_screen_asks(): void
    {
        $this->gratoraNetSends(self::feed());
        $page = $this->page();
        $page->register();

        set_current_screen('dashboard');
        apply_filters('gratora.admin.pages', []);
        foreach (['index.php', 'plugins.php', 'toplevel_page_gratora', 'fundraising_page_gratora-settings'] as $hook) {
            do_action('admin_enqueue_scripts', $hook);
        }

        $this->assertSame([], $this->requests, 'Something other than the Add-ons screen asked gratora.net.');

        ob_start();
        $page->render();
        ob_end_clean();

        $this->assertCount(1, $this->requests);
        $this->assertStringContainsString('"source":"remote"', (string) wp_scripts()->get_data('gratora-admin-addons', 'data'));
    }

    public function test_a_site_can_turn_the_request_off(): void
    {
        $this->gratoraNetSends(self::feed());
        add_filter('gratora.addons.remote', '__return_false');

        $screen = $this->screen();

        $this->assertSame([], $this->requests);
        $this->assertSame('builtin', $screen['source']);
        $this->assertCount(10, $screen['addons']);
    }

    public function test_a_plan_comes_ready_to_show_and_counts_the_cards_on_the_screen(): void
    {
        $this->gratoraNetSends(self::feed(['plans' => [
            self::plan('standard', ['tributes', 'gift-aid'], ['summary' => 'More ways to take a donation.']),
            self::plan('elite', ['tributes', 'events'], ['sites' => 5, 'url' => 'https://gratora.net/pricing/?utm_source=plugin']),
        ]]));

        $plans = $this->screen()['plans'];

        $this->assertSame([
            ['slug' => 'standard', 'name' => 'Standard', 'summary' => 'More ways to take a donation.', 'sites' => 1, 'count' => 1, 'url' => 'https://gratora.net/pricing/'],
            ['slug' => 'elite', 'name' => 'Elite', 'summary' => '', 'sites' => 5, 'count' => 2, 'url' => 'https://gratora.net/pricing/?utm_source=plugin'],
        ], $plans);
    }

    public function test_amounts_sent_with_a_plan_never_reach_the_screen(): void
    {
        $this->gratoraNetSends(self::feed(['plans' => [
            self::plan('standard', ['events'], ['prices' => ['usd' => 19900], 'first_year_prices' => ['usd' => 9900], 'price_cents' => 19900]),
        ]]));

        $json = (string) wp_json_encode($this->screen());

        $this->assertStringContainsString('"standard"', $json);
        $this->assertStringNotContainsString('9900', $json);
        $this->assertStringNotContainsString('price', $json);
    }

    public function test_a_card_names_the_first_plan_that_includes_it(): void
    {
        $this->gratoraNetSends(self::feed([
            'addons' => [self::card('events'), self::card('tributes'), self::card('give-importer', ['free' => true])],
            'plans'  => [
                self::plan('standard', ['tributes']),
                self::plan('advanced', ['tributes', 'events']),
            ],
        ]));

        $cards = $this->cards();

        $this->assertSame('Standard', $cards['tributes']['plan']);
        $this->assertSame('Advanced', $cards['events']['plan']);
        $this->assertSame('', $cards['give-importer']['plan']);
    }

    /** @return array<string, array{array<string,mixed>|string}> */
    public function plansThatBreakARule(): array
    {
        return [
            'a link to another site'   => [self::plan('standard', ['tributes'], ['url' => 'https://example.com/pricing/'])],
            'no add-on on the screen'  => [self::plan('standard', ['peer-to-peer-fundraising'])],
            'add-ons that are no list' => [self::plan('standard', ['tributes'], ['addons' => 'tributes'])],
            'no name'                  => [self::plan('standard', ['tributes'], ['name' => ''])],
            'a name too long'          => [self::plan('standard', ['tributes'], ['name' => str_repeat('n', 41)])],
            'no sites'                 => [self::plan('standard', ['tributes'], ['sites' => 0])],
            'too many sites'           => [self::plan('standard', ['tributes'], ['sites' => 1001])],
            'half a site'              => [self::plan('standard', ['tributes'], ['sites' => 1.5])],
            'sites written as text'    => [self::plan('standard', ['tributes'], ['sites' => 'five'])],
            'a slug with a space'      => [self::plan('the standard', ['tributes'])],
            'a plan that is no plan'   => ['standard'],
        ];
    }

    /**
     * @dataProvider plansThatBreakARule
     * @param array<string,mixed>|string $plan
     */
    public function test_a_plan_that_breaks_a_rule_is_dropped_and_its_cards_look_to_the_next(array|string $plan): void
    {
        $this->gratoraNetSends(self::feed(['plans' => [$plan, self::plan('advanced', ['tributes', 'events'])]]));

        $screen = $this->screen();

        $this->assertSame(['advanced'], array_column($screen['plans'], 'slug'));
        $this->assertSame('Advanced', array_column($screen['addons'], 'plan', 'slug')['tributes']);
    }

    public function test_a_summary_that_breaks_a_rule_is_left_out_and_the_plan_stays(): void
    {
        $this->gratoraNetSends(self::feed(['plans' => [
            self::plan('standard', ['tributes'], ['summary' => str_repeat('s', 101)]),
            self::plan('advanced', ['events'], ['summary' => ['Everything']]),
            self::plan('elite', ['events'], ['summary' => 'On <b>five</b> sites.']),
        ]]));

        $this->assertSame(['', '', 'On five sites.'], array_column($this->screen()['plans'], 'summary'));
    }

    public function test_only_the_first_six_plans_are_read(): void
    {
        $plans = [];
        for ($i = 1; $i <= 8; $i++) {
            $plans[] = self::plan('plan-' . $i, ['events']);
        }
        $this->gratoraNetSends(self::feed(['plans' => $plans]));

        $this->assertCount(6, $this->screen()['plans']);
    }

    public function test_an_offer_is_shown_with_its_link(): void
    {
        $this->gratoraNetSends(self::feed(['offer' => [
            'text' => 'Every plan is on <i>offer</i> this month.',
            'url'  => 'https://gratora.net/pricing/?utm_source=plugin',
            'ends' => '2026-10-31T23:59:59Z',
        ]]));

        $this->assertSame(
            ['text' => 'Every plan is on offer this month.', 'url' => 'https://gratora.net/pricing/?utm_source=plugin'],
            $this->screen()['offer']
        );
    }

    public function test_an_offer_with_no_end_is_shown_until_it_is_withdrawn(): void
    {
        $this->gratoraNetSends(self::feed(['offer' => ['text' => 'Open ended.', 'url' => 'https://gratora.net/pricing/']]));

        $this->assertSame('Open ended.', $this->screen(null, '2031-01-01 00:00:00')['offer']['text']);
    }

    public function test_an_offer_stops_at_its_end_on_a_site_that_fetched_it_earlier(): void
    {
        $this->gratoraNetSends(self::feed(['offer' => [
            'text' => 'Until one o\'clock.',
            'url'  => 'https://gratora.net/pricing/',
            'ends' => '2026-10-06T13:00:00Z',
        ]]));

        $this->assertNotNull($this->screen(null, '2026-10-06 12:59:59')['offer']);
        $this->assertNotNull($this->screen(null, '2026-10-06 13:00:00')['offer']);
        $this->assertNull($this->screen(null, '2026-10-06 13:00:01')['offer']);
        $this->assertCount(1, $this->requests);
    }

    /** @return array<string, array{mixed}> */
    public function offersThatBreakARule(): array
    {
        $offer = ['text' => 'On offer.', 'url' => 'https://gratora.net/pricing/'];

        return [
            'an end that cannot be read' => [array_merge($offer, ['ends' => 'next Friday, probably'])],
            'an end that is no text'     => [array_merge($offer, ['ends' => 1790000000])],
            'an empty end'               => [array_merge($offer, ['ends' => ''])],
            'a link to another site'     => [array_merge($offer, ['url' => 'https://example.com/'])],
            'no link'                    => [['text' => 'On offer.']],
            'no sentence'                => [array_merge($offer, ['text' => ''])],
            'a sentence too long'        => [array_merge($offer, ['text' => str_repeat('o', 141)])],
            'a sentence that is no text' => [array_merge($offer, ['text' => ['On offer.']])],
            'an offer that is no offer'  => ['On offer.'],
        ];
    }

    /** @dataProvider offersThatBreakARule */
    public function test_an_offer_that_breaks_a_rule_is_not_shown(mixed $offer): void
    {
        $this->gratoraNetSends(self::feed(['offer' => $offer]));

        $screen = $this->screen();

        $this->assertNull($screen['offer']);
        $this->assertSame('remote', $screen['source']);
    }

    public function test_a_site_that_runs_a_paid_add_on_gets_no_offer_and_keeps_the_plans(): void
    {
        $this->gratoraNetSends(self::feed([
            'plans' => [self::plan('standard', ['tributes'])],
            'offer' => ['text' => 'For new plans.', 'url' => 'https://gratora.net/pricing/'],
        ]));

        $this->assertSame('For new plans.', $this->screen()['offer']['text']);

        $screen = $this->screen($this->aSiteWithAPaidAddOn());

        $this->assertNull($screen['offer']);
        $this->assertSame(['standard'], array_column($screen['plans'], 'slug'));
        $this->assertSame('Standard', array_column($screen['addons'], 'plan', 'slug')['tributes']);
    }

    public function test_the_list_it_ships_with_links_to_the_site_with_the_same_mark(): void
    {
        add_filter('gratora.addons.remote', '__return_false');

        foreach ($this->screen()['addons'] as $card) {
            $this->assertSame('gratora.net', wp_parse_url($card['url'], PHP_URL_HOST));
            $this->assertStringContainsString('utm_source=plugin&utm_medium=add-ons', $card['url']);
        }
    }
}
