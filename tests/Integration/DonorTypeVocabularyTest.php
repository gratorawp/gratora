<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;

/**
 * Every door onto donor_type has to offer the same words.
 *
 * They did not. The donor list and the export both accepted a `company`
 * type, and the writer would only ever store individual, organization or
 * household, so filtering the list by company matched nothing and could
 * never match anything. The CLI meanwhile refused household, which is a type
 * the product does have. Four literals, drifted three ways, and no reader
 * could tell which was authoritative.
 *
 * They now share Donor::TYPES. This walks the registered routes rather than
 * the source, so a hand-written list added later still fails here.
 */
final class DonorTypeVocabularyTest extends IntegrationTestCase
{
    public function test_no_route_offers_a_donor_type_the_writer_would_refuse(): void
    {
        do_action('rest_api_init');

        $offered = [];
        foreach (rest_get_server()->get_routes() as $route => $handlers) {
            foreach ($handlers as $handler) {
                $enum = $handler['args']['donor_type']['enum'] ?? null;
                if (! is_array($enum)) {
                    continue;
                }
                foreach ($enum as $value) {
                    // The empty string is "no filter", not a type.
                    if ($value !== '' && ! in_array($value, Donor::TYPES, true)) {
                        $offered[] = $route . ' offers ' . $value;
                    }
                }
            }
        }

        $this->assertSame([], $offered, 'a route accepts a donor type nothing can be saved as');
    }

    public function test_every_route_that_filters_by_type_offers_all_of_them(): void
    {
        do_action('rest_api_init');

        $missing = [];
        foreach (rest_get_server()->get_routes() as $route => $handlers) {
            foreach ($handlers as $handler) {
                $enum = $handler['args']['donor_type']['enum'] ?? null;
                if (! is_array($enum)) {
                    continue;
                }
                foreach (Donor::TYPES as $type) {
                    if (! in_array($type, $enum, true)) {
                        $missing[] = $route . ' will not accept ' . $type;
                    }
                }
            }
        }

        $this->assertSame([], $missing, 'a route refuses a donor type the product supports');
    }

    /**
     * Every donor_type enum anywhere in a structure, however deeply nested.
     *
     * @param mixed $node
     * @return list<list<string>>
     */
    private function enumsIn(mixed $node, string $key = ''): array
    {
        if (! is_array($node)) {
            return [];
        }

        $found = [];
        if ($key === 'donor_type' && isset($node['enum']) && is_array($node['enum'])) {
            $found[] = array_values($node['enum']);
        }
        foreach ($node as $k => $child) {
            $found = array_merge($found, $this->enumsIn($child, (string) $k));
        }

        return $found;
    }

    /**
     * The command manifest is a third door, and the one an agent reads to decide
     * what it may send. It is a nested schema rather than route args, so it needs
     * walking separately.
     *
     * @return list<list<string>>
     */
    private function commandManifestEnums(): array
    {
        do_action('rest_api_init');
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new \WP_REST_Request('GET', '/fundkit/v1/admin/commands'));
        $this->assertSame(200, $res->get_status(), 'the command manifest has to be readable');

        return $this->enumsIn($res->get_data());
    }

    public function test_the_command_manifest_offers_the_same_types(): void
    {
        $enums = $this->commandManifestEnums();

        $this->assertNotSame([], $enums, 'the manifest no longer declares donor_type, so this proves nothing');

        foreach ($enums as $enum) {
            $this->assertSame(
                Donor::TYPES,
                array_values(array_filter($enum, static fn (string $v): bool => $v !== '')),
                'the command manifest and the writer disagree about donor types'
            );
        }
    }

    public function test_the_writer_stores_every_type_it_offers(): void
    {
        $service = Plugin::instance()->container->get(DonorService::class);

        foreach (Donor::TYPES as $i => $type) {
            $email = "type-{$i}@example.test";
            $service->findOrCreate($email, ['donor_type' => $type]);

            $this->assertSame(
                $type,
                (string) $service->findByEmail($email)?->donor_type,
                "a donor could not be stored as {$type}"
            );
        }
    }
}
