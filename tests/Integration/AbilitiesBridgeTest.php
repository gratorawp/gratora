<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Foundation\Commands\AbilitiesBridge;
use GiveFlow\Foundation\Commands\CommandRegistry;
use GiveFlow\Foundation\Plugin;

/**
 * GiveFlow over MCP is the Abilities API plus a mapping: whatever adapter the
 * site runs reads core's registry, so every command has to arrive there with
 * its capability and its confirmation gate intact.
 */
final class AbilitiesBridgeTest extends IntegrationTestCase
{
    private function abilities(): array
    {
        // Asking for abilities is what builds the registry and fires both
        // hooks, so this goes through the same path an MCP adapter does rather
        // than calling the bridge by hand.
        return wp_get_abilities(['namespace' => AbilitiesBridge::CATEGORY]);
    }

    public function test_every_command_is_published_as_an_ability(): void
    {
        $registry  = Plugin::instance()->container->get(CommandRegistry::class);
        $commands  = $registry->manifest();
        $abilities = $this->abilities();

        $this->assertNotSame([], $commands, 'nothing registered any commands');

        foreach ($commands as $command) {
            $this->assertArrayHasKey(
                AbilitiesBridge::abilityName((string) $command['id']),
                $abilities,
                (string) $command['id'] . ' never reached the abilities registry'
            );
        }
    }

    /**
     * Dots are legal in a command id and illegal in an ability name, so the
     * mapping is not optional.
     */
    public function test_names_are_legal_ability_names(): void
    {
        foreach (array_keys($this->abilities()) as $name) {
            $this->assertMatchesRegularExpression('#^[a-z0-9-]+/[a-z0-9-]+$#', (string) $name);
        }
    }

    /**
     * The gate is the whole safety story for an external client, so this runs
     * the ability itself rather than the registry behind it. Dispatching as a
     * trusted source would run the command outright, and driving the registry
     * by hand would not notice: it was green while the bridge said 'rest'.
     */
    public function test_a_mutating_ability_refuses_to_run_unconfirmed(): void
    {
        $registry  = Plugin::instance()->container->get(CommandRegistry::class);
        $abilities = $this->abilities();

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $gated = 0;
        $ran   = [];
        foreach ($registry->manifest() as $command) {
            if (! (bool) $command['mutating']) {
                continue;
            }
            $ability = $abilities[AbilitiesBridge::abilityName((string) $command['id'])] ?? null;
            if ($ability === null) {
                continue;
            }

            $result = $ability->execute($this->minimalInputFor($ability));

            // Schema validation also returns WP_Error, so the code matters:
            // asserting merely "an error" stayed green while the bridge
            // dispatched as a trusted source and really did run these.
            if ($result instanceof \WP_Error) {
                if ($result->get_error_code() === 'command.confirmation_required') {
                    $gated++;
                }
                continue;
            }

            $ran[] = (string) $command['id'];
        }

        $this->assertSame([], $ran, 'these mutating commands ran with no confirmation: ' . implode(', ', $ran));
        $this->assertGreaterThan(0, $gated, 'no mutating ability reached the confirmation gate at all');
    }

    /**
     * Enough input to clear schema validation and reach the gate. Required
     * string/number/boolean properties only; anything richer is not a command
     * this check needs.
     *
     * @return array<string,mixed>
     */
    private function minimalInputFor(\WP_Ability $ability): array
    {
        $schema   = $ability->get_input_schema();
        $required = (array) ($schema['required'] ?? []);
        $props    = (array) ($schema['properties'] ?? []);

        $input = [];
        foreach ($required as $name) {
            // JSON Schema allows a list of types; the first is enough here.
            $declared = $props[$name]['type'] ?? 'string';
            $type     = (string) (is_array($declared) ? ($declared[0] ?? 'string') : $declared);
            $input[(string) $name] = match ($type) {
                'integer', 'number' => 1,
                'boolean'           => false,
                'array'             => [],
                'object'            => (object) [],
                default             => 'x',
            };
        }

        return $input;
    }

    /** Reads carry the hint a client uses to tell them apart before calling. */
    public function test_a_read_is_annotated_read_only(): void
    {
        $abilities = $this->abilities();
        $registry  = Plugin::instance()->container->get(CommandRegistry::class);

        foreach ($registry->manifest() as $command) {
            if ((bool) $command['mutating']) {
                continue;
            }
            $ability = $abilities[AbilitiesBridge::abilityName((string) $command['id'])] ?? null;
            $this->assertNotNull($ability);
            $this->assertTrue(
                (bool) ($ability->get_meta()['annotations']['readOnlyHint'] ?? false),
                (string) $command['id'] . ' is a read but is not annotated as one'
            );
            return;
        }
    }
}
