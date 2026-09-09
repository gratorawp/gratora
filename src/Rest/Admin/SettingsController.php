<?php

declare(strict_types=1);

namespace Gratora\Rest\Admin;
use Gratora\Currency\BaseCurrencyLock;
use Gratora\Currency\BaseCurrencyLocked;
use Gratora\Donors\DonorRetention;
use Gratora\Foundation\Auth\Capabilities;
use Gratora\Foundation\References\InvalidReferenceToken;
use Gratora\Settings\SecretRedactor;
use Gratora\Settings\SettingsService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/** @since 1.0.0 */
final class SettingsController
{
    private const NAMESPACE = 'gratora/v1';

    /** @since 1.0.0 */
    public function __construct(
        private SettingsService $settings,
        private DonorRetention $retention,
    ) {
    }

    /** @since 1.0.0 */
    public function retentionPreview(WP_REST_Request $request): WP_REST_Response
    {
        $years = $request['years'] === null ? null : (int) $request['years'];

        return new WP_REST_Response($this->retention->preview((int) $request['days'], $years), 200);
    }

    /** @since 1.0.0 */
    public function registerRoutes(): void
    {
        // Read-only, and deliberately not part of the privacy group: it is a
        // count of what the retention sweep would take, not a setting. Omitting
        // years asks about the window in force; sending one asks about a window
        // the panel is still choosing, which is when the count can still stop
        // someone.
        register_rest_route(self::NAMESPACE, '/admin/settings/retention-preview', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'retentionPreview'],
            'permission_callback' => [$this, 'canAccess'],
            'args'                => [
                'days'  => ['type' => 'integer', 'default' => 30, 'minimum' => 1, 'maximum' => 365],
                'years' => ['type' => 'integer', 'minimum' => 0, 'maximum' => 100],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/admin/settings/(?P<group>[a-z0-9_-]+)', [
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
    }

    /** @since 1.0.0 */
    public function canAccess(): bool
    {
        return Capabilities::userCan('gratora_manage_settings');
    }

    /** @since 1.0.0 */
    public function show(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('gratora_unknown_group', __('Unknown settings group.', 'gratora'), ['status' => 404]);
        }
        // Never hand a stored secret back out. The gateways group holds the
        // Stripe webhook signing secret, which is the only authentication on
        // the webhook route: reading it is enough to forge a paid donation.
        $data = SecretRedactor::redact($this->settings->get($group));

        return new WP_REST_Response(self::withReadOnly($group, $data), 200);
    }

    /** @since 1.0.0 */
    public function update(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $group = (string) $request['group'];
        if (! $this->settings->knows($group)) {
            return new WP_Error('gratora_unknown_group', __('Unknown settings group.', 'gratora'), ['status' => 404]);
        }
        // Assigning Gratora capabilities to roles grants privileges, so it needs
        // full admin - not the delegatable gratora_manage_settings, which a scoped
        // role could otherwise use to grant itself refund/redact/export caps.
        if ($group === 'roles' && ! current_user_can('manage_options')) {
            return new WP_Error('gratora_forbidden', __('Managing roles requires full administrator access.', 'gratora'), ['status' => 403]);
        }
        $body = (array) $request->get_json_params();
        // Whitelist to known top-level keys for this group so arbitrary keys
        // can't be planted in the option by a curious caller. SettingsService
        // deep-merges from here, so nested validation is each panel's job.
        $allowed = array_keys($this->settings->get($group));
        if ($allowed !== []) {
            $body = array_intersect_key($body, array_flip($allowed));
        }
        $body = $this->sanitize($body);
        // The read path masks secrets, so a client that round-trips the group
        // sends the mask back. Put the stored value behind it, otherwise saving
        // any unrelated field would wipe the signing secret.
        $body  = SecretRedactor::restore($body, $this->settings->get($group));

        // Arming or shortening the nightly sweep destroys donor contact details
        // on a schedule and cannot be undone, so it cannot cost less capability
        // than redacting one donor by hand does.
        if ($group === 'privacy' && $this->widensErasure($body) && ! Capabilities::userCan('gratora_redact_donors')) {
            return new WP_Error(
                'gratora_forbidden',
                __('Automatic donor erasure can only be changed by someone who may redact donors.', 'gratora'),
                ['status' => 403]
            );
        }

        // The invariant lives in SettingsService so every writer inherits it;
        // the controller's job is only to give the refusal an HTTP shape.
        try {
            $saved = $this->settings->update($group, $body);
        } catch (BaseCurrencyLocked $e) {
            return new WP_Error('gratora_base_currency_locked', $e->getMessage(), ['status' => 409]);
        } catch (InvalidReferenceToken $e) {
            return new WP_Error('gratora_invalid_reference_token', $e->getMessage(), ['status' => 400]);
        } catch (\InvalidArgumentException $e) {
            // A value the writer refuses on its own terms: a currency that is
            // not a code, a numbering format too long for the column. The
            // sentence is written for the admin, so it is the response.
            return new WP_Error('gratora_invalid_setting', $e->getMessage(), ['status' => 422]);
        }

        // The same read-only fields the GET carries. The client replaces its
        // whole record with this reply, so leaving them out unlocked the base
        // currency picker on a locked site the moment anything was saved.
        return new WP_REST_Response(self::withReadOnly($group, SecretRedactor::redact($saved)), 200);
    }

    /**
     * Whether this save turns the sweep on, or aims it at donors it did not
     * reach before. Turning it off or lengthening the window is not a widening.
     *
     * @param array<string,mixed> $body
     */
    private function widensErasure(array $body): bool
    {
        $current = $this->settings->get('privacy');

        if (array_key_exists('erase_inactive_donors', $body)
            && ! empty($body['erase_inactive_donors'])
            && empty($current['erase_inactive_donors'])) {
            return true;
        }

        if (! array_key_exists('donor_retention_years', $body)) {
            return false;
        }

        $armed = array_key_exists('erase_inactive_donors', $body)
            ? ! empty($body['erase_inactive_donors'])
            : ! empty($current['erase_inactive_donors']);

        $window = (int) $body['donor_retention_years'];

        // A window of zero switches the sweep off, which is the opposite of a
        // widening and must not need the redact capability.
        return $armed
            && $window > 0
            && $window < (int) ($current['donor_retention_years'] ?? 7);
    }

    /**
     * Fields the screen needs that are not part of the stored shape: accept()
     * drops them on the way back in.
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function withReadOnly(string $group, array $data): array
    {
        if ($group === 'currency-locale') {
            $data['base_currency_locked'] = BaseCurrencyLock::isLocked();
        }

        return $data;
    }

    /**
     * Recursively sanitize settings values. Email/receipt templates are sent as
     * plain text, so settings hold no HTML; sanitize_textarea_field strips tags
     * + control chars while preserving line breaks and leaving ampersands and a
     * stray "<" intact (wp_kses_post would turn "&" into "&amp;" and eat "<").
     *
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function sanitize(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value)) {
                $clean = $this->stripUnsafe($value);
                $data[$key] = in_array($key, self::WHITESPACE_IS_THE_VALUE, true) ? $clean : trim($clean);
            } elseif (is_array($value)) {
                $data[$key] = $this->sanitize($value);
            }
        }
        return $data;
    }


    /**
     * Settings whose value can legitimately be a space, so the trim below is
     * not applied to them.
     *
     * A thousands separator of " " arrived as "" and the screen came back
     * saying none. Swedish, Norwegian, Polish, Czech and South African money is
     * written that way, so the format they need was the one that could not be
     * saved.
     */
    private const WHITESPACE_IS_THE_VALUE = ['decimal_sep', 'thousand_sep'];

    /**
     * Tags, invalid UTF-8 and control characters removed, and nothing else.
     *
     * A settings string can carry a percent-encoded URL, and WordPress's text
     * sanitizers delete every %XX run they find, which turns a tracked link in
     * a template into a dead one. Ordinary and non-breaking spaces are left
     * alone; the caller decides which keys are trimmed.
     *
     * @since 1.0.0
     */
    private function stripUnsafe(string $value): string
    {
        $clean = wp_check_invalid_utf8($value);

        if (str_contains($clean, '<')) {
            // Escaped before stripping: strip_tags reads "<3" as an unclosed
            // tag and takes the rest of the body with it.
            $clean = wp_pre_kses_less_than($clean);
            $clean = (string) preg_replace('@<(script|style)[^>]*?>.*?</\1>@si', '', $clean);
            $clean = strip_tags($clean);
        }

        return (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $clean);
    }
}
