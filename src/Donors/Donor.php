<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Donor record. PII columns are AES-256-GCM encrypted; email_hash is the
 * indexed lookup key.
 *
 * @version 1.0.0
 */
final class Donor extends Model
{
    protected string $table = 'dono_donors';
    protected string $version = '1.0.0';

    public int $id;
    public string $email_hash;
    public string $email_encrypted;
    public ?string $first_name = null;
    public ?string $last_name = null;
    public ?string $address_encrypted = null;
    public ?string $phone_encrypted = null;
    public ?string $company = null;
    public ?string $country = null;
    public ?string $locale = null;
    public ?string $tax_id_encrypted = null;
    public string $donor_type = 'individual';
    public ?int $household_id = null;
    public int $total_donated_cents = 0;
    public int $donations_count = 0;
    public ?string $first_donation_at = null;
    public ?string $last_donation_at = null;
    public ?string $redacted_at = null;
    /** When the last re-identification handle was severed. See DonorPurge. */
    public ?string $purged_at = null;
    public ?string $notes_encrypted = null;
    public ?array $flags = null;
    public string $created_at;
    public string $updated_at;
}

Donor::schema(function (Table $t): void {
    $t->id();
    $t->string('email_hash', 64);
    $t->text('email_encrypted');
    $t->string('first_name', 100)->nullable();
    $t->string('last_name', 100)->nullable();
    $t->text('address_encrypted')->nullable();
    $t->text('phone_encrypted')->nullable();
    $t->string('company', 150)->nullable();
    $t->string('country', 2)->nullable()->index();
    $t->string('locale', 10)->nullable();
    $t->text('tax_id_encrypted')->nullable();
    $t->string('donor_type', 20)->default('individual')->index();
    $t->bigInteger('household_id')->unsigned()->nullable()->index();
    $t->bigInteger('total_donated_cents')->unsigned()->default(0);
    $t->integer('donations_count')->unsigned()->default(0);
    $t->datetime('first_donation_at')->nullable();
    $t->datetime('last_donation_at')->nullable()->index();
    $t->datetime('redacted_at')->nullable()->index();
    $t->datetime('purged_at')->nullable();
    $t->text('notes_encrypted')->nullable();
    $t->json('flags')->nullable();
    $t->datetime('created_at');
    $t->datetime('updated_at');

    $t->unique(['email_hash']);
    // DonorPurge's daily sweep for redacted donors past their reunite window.
    $t->index(['purged_at', 'redacted_at']);
});
