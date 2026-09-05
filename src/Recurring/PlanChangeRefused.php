<?php

declare(strict_types=1);

namespace FundKit\Recurring;

defined('ABSPATH') || exit;

use RuntimeException;

/**
 * The plan itself will not take this change, in words a donor can read.
 *
 * Distinct from a gateway failure. Everything a payment API throws is also a
 * RuntimeException, carrying its own English error text and sometimes a
 * subscription id, so a screen that shows the message of one must not show the
 * message of the other.
 *
 * @since 1.0.0
 */
final class PlanChangeRefused extends RuntimeException
{
}
