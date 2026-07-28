<?php

declare(strict_types=1);

namespace Dono\Gateways;

/**
 * Optional capability: a gateway that needs the donor's browser to finish the
 * payment, and therefore needs data on the page.
 *
 * Both halves are explicit whitelists the gateway owns. Gateway metadata is
 * never echoed to the browser wholesale, and neither method may return a
 * secret: everything here ends up in page source a donor can read.
 *
 * A gateway that redirects, or settles entirely server-side, does not
 * implement this.
 *
 * @version 1.0.0
 */
interface BrowserAware
{
    /**
     * Public, non-secret configuration the form needs before a donation
     * exists: a publishable key, an SDK locale, the merchant id. Published
     * under the gateway's own id in the form config. Return [] for none.
     *
     * @return array<string,mixed>
     */
    public function publicConfig(bool $test, string $currency): array;

    /**
     * The subset of a createIntent result the browser may see, published under
     * the gateway's own id in the create-donation response. Return null when
     * this particular intent needs nothing client-side.
     *
     * @return array<string,mixed>|null
     */
    public function browserPayload(GatewayIntentResult $result): ?array;
}
