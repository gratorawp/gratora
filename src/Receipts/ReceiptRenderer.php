<?php

declare(strict_types=1);

namespace Dono\Receipts;

/**
 * Renders a donation receipt to a PDF byte string.
 *
 * Multiple renderers may apply to the same donation; ReceiptIssuer runs
 * each whose appliesTo() returns true.
 *
 * @version 1.0.0
 */
interface ReceiptRenderer
{
    /** Stable identifier, stored on the Receipt row for traceability. */
    public function id(): string;

    /** Human-readable label for admin UI. */
    public function label(): string;

    /**
     * Scope passed to ReferenceGenerator for receipt numbering.
     *
     * Country-specific renderers use their own scope for an independent
     * gap-free sequence, required for tax compliance in some jurisdictions.
     */
    public function referenceScope(): string;

    /** Does this renderer apply to the given context? */
    public function appliesTo(ReceiptContext $ctx): bool;

    /** Render to raw PDF bytes. */
    public function render(ReceiptContext $ctx): string;
}
