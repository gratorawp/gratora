<?php

declare(strict_types=1);

namespace Dono\Donors;

use RuntimeException;

/**
 * Thrown when the requested email already belongs to a different donor row.
 *
 * @version 1.0.0
 */
final class EmailAlreadyAssignedException extends RuntimeException
{
    public function __construct(public readonly int $existingDonorId)
    {
        parent::__construct('Email already assigned to donor #' . $existingDonorId);
    }
}
