<?php

declare(strict_types=1);

namespace Dono\Recurring;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Mirror of a gateway-side subscription. One plan to many donation renewals.
 *
 * @version 1.0.0
 */
final class RecurringPlan extends Model
{
    protected string $table = 'dono_recurring_plans';
    protected string $version = '1.0.1';

    public int $id;
    public int $donor_id;
    public ?int $form_id = null;
    public ?int $campaign_id = null;
    public ?int $fund_id = null;
    public ?int $fundraiser_id = null;
    public ?int $fundraiser_team_id = null;
    public string $gateway;
    public string $gateway_subscription_id;
    public ?string $gateway_customer_id = null;
    public int $amount_cents;
    public string $currency;
    public string $interval_unit = 'month';
    public int $interval_count = 1;
    public string $status = 'active';
    public string $started_at;
    public ?string $current_period_start = null;
    public ?string $current_period_end = null;
    public ?string $next_payment_at = null;
    public ?string $last_payment_at = null;
    public ?string $cancelled_at = null;
    public ?string $cancellation_reason = null;
    public int $payments_count = 0;
    public int $total_paid_cents = 0;
    public int $failed_renewals_count = 0;
    public bool $is_test = false;
    public string $created_at;
    public string $updated_at;
}

RecurringPlan::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donor_id')->unsigned();
    $t->bigInteger('form_id')->unsigned()->nullable();
    $t->bigInteger('campaign_id')->unsigned()->nullable();
    $t->bigInteger('fund_id')->unsigned()->nullable();
    $t->bigInteger('fundraiser_id')->unsigned()->nullable();
    $t->bigInteger('fundraiser_team_id')->unsigned()->nullable();
    $t->string('gateway', 32);
    $t->string('gateway_subscription_id', 128);
    $t->string('gateway_customer_id', 128)->nullable();
    $t->bigInteger('amount_cents')->unsigned();
    $t->string('currency', 3);
    $t->string('interval_unit', 10)->default('month');
    $t->tinyInteger('interval_count')->unsigned()->default(1);
    $t->string('status', 20)->default('active');
    $t->datetime('started_at');
    $t->datetime('current_period_start')->nullable();
    $t->datetime('current_period_end')->nullable();
    $t->datetime('next_payment_at')->nullable();
    $t->datetime('last_payment_at')->nullable();
    $t->datetime('cancelled_at')->nullable();
    $t->string('cancellation_reason', 255)->nullable();
    $t->integer('payments_count')->unsigned()->default(0);
    $t->bigInteger('total_paid_cents')->unsigned()->default(0);
    $t->integer('failed_renewals_count')->unsigned()->default(0);
    // Mode is fixed at creation from the originating donation; renewal and
    // cancel resolve the Stripe account from this, never the mutable setting.
    $t->boolean('is_test')->default(false);
    $t->datetime('created_at');
    $t->datetime('updated_at');

    // Webhook dedup for subscription events.
    $t->unique(['gateway', 'gateway_subscription_id']);

    // Donor's active plans list + dunning scheduler.
    $t->index(['donor_id', 'status']);
    $t->index(['status', 'next_payment_at']);
});
