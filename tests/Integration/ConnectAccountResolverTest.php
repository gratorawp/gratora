<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\Stripe\ConnectAccountResolver;
use Dono\Gateways\Stripe\StripeConnectAccount;
use ReflectionClass;

/**
 * Filters need real WP, so this is an integration test (the unit bootstrap
 * stubs add_filter to a no-op). The unfiltered charge path setting
 * gateway_account_id stays regression-covered by the Stripe/webhook suites.
 */
final class ConnectAccountResolverTest extends IntegrationTestCase
{
    private function account(): StripeConnectAccount
    {
        return Plugin::instance()->container->get(StripeConnectAccount::class);
    }

    public function test_default_delegates_to_single_account(): void
    {
        $svc = $this->account();
        $this->assertSame($svc->accountId(), $svc->accountIdFor(1, 2));
    }

    public function test_resolver_swap_filter_settles_elsewhere(): void
    {
        add_filter('dono.stripe.account_resolver', static fn () => new class implements ConnectAccountResolver {
            public function resolve(?int $campaignId, ?int $formId): ?string
            {
                return 'acct_FROM_RESOLVER';
            }
        });

        $this->assertSame('acct_FROM_RESOLVER', $this->account()->accountIdFor(7, null));

        remove_all_filters('dono.stripe.account_resolver');
    }

    public function test_value_filter_overrides_without_a_resolver(): void
    {
        add_filter('dono.stripe.account_id_for', static fn () => 'acct_OVERRIDE');

        $this->assertSame('acct_OVERRIDE', $this->account()->accountIdFor());

        remove_all_filters('dono.stripe.account_id_for');
    }

    public function test_f1_is_a_resolver_seam_not_new_storage(): void
    {
        $rc = new ReflectionClass(StripeConnectAccount::class);
        $this->assertTrue($rc->implementsInterface(ConnectAccountResolver::class));
        $this->assertTrue($rc->getMethod('resolve')->isPublic());
        // The single-account body moved into a private default, not a new
        // public multi-account API.
        $this->assertTrue($rc->getMethod('resolveDefault')->isPrivate());
    }
}
