<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * An address somebody typed into the portal that nobody has proven yet.
 *
 * An unproven address is not a donor and must not be counted, listed, exported
 * or mailed as one; redeeming the emailed link is what creates the donor. Same
 * crypto split as Donor: address encrypted, peppered hash as the lookup key.
 *
 * @since 1.0.0
 */
final class PendingSignup extends Model
{
    protected string $table = 'dono_pending_signups';
    protected string $version = '1.0.0';

    public int $id;
    public string $email_hash;
    public string $email_encrypted;
    public ?string $first_name = null;
    public ?string $last_name = null;
    public string $expires_at;
    public string $created_at;
}

PendingSignup::schema(function (Table $t): void {
    $t->id();
    // Unique, so a second signup for the same address updates the row it
    // already has rather than leaving two live claims on one mailbox.
    $t->string('email_hash', 64);
    $t->text('email_encrypted');
    $t->string('first_name', 100)->nullable();
    $t->string('last_name', 100)->nullable();
    $t->datetime('expires_at')->index();
    $t->datetime('created_at');

    $t->unique(['email_hash']);
});
