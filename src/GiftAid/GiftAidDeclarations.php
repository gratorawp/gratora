<?php

declare(strict_types=1);

namespace Dono\GiftAid;

use Dono\Foundation\Identity\IdentityHasher;
use Dono\Foundation\Time\Clock;

/**
 * Recording and reading Gift Aid declarations.
 *
 * Append-only: `record()` always writes a new row, so the history of what a
 * donor agreed to and when survives every later change. `current()` is the
 * newest row, which is what "does this donor have a declaration" means.
 *
 * @version 1.0.0
 */
final class GiftAidDeclarations
{
    public function __construct(
        private Clock $clock,
        private IdentityHasher $hasher,
    ) {
    }

    /**
     * @param array{
     *     scope?:string, source?:string, form_id?:int, donation_id?:int,
     *     statement?:string, ip?:string, ua?:string
     * } $ctx
     */
    public function record(int $donorId, bool $declared, array $ctx = []): GiftAidDeclaration
    {
        $scope = (string) ($ctx['scope'] ?? GiftAidDeclaration::SCOPE_ALL);
        if (! in_array($scope, [GiftAidDeclaration::SCOPE_THIS, GiftAidDeclaration::SCOPE_ALL], true)) {
            $scope = GiftAidDeclaration::SCOPE_ALL;
        }

        $row = GiftAidDeclaration::make();
        $row->donor_id           = $donorId;
        $row->scope              = $scope;
        $row->declared           = $declared;
        $row->source             = (string) ($ctx['source'] ?? 'admin');
        $row->source_form_id     = isset($ctx['form_id']) ? (int) $ctx['form_id'] : null;
        $row->source_donation_id = isset($ctx['donation_id']) ? (int) $ctx['donation_id'] : null;
        // Stored as shown, so a later edit to the org's wording cannot rewrite
        // what the donor actually agreed to.
        $row->statement          = isset($ctx['statement']) ? (string) $ctx['statement'] : null;
        $row->ip_hash            = ! empty($ctx['ip']) ? $this->hasher->ipHash((string) $ctx['ip']) : null;
        $row->user_agent_hash    = ! empty($ctx['ua']) ? $this->hasher->userAgentHash((string) $ctx['ua']) : null;
        $row->occurred_at        = $this->clock->now()->format('Y-m-d H:i:s');
        $row->save();

        do_action('dono.gift_aid.declaration_recorded', $row);

        return $row;
    }

    /** The donor's newest declaration, whether it grants or withdraws. */
    public function current(int $donorId): ?GiftAidDeclaration
    {
        return GiftAidDeclaration::query()
            ->where('donor_id', $donorId)
            ->orderBy('occurred_at', 'DESC')
            ->orderBy('id', 'DESC')
            ->limit(1)
            ->get();
    }

    /**
     * Whether the donor has a live declaration that covers gifts beyond the one
     * it was made on. A `this_donation` declaration covers only its own gift,
     * which the donation's own flag records.
     */
    public function coversFutureGifts(int $donorId): bool
    {
        $current = $this->current($donorId);

        return $current instanceof GiftAidDeclaration
            && $current->declared
            && $current->scope === GiftAidDeclaration::SCOPE_ALL;
    }
}
