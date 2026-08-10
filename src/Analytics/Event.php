<?php

declare(strict_types=1);

namespace Dono\Analytics;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Universal event log that every domain emits to this single table.
 *
 * @since 1.0.0
 */
final class Event extends Model
{
    protected string $table = 'dono_events';
    protected string $version = '1.0.0';

    public int $id;
    public string $type;
    public ?int $donor_id = null;
    public ?int $donation_id = null;
    public ?int $recurring_plan_id = null;
    public ?int $form_id = null;
    public ?int $campaign_id = null;
    public ?int $receipt_id = null;
    public ?string $session_hash = null;
    public ?int $user_id = null;
    public ?string $country = null;
    public ?int $amount_cents = null;
    public ?string $currency = null;
    public ?array $payload = null;
    public ?string $ip_hash = null;
    public ?string $user_agent_hash = null;
    public string $occurred_at;
}

Event::schema(function (Table $t): void {
    $t->id();
    $t->string('type', 64);
    $t->bigInteger('donor_id')->unsigned()->nullable();
    $t->bigInteger('donation_id')->unsigned()->nullable();
    $t->bigInteger('recurring_plan_id')->unsigned()->nullable();
    $t->bigInteger('form_id')->unsigned()->nullable();
    $t->bigInteger('campaign_id')->unsigned()->nullable();
    $t->bigInteger('receipt_id')->unsigned()->nullable();
    $t->string('session_hash', 64)->nullable();
    $t->bigInteger('user_id')->unsigned()->nullable();
    $t->string('country', 2)->nullable();
    $t->bigInteger('amount_cents')->unsigned()->nullable();
    $t->string('currency', 3)->nullable();
    $t->json('payload')->nullable();
    $t->string('ip_hash', 64)->nullable();
    $t->string('user_agent_hash', 64)->nullable();
    $t->datetime('occurred_at')->index();
    $t->index(['type', 'occurred_at']);
    $t->index(['donor_id', 'occurred_at']);
    $t->index(['donation_id', 'occurred_at']);
    $t->index(['form_id', 'type', 'occurred_at']);
    $t->index(['campaign_id', 'occurred_at']);
    $t->index(['session_hash', 'occurred_at']);
});
