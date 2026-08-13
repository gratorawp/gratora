<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use DateTimeImmutable;
use Dono\Donations\Donation;
use Dono\Foundation\Time\FrozenClock;
use Dono\Gateways\Sandbox\SandboxGateway;
use Dono\Recurring\RecurringPlanRepository;
use PHPUnit\Framework\TestCase;

final class SandboxGatewayTest extends TestCase
{
    private SandboxGateway $gateway;

    protected function setUp(): void
    {
        $this->gateway = new SandboxGateway(
            new FrozenClock(new DateTimeImmutable('2026-05-13 09:00:00')),
            new RecurringPlanRepository()
        );
    }

    public function test_identity_and_support_metadata(): void
    {
        $this->assertSame('sandbox', $this->gateway->id());
        $this->assertSame(['one_time', 'recurring'], $this->gateway->frequencies());
        $this->assertSame(['*'], $this->gateway->countries());
        $this->assertSame(['*'], $this->gateway->currencies());
        $this->assertNotSame('', $this->gateway->label());
    }

    public function test_confirm_succeeds_so_a_test_donation_completes(): void
    {
        $d = Donation::make();
        $d->reference = 'DONO-2026-00777';

        $intent = $this->gateway->createIntent($d);
        $this->assertSame('sandbox_DONO-2026-00777', $intent->intent_id);

        $result = $this->gateway->confirm($d);
        $this->assertTrue($result->success);
        $this->assertSame('sandbox_txn_DONO-2026-00777', $result->gateway_txn_id);
        $this->assertSame('test', $result->payment_method);
    }
}
