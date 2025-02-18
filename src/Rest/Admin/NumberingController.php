<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;

use Dono\Foundation\Auth\Capabilities;
use Dono\Foundation\References\ReferenceGenerator;
use Throwable;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin endpoints for the reference-number counters: read the next value per
 * scope, and override it. Overrides only move forward; the generator rejects a
 * value <= the current counter so references can never duplicate.
 *
 * @version 1.0.0
 */
final class NumberingController
{
    private const NAMESPACE = 'dono/v1';
    private const SCOPES = ['donation', 'receipt', 'refund'];

    public function __construct(private ReferenceGenerator $references)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/numbering/counters', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'counters'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/numbering/counter', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'setCounter'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'scope' => [
                    'type'     => 'string',
                    'enum'     => self::SCOPES,
                    'required' => true,
                ],
                'next' => [
                    'type'     => 'integer',
                    'required' => true,
                    'minimum'  => 1,
                ],
            ],
        ]);
    }

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    /** Next reference number per scope, without incrementing. */
    public function counters(): WP_REST_Response
    {
        $out = [];
        foreach (self::SCOPES as $scope) {
            $out[$scope] = $this->references->peekNext($scope);
        }
        return new WP_REST_Response($out, 200);
    }

    /** Override a scope's counter so the next reference uses $next. */
    public function setCounter(WP_REST_Request $request)
    {
        $scope = (string) $request->get_param('scope');
        $next  = (int) $request->get_param('next');

        try {
            $this->references->nextNumber($scope, $next);
        } catch (Throwable $e) {
            return new WP_Error('dono_numbering_invalid', $e->getMessage(), ['status' => 400]);
        }

        return new WP_REST_Response([
            'scope' => $scope,
            'next'  => $this->references->peekNext($scope),
        ], 200);
    }
}
