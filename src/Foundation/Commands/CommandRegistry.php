<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

use Dono\Analytics\EventRecorder;
use RuntimeException;

/**
 * Registers, introspects, and dispatches commands.
 *
 * @version 1.0.0
 */
final class CommandRegistry
{
    /** @var array<string,Command> */
    private array $commands = [];

    public function __construct(private ?EventRecorder $events = null)
    {
    }

    /** Register a command; throws if the id is already taken. */
    public function register(Command $command): void
    {
        if (isset($this->commands[$command->id])) {
            throw new RuntimeException("Command '{$command->id}' is already registered.");
        }
        $this->commands[$command->id] = $command;
    }

    /** Return whether a command with $id is registered. */
    public function has(string $id): bool
    {
        return isset($this->commands[$id]);
    }

    /** Return the command for $id, or null if not registered. */
    public function get(string $id): ?Command
    {
        return $this->commands[$id] ?? null;
    }

    /** @return array<string,Command> */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Serialisable command manifest; handler closure is omitted intentionally.
     *
     * @return list<array<string,mixed>>
     */
    public function manifest(): array
    {
        $out = [];
        foreach ($this->commands as $c) {
            $out[] = [
                'id'           => $c->id,
                'summary'      => $c->summary,
                'inputSchema'  => $c->inputSchema,
                'outputSchema' => $c->outputSchema,
                'capability'   => $c->capability,
                'idempotent'   => $c->idempotent,
                'mutating'     => $c->mutating,
                'meta'         => $c->meta,
            ];
        }
        return $out;
    }

    /**
     * Dispatch a command. Enforces: existence, permission, input validation,
     * confirmation gate (mcp + mutating), dry-run, audit, handler, output check.
     *
     * @param array<string,mixed> $input
     */
    public function dispatch(string $id, array $input, CommandContext $ctx): CommandResult
    {
        $command = $this->commands[$id] ?? null;
        if (! $command) {
            return CommandResult::error('command.not_found', "Unknown command '{$id}'.");
        }

        $allowed = in_array($ctx->source, ['rest', 'cli'], true)
            ? current_user_can($command->capability)
            : ($ctx->user_id !== null && user_can($ctx->user_id, $command->capability));
        if (! $allowed) {
            $this->audit('command.denied', $command, $ctx, $input);
            return CommandResult::error('command.denied', "Not permitted: {$command->capability}.");
        }

        // Unattended sources are rate-limited; rest/cli are not.
        if (! in_array($ctx->source, ['rest', 'cli'], true) && $this->rateLimited($command, $ctx)) {
            $this->audit('command.rate_limited', $command, $ctx, $input);
            return CommandResult::error('command.rate_limited', "Rate limit exceeded for '{$id}'.");
        }

        $canonical = $input;
        if ($command->inputSchema !== []) {
            $valid = rest_validate_value_from_schema($input, $command->inputSchema, $id);
            if (is_wp_error($valid)) {
                return CommandResult::error('command.invalid_input', $valid->get_error_message());
            }
            $sanitized = rest_sanitize_value_from_schema($input, $command->inputSchema, $id);
            if (is_wp_error($sanitized)) {
                return CommandResult::error('command.invalid_input', $sanitized->get_error_message());
            }
            $canonical = is_array($sanitized) ? $sanitized : (array) $sanitized;
        }

        if ($command->mutating) {
            $confirmDigest = ConfirmationGate::digest($id, $canonical);
            if ($ctx->dry_run) {
                return CommandResult::dryRun($canonical, $confirmDigest);
            }
            if ($ctx->source === 'mcp') {
                $confirmed = ConfirmationGate::verify(
                    $ctx->confirmation,
                    (string) ($ctx->user_id ?? '0'),
                    $id,
                    $confirmDigest,
                );
                if (! $confirmed) {
                    return CommandResult::confirmationRequired(
                        ['command' => $id, 'input' => $canonical],
                        $confirmDigest,
                    );
                }
            }
        }

        $this->audit('command.invoked', $command, $ctx, $canonical);

        try {
            $data = ($command->handler)($canonical, $ctx);
        } catch (CommandError $e) {
            $this->audit('command.failed', $command, $ctx, $canonical, $e->getMessage());
            return CommandResult::error('command.failed', $e->getMessage());
        }

        if (defined('WP_DEBUG') && WP_DEBUG && $command->outputSchema !== []) {
            $checked = rest_validate_value_from_schema($data, $command->outputSchema, $id . '.output');
            if (is_wp_error($checked)) {
                error_log("[dono] command '{$id}' output schema mismatch: " . $checked->get_error_message());
            }
        }

        return CommandResult::ok(is_array($data) ? $data : ['result' => $data]);
    }

    /**
     * Fixed-window token bucket per (rate-limit key, source), backed by WP transients.
     * meta.rate_limit = cap per 60 s window; meta.rate_limit_key groups commands; <= 0 disables.
     */
    private function rateLimited(Command $command, CommandContext $ctx): bool
    {
        $limit = (int) ($command->meta['rate_limit'] ?? 120);
        if ($limit <= 0) {
            return false;
        }
        $bucket = (string) ($command->meta['rate_limit_key'] ?? $command->id);
        $window = (int) floor(time() / 60);
        $key    = 'dono_cmd_rl_' . md5($bucket . '|' . $ctx->source . '|' . $window);

        $count = (int) get_transient($key);
        if ($count >= $limit) {
            return true;
        }
        set_transient($key, $count + 1, 60);
        return false;
    }

    /**
     * Record to the dono_events firehose. Input is hashed - PII never stored raw.
     *
     * @param array<string,mixed> $input
     */
    private function audit(string $type, Command $command, CommandContext $ctx, array $input, ?string $error = null): void
    {
        if (! $this->events) {
            return;
        }
        $payload = [
            'command_id'   => $command->id,
            'source'       => $ctx->source,
            'request_id'   => $ctx->request_id,
            'user_id'      => $ctx->user_id,
            'dry_run'      => $ctx->dry_run,
            'input_digest' => ConfirmationGate::inputDigest($input),
        ];
        if ($error !== null) {
            $payload['error'] = $error;
        }
        $this->events->record($type, [
            'user_id' => $ctx->user_id,
            'payload' => $payload,
        ]);
    }
}
