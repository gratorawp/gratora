<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

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

        $bundle = json_decode($body, true);
        $this->assertIsArray($bundle, 'the export did not stream a JSON file: ' . $body);

        return $bundle;
    }

    /** @return array<string,mixed> */
    private function adminExport(int $donorId): array
    {
        return $this->served(new WP_REST_Request('GET', "/gratora/v1/admin/donors/{$donorId}/export"));
    }

    /** @return array<string,mixed> */
    private function donorDownload(int $donorId): array
    {
        $_COOKIE['gratora_donor_session'] = $this->portalSession($donorId, 'tok');

        $request = new WP_REST_Request('POST', '/gratora/v1/portal/data-export');
        $request->set_header('X-Gratora-Csrf', 'tok');

        return $this->served($request);
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

    public function test_wordpress_exporter_answers_the_same_with_and_without_a_listener(): void
    {
        $email = 'sections-wp-' . uniqid() . '@example.test';
        $donor = $this->donor($email);
        $c     = Plugin::instance()->container;

        $privacy = new WordPressPrivacy(
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(IdentityHasher::class),
            $c->get(DonorMetricsService::class),
            $c->get(ConsentService::class),
        );

        $without = $privacy->export($email);
        $this->assertNotSame([], $without['data']);

        $this->listen(['volunteering' => ['label' => 'Volunteering', 'data' => ['shirt_size' => 'M']]]);

        $this->assertArrayHasKey(
            'volunteering',
            $c->get(DonorMetricsService::class)->exportData((int) $donor->id)['sections'] ?? []
        );
        $this->assertSame($without, $privacy->export($email));
    }
}
