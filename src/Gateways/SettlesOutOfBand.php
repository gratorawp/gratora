<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Optional capability: a gateway that takes the money out of band, as a bank
 * transfer, a cheque or cash.
 *
 * Such a gateway has no checkout to abandon. The donor leaves with instructions
 * and a reference to quote, so its pending row is a transfer the org is still
 * waiting for, and the queue that transfer has to be matched against. A later
 * submission must not adopt that row as a retry parent: the adoption stamps
 * retried_by, and a pending row carrying that drops out of the admin list, the
 * CSV export, the KPI counts and the donor's own donation list.
 *
 * Declared by the gateway rather than resolved through a filter, so a site
 * cannot switch it off and reopen the hole. See AntiSpamGuard::claimRetry.
 *
 * @since 1.0.0
 */
interface SettlesOutOfBand
{
}
