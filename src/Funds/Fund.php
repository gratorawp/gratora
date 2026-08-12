<?php

declare(strict_types=1);

namespace Dono\Funds;

defined('ABSPATH') || exit;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Fund / designation model.
 *
 * @since 1.0.0
 */
final class Fund extends Model
{
    protected string $table = 'dono_funds';
    protected string $version = '1.0.0';

    public int $id;
    public string $code;
    public string $name;
    public ?string $description = null;
    public bool $is_restricted = false;
    public bool $is_default = false;
    public bool $is_active = true;
    public int $sort_order = 0;
    public ?int $parent_fund_id = null;
    public ?int $goal_cents = null;
    public int $raised_cents = 0;
    public int $donations_count = 0;
    public int $donors_count = 0;
    public ?string $last_paid_at = null;
    public ?string $starts_at = null;
    public ?string $ends_at = null;
    public ?string $accounting_code = null;
    public string $created_at;
    public string $updated_at;
}

Fund::schema(function (Table $t): void {
    $t->id();
    $t->string('code', 64);
    $t->string('name', 150);
    $t->text('description')->nullable();
    $t->boolean('is_restricted')->default(0);
    $t->boolean('is_default')->default(0);
    $t->boolean('is_active')->default(1);
    $t->integer('sort_order')->unsigned()->default(0);
    $t->bigInteger('parent_fund_id')->unsigned()->nullable()->index();
    $t->bigInteger('goal_cents')->unsigned()->nullable();
    $t->bigInteger('raised_cents')->unsigned()->default(0);
    $t->integer('donations_count')->unsigned()->default(0);
    $t->integer('donors_count')->unsigned()->default(0);
    $t->datetime('last_paid_at')->nullable();
    $t->datetime('starts_at')->nullable();
    $t->datetime('ends_at')->nullable();
    $t->string('accounting_code', 64)->nullable();
    $t->datetime('created_at');
    $t->datetime('updated_at');

    $t->unique(['code']);
    $t->index(['is_active', 'sort_order']);
});
