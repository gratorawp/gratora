<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\ErrorLog;
use Gratora\Analytics\Event;
use Gratora\Analytics\EventRecorder;
use Gratora\Donors\ConsentService;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Donors\Privacy\WordPressPrivacy;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * An add-on holds data that is the donor's own, and both JSON exports promise
 * everything held. `gratora.donor.export_sections` is the add-on's way in.
 */
final class DonorExportSectionsTest extends IntegrationTestCase
{
    private const ADMIN_KEYS  = ['exported_at', 'donor', 'donations', 'recurring', 'receipts', 'consents', 'notes', 'events'];
    private const PORTAL_KEYS = ['donor', 'donations', 'recurring', 'receipts', 'events', 'consents', 'exported_at'];

    protected function tearDown(): void
    {
        unset($_COOKIE['gratora_donor_session']);
        parent::tearDown();
    }

    private function donor(string $email = ''): Donor
    {
        return Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email ?: 'sections-' . uniqid() . '@example.test', ['first_name' => 'Nadia']);
    }

    /** @param array<string,mixed> $sections */
    private function listen(array $sections): void
    {
        add_filter(
            'gratora.donor.export_sections',
            static fn (array $soFar): array => $soFar + $sections
        );
    }

    /**
     * Both routes write the file from rest_pre_serve_request, which fires only
     * when the server serves, so it is fired here as the server would.
     *
     * @return array<string,mixed>
     */
    private function served(WP_REST_Request $request): array
    {
        remove_all_filters('rest_pre_serve_request');

        $response = rest_do_request($request);
        $this->assertSame(200, $response->get_status(), (string) wp_json_encode($response->get_data()));

        ob_start();
        apply_filters('rest_pre_serve_request', false, $response, $request, rest_get_server());
        $body = (string) ob_get_clean();

        $bundle = json_decode($body, true, 1024);
        $this->assertIsArray($bundle, 'the export did not stream a JSON file: ' . substr($body, 0, 200));

        return $bundle;
    }

    /**
     * What a route answers when it sends no file: the status, the error code,
     * and whatever the server would have written to the browser.
     *
     * @return array{status:int, code:string, body:string, disposition:?string}
     */
    private function refused(WP_REST_Request $request): array
    {
        remove_all_filters('rest_pre_serve_request');

        $response = rest_do_request($request);

        ob_start();
        apply_filters('rest_pre_serve_request', false, $response, $request, rest_get_server());
        $body = (string) ob_get_clean();

        return [
            'status'      => $response->get_status(),
            'code'        => (string) (((array) $response->get_data())['code'] ?? ''),
            'body'        => $body,
            'disposition' => $response->get_headers()['Content-Disposition'] ?? null,
        ];
    }

    private function adminExportRequest(int $donorId): WP_REST_Request
    {
        return new WP_REST_Request('GET', "/gratora/v1/admin/donors/{$donorId}/export");
    }

    private function donorDownloadRequest(int $donorId): WP_REST_Request
    {
        $_COOKIE['gratora_donor_session'] = $this->portalSession($donorId, 'tok');

        $request = new WP_REST_Request('POST', '/gratora/v1/portal/data-export');
        $request->set_header('X-Gratora-Csrf', 'tok');

        return $request;
    }

    /** @return array<string,mixed> an array nested this many levels */
    private static function nested(int $levels): array
    {
        $data = ['leaf' => 1];
        for ($i = 1; $i < $levels; $i++) {
            $data = ['n' => $data];
        }

        return $data;
    }

    /**
     * Something core itself holds that the file cannot carry, with no listener
     * involved: a payload that is stored and read back, and is nested too deep
     * once the file wraps it.
     */
    private function holdSomethingTheFileCannotCarry(int $donorId): void
    {
        Plugin::instance()->container->get(EventRecorder::class)
            ->record('custom.recorded', ['donor_id' => $donorId, 'payload' => self::nested(510)]);

        $stored = Event::query()->where('donor_id', $donorId)->where('type', 'custom.recorded')->get();
        $this->assertSame(self::nested(510), $stored?->payload, 'the payload did not come back from storage');
    }

    /** @return array<string,mixed> */
    private function adminExport(int $donorId): array
    {
        return $this->served($this->adminExportRequest($donorId));
    }

    /** @return array<string,mixed> */
    private function donorDownload(int $donorId): array
    {
        return $this->served($this->donorDownloadRequest($donorId));
    }

    public function test_the_admin_export_carries_a_section_a_listener_adds(): void
    {
        $this->listen(['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]]);

        $bundle = $this->adminExport((int) $this->donor()->id);

        $this->assertSame(
            ['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]],
            $bundle['sections'] ?? null
        );
        $this->assertSame([...self::ADMIN_KEYS, 'sections'], array_keys($bundle));
    }

    public function test_the_donors_own_download_carries_a_section_a_listener_adds(): void
    {
        $this->listen(['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]]);

        $bundle = $this->donorDownload((int) $this->donor()->id);

        $this->assertSame(
            ['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]],
            $bundle['sections'] ?? null
        );
    }

    public function test_junk_a_listener_returns_is_dropped_and_the_rest_kept(): void
    {
        $kept = ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']];

        $this->listen([
            'volunteering'  => $kept + ['staff_only' => 'not part of a section'],
            0               => ['label' => 'No key of its own', 'data' => []],
            ''              => ['label' => 'Empty key', 'data' => []],
            'not-an-array'  => 'Volunteering',
            'no-label'      => ['data' => ['a' => 1]],
            'label-not-str' => ['label' => 12, 'data' => ['a' => 1]],
            'no-data'       => ['label' => 'No data'],
            'data-not-arr'  => ['label' => 'Data is text', 'data' => 'M'],
            'held-nothing'  => ['label' => 'Nothing held', 'data' => []],
        ]);

        $donorId  = (int) $this->donor()->id;
        $expected = ['volunteering' => $kept, 'held-nothing' => ['label' => 'Nothing held', 'data' => []]];

        $this->assertSame($expected, $this->adminExport($donorId)['sections'] ?? null);
        $this->assertSame($expected, $this->donorDownload($donorId)['sections'] ?? null);
    }

    public function test_data_the_file_cannot_carry_drops_its_section_and_the_rest_is_kept(): void
    {
        $kept     = ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M', 'hours' => 12.5, 'active' => true, 'left' => null, 'shifts' => [['day' => 'Mon']]]];
        $resource = fopen('php://memory', 'r');
        $donor    = $this->donor();
        $loop     = ['v' => 1];
        $loop['itself'] = &$loop;

        try {
            $this->listen([
                'volunteering'   => $kept,
                'not-a-number'   => ['label' => 'NAN', 'data' => ['v' => NAN]],
                'infinite'       => ['label' => 'INF', 'data' => ['v' => INF]],
                'nested-nan'     => ['label' => 'NAN further down', 'data' => ['rows' => [['v' => 1], ['v' => NAN]]]],
                'an-object'      => ['label' => 'Object', 'data' => ['v' => (object) ['a' => 1]]],
                'a-model'        => ['label' => 'Model', 'data' => ['donor' => $donor]],
                'nested-object'  => ['label' => 'Object further down', 'data' => ['rows' => [['v' => new \ArrayObject(['a' => 1])]]]],
                'a-resource'     => ['label' => 'Resource', 'data' => ['v' => $resource]],
                'holds-itself'   => ['label' => 'Array that contains itself', 'data' => $loop],
            ]);

            $donorId = (int) $donor->id;

            $this->assertSame(['volunteering' => $kept], $this->adminExport($donorId)['sections'] ?? null);
            $this->assertSame(['volunteering' => $kept], $this->donorDownload($donorId)['sections'] ?? null);
        } finally {
            fclose($resource);
        }
    }

    public function test_data_is_kept_as_deep_as_the_file_can_carry_it_and_no_deeper(): void
    {
        $this->listen([
            'deepest'  => ['label' => 'As deep as fits', 'data' => self::nested(509)],
            'too-deep' => ['label' => 'One level more', 'data' => self::nested(510)],
        ]);

        $donorId  = (int) $this->donor()->id;
        $expected = ['deepest' => ['label' => 'As deep as fits', 'data' => self::nested(509)]];

        $this->assertSame($expected, $this->adminExport($donorId)['sections'] ?? null);
        $this->assertSame($expected, $this->donorDownload($donorId)['sections'] ?? null);
    }

    public function test_the_admin_export_answers_an_error_and_sends_no_file_when_the_file_cannot_be_written(): void
    {
        $donorId = (int) $this->donor()->id;
        $this->holdSomethingTheFileCannotCarry($donorId);

        $answer = $this->refused($this->adminExportRequest($donorId));

        $this->assertSame(
            ['status' => 500, 'code' => 'gratora_export_failed', 'body' => '', 'disposition' => null],
            $answer
        );
        $this->assertTrue(
            Event::query()->where('donor_id', $donorId)->where('type', ErrorLog::PREFIX . 'admin.donor.export')->exists(),
            'the log under Tools has no line for the failure the message points to'
        );
    }

    public function test_the_donors_own_download_answers_an_error_and_sends_no_file_when_the_file_cannot_be_written(): void
    {
        $donorId = (int) $this->donor()->id;
        $this->holdSomethingTheFileCannotCarry($donorId);

        $answer = $this->refused($this->donorDownloadRequest($donorId));

        $this->assertSame(
            ['status' => 500, 'code' => 'gratora_export_failed', 'body' => '', 'disposition' => null],
            $answer
        );
        $this->assertTrue(
            Event::query()->where('donor_id', $donorId)->where('type', ErrorLog::PREFIX . 'portal.export')->exists(),
            'the organization has no line in its log for the failure the donor is sent to it about'
        );
    }

    public function test_with_no_listener_neither_export_has_the_key(): void
    {
        $donorId = (int) $this->donor()->id;

        $this->assertSame(self::ADMIN_KEYS, array_keys($this->adminExport($donorId)));
        $this->assertSame(self::PORTAL_KEYS, array_keys($this->donorDownload($donorId)));
    }

    public function test_listeners_that_leave_nothing_to_keep_leave_no_key(): void
    {
        $donorId = (int) $this->donor()->id;

        $this->listen(['no-data' => ['label' => 'No data']]);
        $this->assertSame(self::ADMIN_KEYS, array_keys($this->adminExport($donorId)));

        add_filter('gratora.donor.export_sections', '__return_null', 99);
        $this->assertSame(self::ADMIN_KEYS, array_keys($this->adminExport($donorId)));
        $this->assertSame(self::PORTAL_KEYS, array_keys($this->donorDownload($donorId)));
    }

    public function test_the_listener_is_handed_the_donor(): void
    {
        $donor  = $this->donor();
        $handed = [];

        add_filter('gratora.donor.export_sections', static function (array $sections, $subject) use (&$handed): array {
            $handed[] = $subject;

            return $sections;
        }, 10, 2);

        $this->adminExport((int) $donor->id);

        $this->assertCount(1, $handed);
        $this->assertInstanceOf(Donor::class, $handed[0]);
        $this->assertSame((int) $donor->id, (int) $handed[0]->id);
    }

    public function test_an_erased_donor_is_asked_about_like_any_other(): void
    {
        $donor = $this->donor();
        Plugin::instance()->container->get(DonorService::class)->redact($donor);

        add_filter('gratora.donor.export_sections', static function (array $sections, Donor $subject): array {
            $sections['volunteering'] = [
                'label' => 'Volunteering',
                'data'  => ['still_held' => $subject->redacted_at !== null ? 'after erasure' : 'before erasure'],
            ];

            return $sections;
        }, 10, 2);

        $this->assertSame(
            ['still_held' => 'after erasure'],
            $this->adminExport((int) $donor->id)['sections']['volunteering']['data'] ?? null
        );
    }

    public function test_a_listener_that_throws_fails_the_export(): void
    {
        $donorId = (int) $this->donor()->id;

        $this->listen(['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]]);
        add_filter('gratora.donor.export_sections', static function (): array {
            throw new \DomainException('the add-on could not read what it holds');
        }, 20);

        $exports = [
            'admin'  => fn () => $this->adminExport($donorId),
            'portal' => fn () => $this->donorDownload($donorId),
        ];

        foreach ($exports as $name => $export) {
            try {
                $export();
                $this->fail("the {$name} export was made without a section its add-on could not give");
            } catch (\DomainException $e) {
                $this->assertSame('the add-on could not read what it holds', $e->getMessage());
            }
        }
    }

    private function wordPressExporter(): WordPressPrivacy
    {
        $c = Plugin::instance()->container;

        return new WordPressPrivacy(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(IdentityHasher::class),
            $c->get(DonorMetricsService::class),
            $c->get(ConsentService::class),
        );
    }

    public function test_wordpress_exporter_answers_the_same_with_and_without_a_listener(): void
    {
        $email = 'sections-wp-' . uniqid() . '@example.test';
        $donor = $this->donor($email);

        $without = $this->wordPressExporter()->export($email);
        $this->assertNotSame([], $without['data']);

        $this->listen(['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]]);

        $this->assertArrayHasKey('volunteering', $this->adminExport((int) $donor->id)['sections'] ?? []);
        $this->assertSame($without, $this->wordPressExporter()->export($email));
    }

    public function test_wordpress_exporter_asks_no_listener(): void
    {
        $email = 'sections-wp-' . uniqid() . '@example.test';
        $this->donor($email);

        $without = $this->wordPressExporter()->export($email);

        add_filter('gratora.donor.export_sections', static function (): array {
            throw new \DomainException('the add-on could not read what it holds');
        });

        $this->assertSame($without, $this->wordPressExporter()->export($email));
    }
}
