<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Internal fundraiser notes on a donor. Body is encrypted; author is a WP
 * user id (null for system-generated notes).
 *
 * @version 1.0.0
 */
final class DonorNote extends Model
{
    protected string $table = 'dono_donor_notes';
    protected string $version = '1.0.0';

    public int $id;
    public int $donor_id;
    public ?int $author_user_id = null;
    public string $body_encrypted;
    public string $created_at;
    public string $updated_at;
}

DonorNote::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donor_id')->unsigned();
    $t->bigInteger('author_user_id')->unsigned()->nullable();
    $t->text('body_encrypted');
    $t->datetime('created_at');
    $t->datetime('updated_at');

    // Newest-first note fetch per donor.
    $t->index(['donor_id', 'created_at']);
});
