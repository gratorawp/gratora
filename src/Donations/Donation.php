<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Donation intent or completed donation.
 *
 * Status walks: pending to paid, failed, disputed, or refunded.
 *
 * @version 1.0.0
 */
final class Donation extends Model
{
    protected string $table = 'dono_donations';
    protected string $version = '1.0.0';

    public int $id;
    public string $reference;
    /**
     * SHA-256 hash of the raw status token. Raw value never lives in the DB;
     * gates the public status endpoint so a guessed reference can't enumerate
     * donations.
     */
    public string $status_token_hash = '';
    public int $donor_id;
    public ?int $form_id = null;
    public ?int $campaign_id = null;
    public ?int $fund_id = null;
    public ?int $fundraiser_id = null;
    public ?int $fundraiser_team_id = null;
    public ?int $recurring_plan_id = null;
    public int $amount_cents;
    public int $fee_cents = 0;
    public int $fee_covered_cents = 0;
    public int $net_cents;
    public string $currency;
    public ?int $base_amount_cents = null;
    public ?string $base_currency = null;
    public ?string $fx_rate = null;
    public ?string $country = null;
    public string $frequency = 'one_time';
    public string $status = 'pending';
    public string $gateway;
    public ?string $gateway_account_id = null;
    public ?string $gateway_intent_id = null;
    public ?string $gateway_txn_id = null;
    public ?array $gateway_metadata = null;
    public ?string $payment_method = null;
    public ?string $payment_method_brand = null;
    public ?string $payment_method_last4 = null;
    public ?array $source_attribution = null;
    public ?string $locale = null;
    public ?string $note_to_org = null;
    /** Donor opted in to showing note_to_org publicly (supporter wall / recent donations). */
    public bool $note_public = false;
    /** AES-GCM ciphertext of json_encode(donor custom field values). */
    public ?string $custom_data_encrypted = null;
    /** Name as given for this donation; the donor record stays canonical. */
    public ?string $donor_first_name = null;
    public ?string $donor_last_name = null;
    public bool $is_anonymous = false;
    /** Test-mode donation: excluded from all money reporting, never charged live. */
    public bool $is_test = false;
    public ?string $failure_reason = null;
    public ?array $flags = null;
    public ?string $paid_at = null;
    public ?string $refunded_at = null;
    /** Cumulative refunded minor units; mirrors SUM(succeeded refunds), the concurrency guard for over-refund. */
    public int $refunded_cents = 0;
    public string $created_at;
    public string $updated_at;
}

Donation::schema(function (Table $t): void {
    $t->id();
    $t->string('reference', 32);
    $t->string('status_token_hash', 64)->default('');
    $t->bigInteger('donor_id')->unsigned();
    $t->bigInteger('form_id')->unsigned()->nullable();
    $t->bigInteger('campaign_id')->unsigned()->nullable();
    $t->bigInteger('fund_id')->unsigned()->nullable()->index();
    $t->bigInteger('fundraiser_id')->unsigned()->nullable()->index();
    $t->bigInteger('fundraiser_team_id')->unsigned()->nullable()->index();
    $t->bigInteger('recurring_plan_id')->unsigned()->nullable();
    $t->bigInteger('amount_cents')->unsigned();
    $t->bigInteger('fee_cents')->unsigned()->default(0);
    $t->bigInteger('fee_covered_cents')->unsigned()->default(0);
    $t->bigInteger('net_cents')->unsigned();
    $t->string('currency', 3);
    $t->bigInteger('base_amount_cents')->unsigned()->nullable();
    $t->string('base_currency', 3)->nullable();
    $t->decimal('fx_rate', 18, 8)->nullable();
    $t->string('country', 2)->nullable();
    $t->string('frequency', 20)->default('one_time');
    $t->string('status', 20)->default('pending');
    $t->string('gateway', 32);
    $t->string('gateway_account_id', 64)->nullable();
    $t->string('gateway_intent_id', 128)->nullable();
    $t->string('gateway_txn_id', 128)->nullable();
    $t->json('gateway_metadata')->nullable();
    $t->string('payment_method', 32)->nullable();
    $t->string('payment_method_brand', 32)->nullable();
    $t->string('payment_method_last4', 4)->nullable();
    $t->json('source_attribution')->nullable();
    $t->string('locale', 10)->nullable();
    $t->text('note_to_org')->nullable();
    $t->boolean('note_public')->default(0);
    $t->longText('custom_data_encrypted')->nullable();
    $t->string('donor_first_name', 100)->nullable();
    $t->string('donor_last_name', 100)->nullable();
    $t->boolean('is_anonymous')->default(0);
    $t->boolean('is_test')->default(0)->index();
    $t->string('failure_reason', 255)->nullable();
    $t->json('flags')->nullable();
    $t->datetime('paid_at')->nullable();
    $t->datetime('refunded_at')->nullable();
    $t->bigInteger('refunded_cents')->unsigned()->default(0);
    $t->datetime('created_at');
    $t->datetime('updated_at');

    $t->unique(['reference']);

    // Webhook: same (gateway, intent_id) is the same donation.
    $t->unique(['gateway', 'gateway_intent_id']);
    $t->unique(['gateway', 'gateway_txn_id']);

    $t->index(['donor_id', 'status', 'paid_at']);
    $t->index(['status', 'paid_at']);
    $t->index(['form_id', 'paid_at']);
    $t->index(['campaign_id', 'paid_at']);
    $t->index(['recurring_plan_id', 'paid_at']);
    $t->index(['country', 'paid_at']);
    // Wide composite for per-campaign / per-donor GROUP BYs without filesort.
    $t->index(['campaign_id', 'status', 'donor_id', 'paid_at']);
});
