<?php

declare(strict_types=1);

namespace FundKit\Foundation\Commands;

/**
 * Every FundKit command, published as a WordPress ability.
 *
 * The Abilities API (core, since 6.9) is the registry an MCP server reads, so
 * bridging to it is the whole of "expose FundKit over MCP": no transport, no
 * token table, no scope machinery of our own. Whatever adapter the site runs
 * gets the same commands the assistant and the REST endpoint already use, with
 * the same capability checks and the same confirmation gate behind them.
 *
 * Add-ons need do nothing: a command registered by any module is bridged here.
 *
 * @since 1.0.0
 */
final class AbilitiesBridge
{
    public const CATEGORY = 'fundkit';

    /** @since 1.0.0 */
    public function __construct(private CommandRegistry $registry)
    {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        // Guarded rather than assumed: the API is core, but a hardened install
        // can strip it, and a missing ability registry must not be fatal.
        if (! function_exists('wp_register_ability')) {
            return;
        }

        // Two hooks, not one: core refuses a category registered on the
        // abilities hook and an ability registered anywhere else.
        add_action('wp_abilities_api_categories_init', [$this, 'registerCategory']);
        add_action('wp_abilities_api_init', [$this, 'registerAbilities']);
    }

    /** @since 1.0.0 */
    public function registerCategory(): void
    {
        wp_register_ability_category(self::CATEGORY, [
            'label'       => __('Fundraising Toolkit', 'fundraising-toolkit'),
            'description' => __('Campaigns, donations, donors and everything the installed add-ons add.', 'fundraising-toolkit'),
        ]);
    }

    /** @since 1.0.0 */
    public function registerAbilities(): void
    {
        foreach ($this->registry->manifest() as $command) {
            $id = (string) $command['id'];

            wp_register_ability(self::abilityName($id), [
                'label'               => self::label($id),
                'description'         => (string) $command['summary'],
                'category'            => self::CATEGORY,
                'input_schema'        => $command['inputSchema'] !== [] ? $command['inputSchema'] : ['type' => 'object'],
                'output_schema'       => $command['outputSchema'] !== [] ? $command['outputSchema'] : ['type' => 'object'],
                'permission_callback' => static fn (): bool => current_user_can((string) $command['capability']),
                'execute_callback'    => fn (array $input = []) => $this->execute($id, $input),
                'meta'                => [
                    // MCP's own hints, so a client can tell a read from a write
                    // before it calls one.
                    'annotations' => [
                        'readOnlyHint'    => ! (bool) $command['mutating'],
                        'idempotentHint'  => (bool) $command['idempotent'],
                        'destructiveHint' => (bool) $command['mutating'] && ! (bool) $command['idempotent'],
                    ],
                ],
            ]);
        }
    }

    /**
     * Dispatched as 'mcp', which is deliberate: that source is inside the
     * registry's CONFIRMATION_SOURCES, so a mutating command called through an
     * ability stops and asks for confirmation instead of running. Passing
     * 'rest' here would have been the natural-looking choice and would have
     * handed an external client unconfirmed writes.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|\WP_Error
     */
    private function execute(string $id, array $input)
    {
        $result = $this->registry->dispatch($id, $input, new CommandContext(
            user_id: get_current_user_id() ?: null,
            source: 'mcp',
            request_id: 'ability-' . wp_generate_uuid4(),
            confirmation: isset($input['confirmation']) ? (string) $input['confirmation'] : null,
        ));

        if (! $result->ok) {
            return new \WP_Error(
                (string) ($result->error_code ?? 'fundkit_command_failed'),
                (string) ($result->error ?? __('The command did not run.', 'fundraising-toolkit')),
                $result->data
            );
        }

        return $result->data;
    }

    /** `donation.list` is not a legal ability name; `fundkit/donation-list` is. */
    public static function abilityName(string $commandId): string
    {
        return self::CATEGORY . '/' . str_replace(['.', '_'], '-', $commandId);
    }

    private static function label(string $commandId): string
    {
        return ucfirst(str_replace(['.', '_', '-'], ' ', $commandId));
    }
}
