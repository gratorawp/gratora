<?php

declare(strict_types=1);

namespace Dono\Receipts;

use Dono\Vendor\Queryable\Model;
use Dono\Vendor\Queryable\Schema\Table;

/**
 * Receipt audit record: proof a receipt was issued and emailed.
 *
 * PDFs are rendered on demand; no file is stored. Re-sends regenerate
 * deterministically from the donation and donor context.
 *
 * @since 1.0.0
 */
final class Receipt extends Model
{
    protected string $table = 'dono_receipts';
    protected string $version = '1.0.0';

    public int $id;
    public int $donation_id;
    public int $donor_id;
    public string $renderer_id;
    public ?string $country = null;
    public string $locale;
    public string $receipt_number;
    public ?string $sent_to_email_at = null;
    public bool $voided = false;
    public ?string $voided_at = null;
    public string $issued_at;
}

Receipt::schema(function (Table $t): void {
    $t->id();
    $t->bigInteger('donation_id')->unsigned()->index();
    $t->bigInteger('donor_id')->unsigned();
    $t->string('renderer_id', 64);
    $t->string('country', 2)->nullable();
    $t->string('locale', 10);
    $t->string('receipt_number', 64);
    $t->datetime('sent_to_email_at')->nullable();
    $t->boolean('voided')->default(0);
    $t->datetime('voided_at')->nullable();
    $t->datetime('issued_at');

    // Exactly one receipt per donation+renderer, DB-enforced. The issuer also
    // checks first, but this closes the concurrent-async-runner double-issue
    // window (which would otherwise also send a second receipt email).
    $t->unique(['donation_id', 'renderer_id']);
    // Per-renderer numbering: some receipt types are legally required to keep their own sequence.
    $t->unique(['renderer_id', 'receipt_number']);
    $t->index(['donor_id', 'issued_at']);
    $t->index(['renderer_id', 'issued_at']);
});
