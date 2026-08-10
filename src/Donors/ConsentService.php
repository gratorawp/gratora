<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;
use Dono\Settings\SettingsService;

/**
 * Reads, records, and retrieves donor consent rows.
 *
 * @since 1.0.0
 */
final class ConsentService
{
    /** @since 1.0.0 */
    public function __construct(
        private IdentityHasher $hasher,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<array{key:string,label:string,description:string,required:bool,default:bool,version:int}>
     *
     * @since 1.0.0
     */
    public function purposes(): array
    {
        // Read through SettingsService so the configured defaults (newsletter,
        // campaign updates) apply even when dono_consents was never saved. The
        // admin panel reads the same way, so portal and admin stay in sync.
        $stored = (new SettingsService())->get('consents');
        $raw    = is_array($stored['purposes'] ?? null) ? $stored['purposes'] : [];

        $out = [];
        foreach ($raw as $p) {
            if (! is_array($p)) continue;
            $key = (string) ($p['key'] ?? '');
            if ($key === '') continue;
            $out[] = [
                'key'         => $key,
                'label'       => (string) ($p['label']       ?? $key),
                'description' => (string) ($p['description'] ?? ''),
                'required'    => (bool)   ($p['required']    ?? false),
                'default'     => (bool)   ($p['default']     ?? false),
                'version'     => (int)    ($p['version']     ?? 1),
            ];
        }
        return $out;
    }

    /** @since 1.0.0 */
    public function findPurpose(string $key): ?array
    {
        foreach ($this->purposes() as $p) {
            if ($p['key'] === $key) return $p;
        }
        return null;
    }

    /**
     * Newest Consent row per purpose for a donor.
     *
     * @return array<string, Consent>
     *
     * @since 1.0.0
     */
    public function latestByPurpose(int $donorId): array
    {
        if ($donorId <= 0) return [];

        $rows = Consent::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->getAll();

        $out = [];
        foreach ($rows as $r) {
            if (! isset($out[$r->purpose])) {
                $out[$r->purpose] = $r;
            }
        }
        return $out;
    }

    /**
     * @param array{source?:string,form_id?:int,donation_id?:int,ip?:string,ua?:string,version?:int} $ctx
     *
     * @since 1.0.0
     */
    public function record(int $donorId, string $purposeKey, bool $granted, array $ctx = []): Consent
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $version = (int) ($ctx['version'] ?? $this->findPurpose($purposeKey)['version'] ?? 1);

        $row = Consent::make();
        $row->donor_id           = $donorId;
        $row->purpose            = $purposeKey;
        $row->granted            = $granted;
        $row->purpose_version    = $version;
        $row->source             = (string) ($ctx['source'] ?? 'admin');
        $row->source_form_id     = isset($ctx['form_id']) ? (int) $ctx['form_id'] : null;
        $row->source_donation_id = isset($ctx['donation_id']) ? (int) $ctx['donation_id'] : null;
        $row->ip_hash            = ! empty($ctx['ip']) ? $this->hasher->ipHash((string) $ctx['ip']) : null;
        $row->user_agent_hash    = ! empty($ctx['ua']) ? $this->hasher->userAgentHash((string) $ctx['ua']) : null;
        $row->occurred_at        = $now;
        $row->save();

        do_action('dono.consent.recorded', $row, [
            'purpose_key' => $purposeKey,
            'version'     => $version,
        ]);
        return $row;
    }
}
