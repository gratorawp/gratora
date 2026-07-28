<?php

declare(strict_types=1);

namespace Dono\GiftAid;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * A UK donor's Gift Aid declaration.
 *
 * Append-only, like `dono_consents`: a withdrawal is a new row saying
 * `declared = false`, never an edit, because HMRC can ask what the donor agreed
 * to and when. `statement` stores the exact wording shown at the time, so a
 * later change to the org's copy cannot rewrite what was actually agreed.
 *
 * Deliberately not a consent purpose despite the identical shape. Consent is a
 * lawful basis the donor can revoke at will; a Gift Aid declaration is the
 * evidence for a tax reclaim, which HMRC requires be kept for six years after
 * the end of the accounting period it relates to. They erase differently, and
 * an admin editing the consent-purpose list must not be able to delete tax
 * records.
 *
 * @version 1.0.0
 */
final class GiftAidDeclaration extends Model
{
    protected string $table = 'dono_gift_aid_declarations';
    protected string $version = '1.0.0';

    /** Covers this donation only. */
    public const SCOPE_THIS = 'this_donation';
    /** The enduring declaration: this one, the previous four years, and future gifts. */
    public const SCOPE_ALL = 'all_donations';

    public int $id;
    public int $donor_id;
    public string $scope = self::SCOPE_ALL;
    /** False is a withdrawal. The row is kept; the claim it evidences is not. */
    public bool $declared = true;
    /** form | portal | admin | import */
    public string $source = 'form';
    public ?int $source_form_id = null;
    public ?int $source_donation_id = null;
    /** The wording the donor actually saw, not whatever the setting says today. */
    public ?string $statement = null;
    public ?string $ip_hash = null;
    public ?string $user_agent_hash = null;
    public string $occurred_at;
}

GiftAidDeclaration::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donor_id')->unsigned();
    $t->string('scope', 20)->default(GiftAidDeclaration::SCOPE_ALL);
    $t->boolean('declared')->default(1);
    $t->string('source', 20)->default('form');
    $t->bigInteger('source_form_id')->unsigned()->nullable();
    $t->bigInteger('source_donation_id')->unsigned()->nullable();
    $t->text('statement')->nullable();
    $t->string('ip_hash', 64)->nullable();
    $t->string('user_agent_hash', 64)->nullable();
    $t->datetime('occurred_at');

    // "The donor's current declaration" is the newest row for them.
    $t->index(['donor_id', 'occurred_at']);
});
