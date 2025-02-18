<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Per-form donation aggregates.
 *
 * AggregateSyncer is the only writer.
 *
 * @version 1.0.0
 */
final class FormDonationStats extends Model
{
    protected string $table = 'dono_form_donation_stats';
    protected string $version = '1.0.0';
    protected string $primaryKey = 'form_id';

    public int $form_id;
    public int $raised_cents = 0;
    public int $donors_count = 0;
    public int $donations_count = 0;
    public ?string $first_paid_at = null;
    public ?string $last_paid_at = null;
    public string $updated_at;
}

FormDonationStats::schema(function (Table $t): void {
    $t->bigInteger('form_id')->unsigned()->primary();
    $t->bigInteger('raised_cents')->unsigned()->default(0);
    $t->integer('donors_count')->unsigned()->default(0);
    $t->integer('donations_count')->unsigned()->default(0);
    $t->datetime('first_paid_at')->nullable();
    $t->datetime('last_paid_at')->nullable();
    $t->datetime('updated_at');
});
