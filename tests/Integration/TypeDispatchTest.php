<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Analytics\EventRecorder;
use Gratora\Currency\FxRates;
use Gratora\Donations\AggregateSyncer;
use Gratora\Donations\Donation;
use Gratora\Donations\DonationIntent;
use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorService;
use Gratora\Forms\DefaultFormTypeHandler;
use Gratora\Forms\FormTypeHandler;
use Gratora\Forms\FormTypeRegistry;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\References\ReferenceGenerator;
use Gratora\Foundation\Time\Clock;
use Gratora\Funds\FundResolver;
use Gratora\Gateways\GatewayManager;

/**
 * Use an explicit registry to avoid the process-global container’s once-per-run registration
 * hook.
 */
final class TypeDispatchTest extends IntegrationTestCase
{
    private function service(FormTypeRegistry $types): DonationService
    {
        $c = Plugin::instance()->container;
        return new DonationService(
            $c->get(DonationRepository::class),
            $c->get(DonorService::class),
            $c->get(ReferenceGenerator::class),
            $c->get(EventRecorder::class),
            $c->get(GatewayManager::class),
            $c->get(Clock::class),
            $c->get(AggregateSyncer::class),
            $c->get(FundResolver::class),
            $c->get(FxRates::class),
            $types,
            $c->get(\Gratora\Foundation\Crypto\Crypto::class),
            $c->get(\Gratora\Gateways\TestMode::class),
        );
    }

    public function test_default_handler_is_a_no_op(): void
    {
        $registry = new FormTypeRegistry();
        $registry->register(new DefaultFormTypeHandler());

        $res = $this->service($registry)->createPending(new DonationIntent(
            email: 'plain@example.com',
            amount_cents: 1500,
            currency: 'USD',
            gateway: 'offline',
        ));
        $donation = $res['donation'];

        $this->assertSame('pending', $donation->status);
        $this->assertNull($donation->fundraiser_id);
        $this->assertNull($donation->fundraiser_team_id);
        $this->assertNotEmpty($res['status_token']);
    }

    public function test_custom_handler_augments_intent_and_runs_post_commit(): void
    {
        $handler = new class implements FormTypeHandler {
            public bool $ran = false;

            public function type(): string
            {
                return 'p2p';
            }

            public function label(): string
            {
                return 'P2P';
            }

            public function prepareIntent(DonationIntent $intent, array $body): DonationIntent
            {
                return new DonationIntent(
                    email: $intent->email,
                    amount_cents: $intent->amount_cents,
                    currency: $intent->currency,
                    gateway: $intent->gateway,
                    frequency: $intent->frequency,
                    form_id: $intent->form_id,
                    campaign_id: $intent->campaign_id,
                    fund_id: $intent->fund_id,
                    profile: $intent->profile,
                    payment_method: $intent->payment_method,
                    source_attribution: $intent->source_attribution,
                    locale: $intent->locale,
                    note_to_org: $intent->note_to_org,
                    is_anonymous: $intent->is_anonymous,
                    country: $intent->country,
                    fee_covered_cents: $intent->fee_covered_cents,
                    extra: array_merge($intent->extra, ['fundraiser_id' => 42]),
                );
            }

            public function onDonationCreated(Donation $donation, array $body): void
            {
                $this->ran = true;
            }

            public function sidecarModel(): ?string
            {
                return null;
            }
        };

        $registry = new FormTypeRegistry();
        $registry->register(new DefaultFormTypeHandler());
        $registry->register($handler);

        $res = $this->service($registry)->createPending(new DonationIntent(
            email: 'p2p@example.com',
            amount_cents: 2500,
            currency: 'USD',
            gateway: 'offline',
            extra: ['form_type' => 'p2p'],
        ));
        $donation = $res['donation'];

        $this->assertSame(42, $donation->fundraiser_id);
        $this->assertTrue($handler->ran, 'onDonationCreated must run post-commit');
    }
}
