<?php

declare(strict_types=1);

namespace Dono\Rest\Admin;
use Dono\Foundation\Auth\Capabilities;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\Styling\CampaignStyleResolver;
use Dono\Forms\Form;
use Dono\Forms\FormRepository;
use Dono\Forms\FormService;
use Dono\Forms\FormTemplates;
use Dono\Forms\Shortcode\DonationFormShortcode;
use Dono\Funds\FundRepository;
use Dono\Gateways\GatewayManager;
use Dono\Rest\Schemas\FormSchemas;
use Dono\Settings\SettingsService;
use InvalidArgumentException;
use RuntimeException;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Admin form endpoints: list, show, create, update, delete, campaigns picker,
 * preview render.
 *
 * @version 1.0.0
 */
final class FormsController
{
    private const NAMESPACE = 'dono/v1';

    public function __construct(
        private FormRepository $forms,
        private FormService $formService,
        private CampaignRepository $campaigns,
        private GatewayManager $gateways,
        private CampaignStyleResolver $styles,
        private \Dono\Forms\FormReadinessService $readiness,
        private FundRepository $funds,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/admin/forms', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'index'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => [
                    'page'        => ['type' => 'integer', 'default' => 1, 'minimum' => 1],
                    'per_page'    => ['type' => 'integer', 'default' => 25, 'minimum' => 1, 'maximum' => 100],
                    'orderby'     => ['type' => 'string', 'default' => 'updated_at'],
                    'order'       => ['type' => 'string', 'enum' => ['asc', 'desc'], 'default' => 'desc'],
                    'status'      => ['type' => 'string'],
                    'campaign_id' => ['type' => 'integer'],
                    'search'      => ['type' => 'string'],
                ],
            ],
            [
                'methods'             => WP_REST_Server::CREATABLE,
                'callback'            => [$this, 'create'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => FormSchemas::create(),
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/campaigns', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'campaigns'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/gateways', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'gatewaysList'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/funds', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'fundsList'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/currencies', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'currenciesList'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/preview', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'preview'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'blocks' => ['type' => 'string'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/templates', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'templates'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/(?P<id>\d+)/duplicate', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'duplicate'],
            'permission_callback' => [$this, 'canAccess'],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/(?P<id>\d+)/readiness', [
            // GET checks the stored form; POST carries the live editor blocks so
            // the checklist reflects unsaved edits.
            'methods'             => WP_REST_Server::READABLE . ', ' . WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'readiness'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'id' => ['type' => 'integer', 'required' => true],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/forms/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::READABLE,
                'callback'            => [$this, 'show'],
                'permission_callback' => [$this, 'canAccess'],
            ],
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'update'],
                'permission_callback' => [$this, 'canAccess'],
                'args'                => FormSchemas::update(),
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'delete'],
                'permission_callback' => [$this, 'canAccess'],
            ],
        ]);
    }

    public function canAccess(): bool
    {
        return Capabilities::userCan('dono_manage_forms');
    }

    public function index(WP_REST_Request $request): WP_REST_Response
    {
        $perPage = (int) ($request['per_page'] ?? 25);

        $result = $this->forms->listAdmin([
            'page'        => (int) ($request['page'] ?? 1),
            'per_page'    => $perPage,
            'orderby'     => (string) ($request['orderby'] ?? 'updated_at'),
            'order'       => (string) ($request['order']   ?? 'desc'),
            'status'      => $request['status'] !== null ? (string) $request['status'] : null,
            'campaign_id' => $request['campaign_id'] !== null ? (int) $request['campaign_id'] : null,
            'search'      => $request['search'] !== null ? (string) $request['search'] : null,
        ]);

        // Bulk-load referenced campaigns to avoid N+1.
        $campaignIds = array_unique(array_map(
            fn (Form $f): int => $f->campaign_id,
            $result['items'],
        ));
        $campaignCache = [];
        foreach ($campaignIds as $cid) {
            $campaignCache[$cid] = $this->campaigns->findById((int) $cid);
        }

        $shaped = array_map(
            fn (Form $f): array => $this->shapeFormSummary($f, $campaignCache[$f->campaign_id] ?? null),
            $result['items'],
        );

        $response = new WP_REST_Response($shaped, 200);
        $response->header('X-WP-Total',      (string) $result['total']);
        $response->header('X-WP-TotalPages', (string) max(1, (int) ceil($result['total'] / max(1, $perPage))));
        return $response;
    }

    public function campaigns(): WP_REST_Response
    {
        $shaped = array_map(
            fn (Campaign $c): array => [
                'id'           => $c->id,
                'title'        => $c->title,
                'slug'         => $c->slug,
                'status'       => $c->status,
                'currency'     => (string) ($c->currency ?? 'USD'),
                'goal_type'    => (string) ($c->goal_type ?? 'amount'),
                'goal_cents'   => (int) ($c->goal_cents ?? 0),
                'goal_count'   => (int) ($c->goal_count ?? 0),
                'raised_cents' => (int) ($c->raised_cents ?? 0),
                'donations_count' => (int) ($c->donations_count ?? 0),
                'donors_count' => (int) ($c->donors_count ?? 0),
                'ends_at'      => $c->ends_at ? (string) $c->ends_at : null,
                'style'        => is_array($c->style) ? $c->style : null,
            ],
            $this->campaigns->listForPicker(),
        );
        return new WP_REST_Response($shaped, 200);
    }

    public function gatewaysList(): WP_REST_Response
    {
        $shaped = [];
        foreach ($this->gateways->all() as $gateway) {
            $shaped[] = [
                'id'         => $gateway->id(),
                'label'      => $gateway->label(),
                'frequencies' => $gateway->frequencies(),
            ];
        }
        return new WP_REST_Response($shaped, 200);
    }

    public function fundsList(): WP_REST_Response
    {
        return new WP_REST_Response($this->funds->pickerOptions(), 200);
    }

    /**
     * Org-enabled currencies the currency-switcher block may offer. Base
     * first; the block can only pick from this set.
     */
    public function currenciesList(): WP_REST_Response
    {
        $cur  = (new SettingsService())->get('currency-locale');
        $base = strtoupper((string) ($cur['default_currency'] ?? 'USD'));

        $supported = is_array($cur['supported_currencies'] ?? null)
            ? array_map(static fn ($c): string => strtoupper((string) $c), $cur['supported_currencies'])
            : [];

        $codes = array_values(array_unique(array_merge([$base], $supported)));

        return new WP_REST_Response(['base' => $base, 'currencies' => $codes], 200);
    }

    public function templates(): WP_REST_Response
    {
        return new WP_REST_Response(FormTemplates::all(), 200);
    }

    public function readiness(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $form = $this->forms->findById((int) $request['id']);
        if (! $form) {
            return new WP_Error('dono_not_found', __('Form not found.', 'dono'), ['status' => 404]);
        }

        // A POST carries the live editor blocks so checks reflect unsaved edits;
        // applied in-memory only (never persisted). Settings stay as saved.
        $body = (array) ($request->get_json_params() ?? []);
        if (array_key_exists('blocks', $body)) {
            $form->blocks = $this->formService->sanitizeBlocks((string) $body['blocks']);
        }

        return new WP_REST_Response([
            'checks' => $this->readiness->check($form),
        ], 200);
    }

    public function preview(WP_REST_Request $request): WP_REST_Response
    {
        $body       = (array) ($request->get_json_params() ?? []);
        $blocks     = $this->formService->sanitizeBlocks((string) ($body['blocks'] ?? ''));
        $settings   = is_array($body['settings'] ?? null) ? $body['settings'] : null;
        $campaignId = isset($body['campaign_id']) ? (int) $body['campaign_id'] : null;

        $shortcode = new DonationFormShortcode($this->forms, $this->styles, $this->campaigns, null, $this->gateways);
        $preview   = $shortcode->renderPreview($blocks, $settings, $campaignId);

        $cssUrl  = esc_url($preview['cssUrl']);
        $jsUrl   = esc_url($preview['jsUrl']);
        // $blocks was block-aware sanitized above (kses'd for authors lacking
        // unfiltered_html), so this do_blocks() output is safe to inline; the
        // interpolated URLs are esc_url'd.
        $formHtml = $preview['html'];

        // Inline script-handle deps; the preview iframe is a standalone document.
        $depScripts = '';
        $scripts    = wp_scripts();
        foreach ($preview['jsDeps'] as $handle) {
            $reg = $scripts->registered[$handle] ?? null;
            $src = $reg ? (string) $reg->src : '';
            if ($src !== '') {
                $url = strpos($src, 'http') === 0 ? $src : site_url($src);
                $depScripts .= '<script src="' . esc_url($url) . '"></script>' . "\n";
            }
        }

        $doc = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{$cssUrl}">
    <style>
        html, body { margin: 0; padding: 0; background: #f0f0f1; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif; }
        body { padding: 32px 16px; min-height: 100vh; }
    </style>
</head>
<body>
    {$formHtml}
    {$depScripts}
    <script src="{$jsUrl}"></script>
</body>
</html>
HTML;

        return new WP_REST_Response([
            'html' => $doc,
        ], 200);
    }

    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $form = $this->forms->findById((int) $request['id']);
        if (! $form) {
            return new WP_Error('dono_not_found', __('Form not found.', 'dono'), ['status' => 404]);
        }
        $campaign = $form->campaign_id ? $this->campaigns->findById((int) $form->campaign_id) : null;
        return new WP_REST_Response($this->shapeFormFull($form, $campaign), 200);
    }

    public function duplicate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $source = $this->forms->findById((int) $request['id']);
        if (! $source) {
            return new WP_Error('dono_not_found', __('Form not found.', 'dono'), ['status' => 404]);
        }
        try {
            $copy = $this->formService->duplicate($source);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_form_duplicate_failed', $e->getMessage(), ['status' => 500]);
        }
        $campaign = $copy->campaign_id ? $this->campaigns->findById((int) $copy->campaign_id) : null;
        return new WP_REST_Response($this->shapeFormFull($copy, $campaign), 201);
    }

    public function create(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $body = (array) ($request->get_json_params() ?? []);
        try {
            $form = $this->formService->create($body);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_form_create_failed', $e->getMessage(), ['status' => 500]);
        }

        $campaign = $form->campaign_id ? $this->campaigns->findById((int) $form->campaign_id) : null;
        return new WP_REST_Response($this->shapeFormFull($form, $campaign), 201);
    }

    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $form = $this->forms->findById((int) $request['id']);
        if (! $form) {
            return new WP_Error('dono_not_found', __('Form not found.', 'dono'), ['status' => 404]);
        }

        $body = (array) ($request->get_json_params() ?? []);
        try {
            $form = $this->formService->update($form, $body);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_invalid_input', $e->getMessage(), ['status' => 422]);
        }

        $campaign = $form->campaign_id ? $this->campaigns->findById((int) $form->campaign_id) : null;
        return new WP_REST_Response($this->shapeFormFull($form, $campaign), 200);
    }

    public function delete(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $form = $this->forms->findById((int) $request['id']);
        if (! $form) {
            return new WP_Error('dono_not_found', __('Form not found.', 'dono'), ['status' => 404]);
        }
        try {
            $this->formService->delete($form);
        } catch (InvalidArgumentException $e) {
            return new WP_Error('dono_form_delete_blocked', $e->getMessage(), ['status' => 422]);
        } catch (RuntimeException $e) {
            return new WP_Error('dono_form_delete_blocked', $e->getMessage(), ['status' => 422]);
        }
        return new WP_REST_Response(['deleted' => true, 'id' => $form->id], 200);
    }

    private function shapeFormSummary(Form $f, ?Campaign $c): array
    {
        return [
            'id'           => $f->id,
            'title'        => $f->title,
            'slug'         => $f->slug,
            'status'       => $f->status,
            'campaign_id'  => $f->campaign_id,
            'campaign'     => $c ? ['id' => $c->id, 'title' => $c->title, 'slug' => $c->slug] : null,
            'published_at' => $f->published_at,
            'updated_at'   => $f->updated_at,
            'created_at'   => $f->created_at,
        ];
    }

    private function shapeFormFull(Form $f, ?Campaign $c): array
    {
        return $this->shapeFormSummary($f, $c) + [
            'default_fund_id' => $f->default_fund_id,
            'blocks'          => $f->blocks,
            'settings'        => $f->settings,
            'spec'            => $f->spec,
            'spec_version'    => $f->spec_version,
        ];
    }
}
