<?php

declare(strict_types=1);

namespace Gratora\Foundation\References;

use RuntimeException;

/**
 * Thrown when a numbering setting holds characters a reference cannot carry.
 *
 * @since 1.0.0
 */
final class InvalidReferenceToken extends RuntimeException
{
    /** @since 1.0.0 */
    public function __construct(public readonly string $field, public readonly string $value)
    {
        parent::__construct(sprintf(
            /* translators: 1: the field name as the settings screen labels it, 2: the value entered. */
            __('%1$s can only use letters, numbers, hyphens and underscores, and cannot be empty. "%2$s" would be stripped down before a single reference was minted, so the numbering an accountant is given would not be the one on screen.', 'gratora-donation-platform'),
            $field,
            $value
        ));
    }
}
