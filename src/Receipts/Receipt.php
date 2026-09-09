<?php

declare(strict_types=1);

namespace Gratora\Receipts;

defined('ABSPATH') || exit;

use Gratora\Vendor\Queryable\Model;
use Gratora\Vendor\Queryable\Schema\Table;

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
    protected string $table = 'gratora_receipts';
    protected string $version = '1.0.1';

    public int $id;
    public int $donation_id;
    public int $donor_id;
    public string $renderer_id;
    public ?string $country = null;
    public string $locale;
    public string $receipt_number;
    /** When the receipt was actually emailed. Never cleared: it is a fact. */
    public ?string $sent_to_email_at = null;
    /**
     * The single-sender lock, held while a runner is trying to send.
     *
     * Separate from sent_to_email_at because that column is also what the
     * screens and the missing-receipt sweep read. Using one column for both
     * meant every failed resend erased the record of the send that did happen.
     */
    public ?string $send_claimed_at = null;
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
    $t->datetime('send_claimed_at')->nullable();
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
