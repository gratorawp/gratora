<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Append-only consent record. Revocation inserts a new row with granted=false;
 * originals are never mutated (GDPR audit trail). Current consent = newest row
 * per (donor, purpose) by occurred_at.
 *
 * Erasure is the one exception: it nulls ip_hash and user_agent_hash on the
 * erased donor's rows. The fact, the purpose and the timestamp are never
 * touched, because those are the evidence; the hashes only re-identify.
 *
 * @version 1.0.0
 */
final class Consent extends Model
{
    protected string $table = 'dono_consents';
    protected string $version = '1.0.0';

    public int $id;
    public int $donor_id;
    public string $purpose;
    public bool $granted;
    public int $purpose_version = 1;
    public string $source;
    public ?int $source_form_id = null;
    public ?int $source_donation_id = null;
    public ?string $ip_hash = null;
    public ?string $user_agent_hash = null;
    public string $occurred_at;
}

Consent::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donor_id')->unsigned();
    $t->string('purpose', 64);
    $t->boolean('granted');
    $t->integer('purpose_version')->unsigned()->default(1);
    $t->string('source', 64);
    $t->bigInteger('source_form_id')->unsigned()->nullable();
    $t->bigInteger('source_donation_id')->unsigned()->nullable();
    $t->string('ip_hash', 64)->nullable();
    $t->string('user_agent_hash', 64)->nullable();
    $t->datetime('occurred_at');

    $t->index(['donor_id', 'purpose', 'occurred_at']); // current consent lookup
    $t->index(['purpose', 'occurred_at']);              // audit queries by purpose
});
