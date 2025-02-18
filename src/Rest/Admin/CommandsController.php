<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Commands\CommandContext;
use Dono\Foundation\Commands\CommandRegistry;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exposes the command catalogue and a single invocation endpoint.
 *
 * Route-level gate is manage_options; per-command capability is enforced
 * inside CommandRegistry::dispatch().
 *
 * @version 1.0.0
 */
final class CommandsController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(private CommandRegistry $registry)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/commands', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'manifest'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/commands/(?P<id>[a-z0-9_.]+)', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'dispatch'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    public function canAccess(): bool
    {
        return current_user_can('manage_options');
    }

    public function manifest(): WP_REST_Response
    {
        return new WP_REST_Response($this->registry->manifest(), 200);
    }

    public function dispatch(WP_REST_Request $request): WP_REST_Response
    {
        $id   = (string) $request['id'];
        $body = (array) ($request->get_json_params() ?? []);

        $ctx = new CommandContext(
            user_id: get_current_user_id() ?: null,
            source: 'rest',
            request_id: 'rest-' . wp_generate_uuid4(),
            dry_run: ! empty($body['dry_run']),
            confirmation: isset($body['confirmation']) ? (string) $body['confirmation'] : null,
        );

        $result = $this->registry->dispatch($id, (array) ($body['input'] ?? []), $ctx);

        if ($result->ok) {
            return new WP_REST_Response($result->data, 200);
        }

        return new WP_REST_Response([
            'code'    => $result->error_code,
            'message' => $result->error,
            'data'    => $result->data,
        ], $this->statusFor((string) $result->error_code));
    }

    private function statusFor(string $code): int
    {
        return match ($code) {
            'command.not_found'             => 404,
            'command.invalid_input'         => 400,
            'command.denied'                => 403,
            'command.confirmation_required' => 409,
            'command.rate_limited'          => 429,
            default                         => 422,
        };
    }
}
