<?php

declare(strict_types=1);

namespace FundKit\Donors;

use FundKit\Foundation\Identity\IdentityHasher;
use FundKit\Foundation\Time\Clock;
use FundKit\Settings\SettingsService;

/** @since 1.0.0 */
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
        // campaign updates) apply even when fundkit_consents was never saved. The
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
    private static function text(mixed $value, int $max): ?string
    {
        $text = trim((string) ($value ?? ''));

        return $text === '' ? null : mb_substr($text, 0, $max);
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
     * label and description override the registry, for a consent whose wording
     * lives on the form rather than in it (the donation form's terms box).
     *
     * @param array{source?:string,form_id?:int,donation_id?:int,ip?:string,ua?:string,version?:int,label?:string,description?:string} $ctx
     *
     * @since 1.0.0
     */
    public function record(int $donorId, string $purposeKey, bool $granted, array $ctx = []): Consent
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $purpose = $this->findPurpose($purposeKey);
        $version = (int) ($ctx['version'] ?? $purpose['version'] ?? 1);

        $row = Consent::make();
        $row->donor_id           = $donorId;
        $row->purpose            = $purposeKey;
        $row->granted            = $granted;
        $row->purpose_version    = $version;
        // Snapshotted, because the registry entry can be edited afterwards and
        // then the row would appear to be consent to whatever it says today.
        $row->purpose_label       = self::text($ctx['label'] ?? $purpose['label'] ?? null, 191);
        $row->purpose_description = self::text($ctx['description'] ?? $purpose['description'] ?? null, 5000);
        $row->source             = (string) ($ctx['source'] ?? 'admin');
        $row->source_form_id     = isset($ctx['form_id']) ? (int) $ctx['form_id'] : null;
        $row->source_donation_id = isset($ctx['donation_id']) ? (int) $ctx['donation_id'] : null;
        $row->ip_hash            = ! empty($ctx['ip']) ? $this->hasher->ipHash((string) $ctx['ip']) : null;
        $row->user_agent_hash    = ! empty($ctx['ua']) ? $this->hasher->userAgentHash((string) $ctx['ua']) : null;
        $row->occurred_at        = $now;
        $row->save();

        do_action('fundkit.consent.recorded', $row, [
            'purpose_key' => $purposeKey,
            'version'     => $version,
        ]);
        return $row;
    }
}
