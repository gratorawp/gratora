<?php

declare(strict_types=1);

namespace Dono\Receipts;

/**
 * Renders a donation receipt to a PDF byte string.
 *
 * Multiple renderers may apply to the same donation; ReceiptIssuer runs
 * each whose appliesTo() returns true.
 *
 * @since 1.0.0
 */
interface ReceiptRenderer
{
    /**
     * Stable identifier, stored on the Receipt row for traceability.
     *
     * @since 1.0.0
     */
    public function id(): string;

    /** @since 1.0.0 */
    public function label(): string;

    /**
     * Scope passed to ReferenceGenerator for receipt numbering.
     *
     * Country-specific renderers use their own scope for an independent
     * gap-free sequence, required for tax compliance in some jurisdictions.
     *
     * @since 1.0.0
     */
    public function referenceScope(): string;

    /** @since 1.0.0 */
    public function appliesTo(ReceiptContext $ctx): bool;

    /**
     * Render to raw PDF bytes.
     *
     * @since 1.0.0
     */
    public function render(ReceiptContext $ctx): string;
}
