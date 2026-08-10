<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Internal note attached to a donation.
 *
 * @since 1.0.0
 */
final class DonationNote extends Model
{
    protected string $table = 'dono_donation_notes';
    protected string $version = '1.0.0';

    public int $id;
    public int $donation_id;
    public ?int $author_user_id = null;
    public string $body_encrypted;
    public string $created_at;
    public string $updated_at;
}

DonationNote::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donation_id')->unsigned();
    $t->bigInteger('author_user_id')->unsigned()->nullable();
    $t->text('body_encrypted');
    $t->datetime('created_at');
    $t->datetime('updated_at');

    $t->index(['donation_id', 'created_at']);
});
