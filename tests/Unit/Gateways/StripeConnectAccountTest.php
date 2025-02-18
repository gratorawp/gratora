<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Gateways\Stripe\StripeConnectAccount;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class StripeConnectAccountTest extends TestCase
{
    private StripeConnectAccount $acct;

    protected function setUp(): void
    {
        // Mode resolution never touches Crypto (which loads a key from the DB),
        // so skip the constructor for a pure unit.
        $this->acct = (new ReflectionClass(StripeConnectAccount::class))
            ->newInstanceWithoutConstructor();
    }

    public function test_defaults_to_test_when_no_caller_set_the_mode(): void
    {
        // Fail safe: a live charge must never fire because a caller forgot to
        // thread the donation's is_test.
        $this->assertTrue($this->acct->isTestMode());
    }

    public function test_use_test_mode_selects_the_active_mode(): void
    {
        $this->acct->useTestMode(false);
        $this->assertFalse($this->acct->isTestMode());

        $this->acct->useTestMode(true);
        $this->assertTrue($this->acct->isTestMode());
    }
}
