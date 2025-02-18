<?php

declare(strict_types=1);

namespace Dono\Foundation\Commands;

use RuntimeException;

/**
 * Signals a clean, expected handler failure; mapped to a command.failed audit event.
 *
 * @version 1.0.0
 */
final class CommandError extends RuntimeException
{
}
