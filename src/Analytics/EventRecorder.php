<?php

declare(strict_types=1);

namespace FundKit\Analytics;

use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Foundation\Time\Clock;
use FundKit\Settings\SettingsService;

/**
 * Records analytics events to the fundkit_events table.
 *
 * @since 1.0.0
 */
final class EventRecorder
{
    /** @since 1.0.0 */
    public function __construct(
        private IdentityHasher $hasher,
        private Clock $clock,
        private SettingsService $settings,
    ) {
    }

    /**
     * @param string $type  e.g. 'donation.completed', 'form.viewed'
     * @param array  $ctx   any of: donor_id, donation_id, recurring_plan_id, form_id, campaign_id,
     *                      receipt_id, session_hash, user_id, country, amount_cents, currency, payload
     *
     * @since 1.0.0
     */
    public function record(string $type, array $ctx = []): void
    {
        try {
            $this->write($type, $ctx);
        } catch (\Throwable $e) {
            // Observability only: an analytics write must never abort the
            // donation transaction record() is often called within.
            ErrorLog::toDebugLog('event record failed: ' . $e->getMessage());
        }
    }

    /** @since 1.0.0 */
    private function write(string $type, array $ctx): void
    {
        $ctx = apply_filters('fundkit.event.recording', $ctx, $type);

        // A donor.* row is the audit trail of what was done TO a donor, and
        // erasure keeps it on purpose while clearing everything else. It must
        // therefore carry no handle that re-links it to a person: for a portal
        // self-erasure the requester is the donor being erased, so their IP and
        // user-agent hashes would sit next to their donor_id for good.
        $audit = str_starts_with($type, 'donor.');

        $event = Event::make();
        $event->type              = $type;
        $event->donor_id          = isset($ctx['donor_id']) ? (int) $ctx['donor_id'] : null;
        $event->donation_id       = isset($ctx['donation_id']) ? (int) $ctx['donation_id'] : null;
        $event->recurring_plan_id = isset($ctx['recurring_plan_id']) ? (int) $ctx['recurring_plan_id'] : null;
        $event->form_id           = isset($ctx['form_id']) ? (int) $ctx['form_id'] : null;
        $event->campaign_id       = isset($ctx['campaign_id']) ? (int) $ctx['campaign_id'] : null;
        $event->receipt_id        = isset($ctx['receipt_id']) ? (int) $ctx['receipt_id'] : null;
        $event->session_hash      = ! $audit && isset($ctx['session_hash']) ? (string) $ctx['session_hash'] : null;
        $event->user_id           = isset($ctx['user_id']) ? (int) $ctx['user_id'] : null;
        $event->country           = isset($ctx['country']) ? strtoupper(substr((string) $ctx['country'], 0, 2)) : null;
        $event->amount_cents      = isset($ctx['amount_cents']) ? (int) $ctx['amount_cents'] : null;
        $event->currency          = isset($ctx['currency']) ? strtoupper(substr((string) $ctx['currency'], 0, 3)) : null;
        $event->payload           = $ctx['payload'] ?? null;
        $privacy = $this->settings->get('privacy');
        $event->ip_hash           = ! $audit && ! empty($privacy['anonymize_ips'])
            ? $this->hasher->ipHash(
                filter_var(wp_unslash($_SERVER['REMOTE_ADDR'] ?? ''), FILTER_VALIDATE_IP) ?: null
            )
            : null;
        $event->user_agent_hash   = $audit ? null : $this->hasher->userAgentHash(
            wp_strip_all_tags(wp_unslash($_SERVER['HTTP_USER_AGENT'] ?? ''))
        );
        $event->occurred_at       = $this->clock->now()->format('Y-m-d H:i:s');

        $event->save();

        do_action('fundkit.event.recorded', $event);
    }
}
