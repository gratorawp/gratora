<?php

declare(strict_types=1);

namespace Dono\Donors;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * One-time token for donor self-service.
 *
 * @version 1.0.0
 */
final class MagicLinkToken extends Model
{
    protected string $table = 'dono_magic_link_tokens';
    protected string $version = '1.0.0';

    public int $id;
    public int $donor_id;
    public string $token_hash;
    public string $purpose;
    public ?int $target_id = null;
    public ?string $used_at = null;
    public string $expires_at;
    public string $created_at;
}

MagicLinkToken::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donor_id')->unsigned();
    $t->string('token_hash', 64);
    $t->string('purpose', 64);
    $t->bigInteger('target_id')->unsigned()->nullable();
    $t->datetime('used_at')->nullable();
    $t->datetime('expires_at')->index();
    $t->datetime('created_at');

    $t->unique(['token_hash']);
    $t->index(['donor_id', 'purpose']);
});
