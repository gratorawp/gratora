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
 */
final class FxController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private FxRates $fx,
        private FxRatesUpdater $updater,
        private SettingsService $settings,
        private GatewayManager $gateways,
    ) {
    }

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

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_settings');
    }

    public function show(): WP_REST_Response
    {
        return new WP_REST_Response($this->state(), 200);
    }

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

        $this->updater->saveSettings((bool) ($body['auto'] ?? true), $manual);

        return new WP_REST_Response($this->state(), 200);
    }

    public function fetch(): WP_REST_Response
    {
        $ok = $this->updater->fetchNow();
        $state = $this->state();
        $state['fetch_ok'] = $ok;
        return new WP_REST_Response($state, 200);
    }

    /** @return array<string,mixed> */
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
            // canCharge() alone and ignores the per-gateway enabled flag,
            // so a disabled Offline still counted and this never fired.
            if ($this->gateways->optionsFor([], null, $code, 'one_time') === []) {
                $out[] = $code;
            }
        }

        return array_values(array_unique($out));
    }
}
