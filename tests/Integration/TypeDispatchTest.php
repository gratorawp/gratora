<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Analytics\EventRecorder;
use Dono\Currency\FxRates;
use Dono\Donations\AggregateSyncer;
use Dono\Donations\Donation;
use Dono\Donations\DonationIntent;
use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Donations\DonationTributeRepository;
use Dono\Donors\DonorService;
use Dono\Forms\DefaultFormTypeHandler;
use Dono\Forms\FormTypeHandler;
use Dono\Forms\FormTypeRegistry;
use Dono\Foundation\Plugin;
use Dono\Foundation\References\ReferenceGenerator;
use Dono\Foundation\Time\Clock;
use Dono\Funds\FundResolver;
use Dono\Gateways\GatewayManager;

/**
 * The byte-identical Free path is also regression-covered by the existing
 * DonationFlowTest; this isolates the type-dispatch seam itself. The service
 * is built with an explicit registry to avoid the process-global container
 * singleton (its register hook fires once per run).
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
            $c->get(\Dono\Foundation\Crypto\Crypto::class),
            $c->get(\Dono\Gateways\TestMode::class),
            $c->get(DonationTributeRepository::class),
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
                    tribute: $intent->tribute,
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
