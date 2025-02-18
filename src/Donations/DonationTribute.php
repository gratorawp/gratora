<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Tribute (in-memory/in-honor) record linked to a donation.
 *
 * @version 1.0.0
 */
final class DonationTribute extends Model
{
    protected string $table = 'dono_donation_tributes';
    protected string $version = '1.0.0';

    public int $id;
    public int $donation_id;
    public ?int $donor_id = null;
    public ?int $campaign_id = null;
    public string $type;
    public string $name;
    public ?string $notify_email_encrypted = null;
    public ?string $message_encrypted = null;
    public bool $notified = false;
    public ?string $notified_at = null;
    public bool $convert_to_annual = false;
    public ?string $annual_anchor_date = null;
    public string $created_at;
}

DonationTribute::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donation_id')->unsigned();
    $t->bigInteger('donor_id')->unsigned()->nullable()->index();
    $t->bigInteger('campaign_id')->unsigned()->nullable()->index();
    $t->string('type', 64);
    $t->string('name', 200);
    $t->text('notify_email_encrypted')->nullable();
    $t->text('message_encrypted')->nullable();
    $t->boolean('notified')->default(0);
    $t->datetime('notified_at')->nullable();
    $t->boolean('convert_to_annual')->default(0);
    $t->date('annual_anchor_date')->nullable()->index();
    $t->datetime('created_at');

    $t->unique(['donation_id']);
    $t->index(['type', 'created_at']);
    $t->index(['campaign_id', 'type']);
});
