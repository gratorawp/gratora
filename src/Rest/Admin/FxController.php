<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Currency\FxRates;
use Dono\Gateways\GatewayManager;
use Dono\Currency\FxRatesUpdater;
use Dono\Foundation\Helpers\Money;
use Dono\Settings\SettingsService;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Exchange-rate settings surface for the Currency panel; POST /fetch refreshes
 * immediately regardless of the auto toggle.
 *
 * @since 1.0.0
 */
final class FxController
{
    private const NAMESPACE = 'dono/v1';

    /** @since 1.0.0 */
    public function __construct(
        private FxRates $fx,
        private FxRatesUpdater $updater,
        private SettingsService $settings,
        private GatewayManager $gateways,
    ) {
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/currency/fx', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/currency/fx/fetch', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'fetch'],
            'permission_callback' => [$this, 'canAccess'],
        ]);
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    /** @since 1.0.0 */
    public function show(): WP_REST_Response
    {
        return new WP_REST_Response($this->state(), 200);
    }

    /** @since 1.0.0 */
    public function update(WP_REST_Request $request): WP_REST_Response
    {
        $body = (array) $request->get_json_params();

        $manual = [];
        foreach ((array) ($body['manual'] ?? []) as $code => $val) {
            if ($val === null || $val === '') {
                continue; // omitted/blank clears the override
            }
            $rate = (float) $val;
            if ($rate > 0.0) {
                $manual[strtoupper((string) $code)] = $rate;
            }
        }

        // The base the client read its rates in. A base change saved from this
        // same screen restates the whole snapshot, and the rates below it were
        // rendered before that happened.
        $frame = (string) ($body['frame'] ?? '');

        if (! $this->updater->saveSettings((bool) ($body['auto'] ?? true), $manual, $frame)) {
            return new WP_REST_Response([
                'code'    => 'dono_fx_frame_moved',
                'message' => __('The base currency changed while this screen was open, so the rates shown are no longer in the currency they were entered against. Reload the page and set them again.', 'dono-fundraising-platform'),
            ], 409);
        }

        return new WP_REST_Response($this->state(), 200);
    }

    /** @since 1.0.0 */
    public function fetch(): WP_REST_Response
    {
        $ok = $this->updater->fetchNow();
        $state = $this->state();
        $state['fetch_ok'] = $ok;
        return new WP_REST_Response($state, 200);
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function state(): array
    {
        $base = strtoupper(Money::defaultCurrency());

        $cur       = $this->settings->get('currency-locale');
        $supported = is_array($cur['supported_currencies'] ?? null)
            ? array_map('strtoupper', $cur['supported_currencies'])
            : [$base];

        $codes    = array_values(array_unique(array_merge([$base], $supported)));
        $manual   = $this->fx->manual();
        $fetched  = $this->fx->fetchedRates();

        $rows = [];
        foreach ($codes as $code) {
            $isBase = $code === $base;
            $rows[] = [
                'code'      => $code,
                'is_base'   => $isBase,
                'rate'      => $isBase ? 1.0 : $this->fx->effectiveRate($code),
                'auto_rate' => $isBase ? 1.0 : ($fetched[$code] ?? null),
                'is_manual' => isset($manual[$code]),
            ];
        }

        return [
            'base'       => $base,
            // What the rate column is actually denominated in, which is the org
            // base in every healthy state and the one the client has to declare
            // back on a write. Not merged into 'base': that one labels the
            // column and is the org's own currency whatever the snapshot holds.
            'frame'      => $this->fx->base() ?? $base,
            'auto'       => $this->fx->auto(),
            'stale'      => $this->fx->isStale(),
            'date'       => $this->fx->date(),
            'fetched_at' => $this->fx->fetchedAt(),
            'source'     => 'European Central Bank (Frankfurter)',
            'rows'       => $rows,
            // Currencies the org offers that have no rate to the base. A
            // donation in one is still accepted, but it counts as zero in every
            // base-currency total, so the admin has to be told before they
            // offer it rather than discovering it in a report.
            'unconvertible' => $this->fx->unconvertible($supported),
            // Currencies the org offers that no enabled gateway can charge.
            // The donation form has to say so at the point of choice, which is
            // late: the currency was offered on a page a donor had already
            // reached. Say it here, where it was added.
            'no_gateway' => $this->currenciesWithoutGateway($supported),
        ];
    }

    /**
     * @param  array<int,string> $supported
     * @return array<int,string>
     *
     * @since 1.0.0
     */
    private function currenciesWithoutGateway(array $supported): array
    {
        $out = [];
        foreach ($supported as $code) {
            $code = strtoupper(trim((string) $code));
            if ($code === '') {
                continue;
            }
            // optionsFor(), not availableFor(): the latter filters on
            // canCharge() alone and ignores the per-gateway enabled flag.
            if ($this->gateways->optionsFor([], null, $code, 'one_time') === []) {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }
}
