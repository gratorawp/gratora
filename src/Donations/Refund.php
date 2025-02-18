<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * One row per refund operation on a donation.
 *
 * Donations are never deleted when refunded; this row is persisted and the
 * donation status becomes 'refunded' or 'partial_refund'. Associated receipts
 * are voided, not deleted.
 *
 * @version 1.0.0
 */
final class Refund extends Model
{
    protected string $table = 'dono_refunds';
    protected string $version = '1.0.0';

    public int $id;
    public int $donation_id;
    public int $amount_cents;
    public string $currency;
    public ?string $reason = null;
    public string $initiated_by;
    public ?int $initiated_user_id = null;
    public ?string $gateway_refund_id = null;
    public string $status = 'succeeded';
    public ?array $metadata = null;
    public string $occurred_at;
}

Refund::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donation_id')->unsigned()->index();
    $t->bigInteger('amount_cents')->unsigned();
    $t->string('currency', 3);
    $t->string('reason', 255)->nullable();
    $t->string('initiated_by', 64);
    $t->bigInteger('initiated_user_id')->unsigned()->nullable();
    $t->string('gateway_refund_id', 128)->nullable();
    $t->string('status', 20)->default('succeeded');
    $t->json('metadata')->nullable();
    $t->datetime('occurred_at')->index();

    // UNIQUE so a redelivered/concurrent gateway refund webhook cannot insert
    // the same refund twice (double-subtracting from totals). Nullable, so
    // manual/offline refunds (no gateway id) are exempt - MySQL allows many
    // NULLs in a unique index.
    $t->unique(['gateway_refund_id']);
});
