<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

/**
 * What a form-carrying page block draws, kept apart so each piece is escaped
 * for what it is: the block's own markup before and after, and between them
 * the form by its slug or the editor's preview document of it.
 *
 * @since 1.1.0
 */
final class EmbeddedForm
{
    /** @since 1.1.0 */
    public function __construct(
        public readonly string $before,
        public readonly string $formSlug = '',
        public readonly string $previewDocument = '',
        public readonly string $previewTitle = '',
        public readonly string $after = '',
    ) {
    }
}
