<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

use Dono\Analytics\ErrorLog;
use Dono\Analytics\EventRecorder;
use RuntimeException;

/**
 * Registers, introspects, and dispatches commands.
 *
 * @since 1.0.0
 */
final class CommandRegistry
{
    /** Sources whose mutating dispatches must clear the confirmation gate. */
    private const CONFIRMATION_SOURCES = ['mcp', 'chat'];

    /** @var array<string,Command> */
    private array $commands = [];

    /** @since 1.0.0 */
    public function __construct(private ?EventRecorder $events = null)
    {
    }

    /**
     * Register a command; throws if the id is already taken.
     *
     * @since 1.0.0
     */
    public function register(Command $command): void
    {
        if (isset($this->commands[$command->id])) {
            throw new RuntimeException(esc_html("Command '{$command->id}' is already registered."));
        }
        $this->commands[$command->id] = $command;
    }

    /** @since 1.0.0 */
    public function has(string $id): bool
    {
        return isset($this->commands[$id]);
    }

    /** @since 1.0.0 */
    public function get(string $id): ?Command
    {
        return $this->commands[$id] ?? null;
    }

    /**
     * @return array<string,Command>
     * @since 1.0.0
     */
    public function all(): array
    {
        return $this->commands;
    }

    /**
     * Serializable command manifest; handler closure is omitted intentionally.
     *
     * @return list<array<string,mixed>>
     * @since 1.0.0
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
                'has_preview'  => $c->preview !== null,
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
     * @since 1.0.0
     */
    public function dispatch(string $id, array $input, CommandContext $ctx): CommandResult
    {
        $command = $this->authorize($id, $ctx);
        if ($command instanceof CommandResult) {
            // Only a permission denial is audited; not-found and invalid-input are not.
            if ($command->error_code === 'command.denied') {
                $this->audit('command.denied', $this->commands[$id], $ctx, $input);
            }
            return $command;
        }

        // Unattended sources are rate-limited; rest/cli are not.
        if (! in_array($ctx->source, ['rest', 'cli'], true) && $this->rateLimited($command, $ctx)) {
            $this->audit('command.rate_limited', $command, $ctx, $input);
            return CommandResult::error('command.rate_limited', "Rate limit exceeded for '{$id}'.");
        }

        $canonical = $this->canonicalize($command, $input);
        if ($canonical instanceof CommandResult) {
            return $canonical;
        }

        if ($command->mutating) {
            $confirmDigest = ConfirmationGate::digest($id, $canonical);
            if ($ctx->dry_run) {
                return CommandResult::dryRun($canonical, $confirmDigest);
            }
            // Agent-initiated mutations pause for a human. 'mcp' is an external
            // client; 'chat' is the in-admin assistant. Both must clear the
            // confirmation verifier; 'automation'/'rest'/'cli' are trusted
            // callers and run straight through.
            if (in_array($ctx->source, self::CONFIRMATION_SOURCES, true)) {
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
        } catch (\Throwable $e) {
            // An agent-initiated command must never fatal the request: a bad
            // input that trips a service exception (invalid slug, unpublishable
            // form, ...) comes back as a failed result the model can react to.
            $this->audit('command.failed', $command, $ctx, $canonical, $e->getMessage());
            ErrorLog::record('command', sprintf('%s threw %s: %s', $id, get_class($e), $e->getMessage()));
            return CommandResult::error('command.failed', $e->getMessage());
        }

        if (defined('WP_DEBUG') && WP_DEBUG && $command->outputSchema !== []) {
            $checked = rest_validate_value_from_schema($data, $command->outputSchema, $id . '.output');
            if (is_wp_error($checked)) {
                ErrorLog::record('command', sprintf('%s returned data its schema rejects: %s', $id, $checked->get_error_message()));
            }
        }

        return CommandResult::ok(is_array($data) ? $data : ['result' => $data]);
    }

    /**
     * Read-only "what will this change" for a command. Runs the same front-half
     * gates as dispatch (existence, permission, input validation), and when they
     * pass and the command carries a preview closure, returns its change rows.
     * The handler is never called. Any failed gate, a missing closure, or a
     * throwing closure yields [] - a preview must never break the flow, so a
     * caller can safely show the operator the diff before they approve.
     *
     * @param array<string,mixed> $input
     * @return list<array{label:string, from?:string, to:string}>
     * @since 1.0.0
     */
    public function previewFor(string $id, array $input, CommandContext $ctx): array
    {
        $command = $this->authorize($id, $ctx);
        if ($command instanceof CommandResult) {
            return [];
        }
        $canonical = $this->canonicalize($command, $input);
        if ($canonical instanceof CommandResult) {
            return [];
        }
        if ($command->preview === null) {
            return [];
        }
        try {
            $rows = ($command->preview)($canonical);
        } catch (\Throwable $e) {
            return [];
        }
        return is_array($rows) ? array_values($rows) : [];
    }

    /**
     * The inverse of a reversible command: if the gate would pass and the command
     * carries a reverse closure, returns the input that undoes it (computed from
     * the current, pre-mutation state), or null. The handler is never called; a
     * failed gate, a missing closure, or a throwing closure all yield null. Call
     * this BEFORE dispatching the forward command, while the "before" state holds.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|null
     * @since 1.0.0
     */
    public function reverseFor(string $id, array $input, CommandContext $ctx): ?array
    {
        $command = $this->authorize($id, $ctx);
        if ($command instanceof CommandResult) {
            return null;
        }
        $canonical = $this->canonicalize($command, $input);
        if ($canonical instanceof CommandResult) {
            return null;
        }
        if ($command->reverse === null) {
            return null;
        }
        try {
            $inverse = ($command->reverse)($canonical);
        } catch (\Throwable $e) {
            return null;
        }
        return is_array($inverse) && $inverse !== [] ? $inverse : null;
    }

    /**
     * Existence + permission gate shared by dispatch() and previewFor(). Returns
     * the Command when the caller may run it, or a CommandResult error
     * (not_found or denied). No audit here: the caller decides whether a denial
     * is recorded (dispatch does; a read-only preview does not).
     *
     * @return Command|CommandResult
     * @since 1.0.0
     */
    private function authorize(string $id, CommandContext $ctx): Command|CommandResult
    {
        $command = $this->commands[$id] ?? null;
        if (! $command) {
            return CommandResult::error('command.not_found', "Unknown command '{$id}'.");
        }
        $allowed = in_array($ctx->source, ['rest', 'cli'], true)
            ? current_user_can($command->capability)
            : ($ctx->user_id !== null && user_can($ctx->user_id, $command->capability));
        if (! $allowed) {
            return CommandResult::error('command.denied', "Not permitted: {$command->capability}.");
        }
        return $command;
    }

    /**
     * Validate + sanitize input against a command's schema. Returns the
     * canonical (sanitized) input, or a CommandResult invalid_input error. A
     * command with no schema passes its input through unchanged.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>|CommandResult
     * @since 1.0.0
     */
    private function canonicalize(Command $command, array $input): array|CommandResult
    {
        if ($command->inputSchema === []) {
            return $input;
        }
        $valid = rest_validate_value_from_schema($input, $command->inputSchema, $command->id);
        if (is_wp_error($valid)) {
            return CommandResult::error('command.invalid_input', $valid->get_error_message());
        }
        $sanitized = rest_sanitize_value_from_schema($input, $command->inputSchema, $command->id);
        if (is_wp_error($sanitized)) {
            return CommandResult::error('command.invalid_input', $sanitized->get_error_message());
        }
        return is_array($sanitized) ? $sanitized : (array) $sanitized;
    }

    /**
     * Fixed-window token bucket per (rate-limit key, source), backed by WP transients.
     * meta.rate_limit = cap per 60 s window; meta.rate_limit_key groups commands; <= 0 disables.
     *
     * @since 1.0.0
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
     * Record to the dono_events firehose. Input is hashed, PII never stored raw.
     *
     * @param array<string,mixed> $input
     * @since 1.0.0
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
