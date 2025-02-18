<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Gateways;

use Dono\Donations\Donation;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\WebhookOutcome;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use WP_REST_Request;

/**
 * Helper test double - a gateway whose support set we control inline.
 */
final class FakeGateway implements PaymentGateway
{
    public function __construct(
        private string $id,
        private array $frequencies,
        private array $methods,
        private array $countries,
        private array $currencies,
        private bool $chargeable = true,
    ) {}

    public function id(): string { return $this->id; }
    public function label(): string { return $this->id; }
    public function description(): string { return ''; }
    public function frequencies(): array { return $this->frequencies; }
    public function paymentMethods(): array { return $this->methods; }
    public function countries(): array { return $this->countries; }
    public function currencies(): array { return $this->currencies; }
    public function canCharge(): bool { return $this->chargeable; }
    public function createIntent(Donation $d): GatewayIntentResult { return new GatewayIntentResult('x'); }
    public function confirm(Donation $d, array $p = []): GatewayConfirmResult { return new GatewayConfirmResult(true); }
    public function handleWebhook(WP_REST_Request $r): WebhookOutcome { return new WebhookOutcome(true); }
    public function refund(Donation $d, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(success: true, gateway_refund_id: 'fake_' . $d->id, amount_cents: $amountCents);
    }
}

final class GatewayManagerTest extends TestCase
{
    public function test_register_and_get_round_trips_by_id(): void
    {
        $gm = new GatewayManager();
        $g = new FakeGateway('alpha', ['one_time'], ['card'], ['*'], ['*']);

        $gm->register($g);

        $this->assertSame($g, $gm->get('alpha'));
        $this->assertNull($gm->get('unknown'));
    }

    public function test_register_rejects_duplicate_ids(): void
    {
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('alpha', ['one_time'], ['card'], ['*'], ['*']));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("Gateway 'alpha' is already registered.");
        $gm->register(new FakeGateway('alpha', ['one_time'], ['card'], ['*'], ['*']));
    }

    public function test_require_throws_when_missing(): void
    {
        $gm = new GatewayManager();
        $this->expectException(RuntimeException::class);
        $gm->require('nope');
    }

    public function test_available_for_filters_by_currency(): void
    {
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('eur_only',     ['one_time'], ['card'], ['*'], ['EUR']));
        $gm->register(new FakeGateway('usd_only',     ['one_time'], ['card'], ['*'], ['USD']));
        $gm->register(new FakeGateway('any_currency', ['one_time'], ['card'], ['*'], ['*']));

        $available = $gm->availableFor('DE', 'EUR', 'one_time');
        $this->assertArrayHasKey('eur_only', $available);
        $this->assertArrayHasKey('any_currency', $available);
        $this->assertArrayNotHasKey('usd_only', $available);
    }

    public function test_available_for_excludes_gateways_that_cannot_charge(): void
    {
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('ready',      ['one_time'], ['card'], ['*'], ['*'], true));
        $gm->register(new FakeGateway('onboarding', ['one_time'], ['card'], ['*'], ['*'], false));

        $available = $gm->availableFor(null, 'USD', 'one_time');
        $this->assertArrayHasKey('ready', $available);
        $this->assertArrayNotHasKey('onboarding', $available, 'a gateway that cannot charge is not offered');

        // optionsFor flows through availableFor, so it is excluded there too.
        $this->assertNotContains('onboarding', $gm->optionsFor([], null, 'USD'));
    }

    public function test_available_for_filters_by_country(): void
    {
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('de_only',  ['one_time'], ['card'], ['DE'],     ['*']));
        $gm->register(new FakeGateway('us_only',  ['one_time'], ['card'], ['US'],     ['*']));
        $gm->register(new FakeGateway('any_country', ['one_time'], ['card'], ['*'],   ['*']));

        $available = $gm->availableFor('DE', 'EUR', 'one_time');
        $this->assertArrayHasKey('de_only', $available);
        $this->assertArrayHasKey('any_country', $available);
        $this->assertArrayNotHasKey('us_only', $available);
    }

    public function test_specific_recurring_frequencies_match_recurring_bucket(): void
    {
        // Gateway's frequencies() should be ['one_time'] or ['recurring'] -
        // 'monthly'/'quarterly'/'yearly' in DonationIntent normalize to 'recurring'.
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('one_only',     ['one_time'],   ['card'], ['*'], ['*']));
        $gm->register(new FakeGateway('rec_only',     ['recurring'],  ['card'], ['*'], ['*']));
        $gm->register(new FakeGateway('both',         ['one_time','recurring'], ['card'], ['*'], ['*']));

        foreach (['monthly', 'quarterly', 'yearly', 'weekly', 'biweekly', 'recurring'] as $freq) {
            $a = $gm->availableFor('DE', 'EUR', $freq);
            $this->assertArrayHasKey('rec_only', $a, "$freq should match rec_only");
            $this->assertArrayHasKey('both', $a,     "$freq should match both");
            $this->assertArrayNotHasKey('one_only', $a, "$freq should not match one_only");
        }

        $a = $gm->availableFor('DE', 'EUR', 'one_time');
        $this->assertArrayHasKey('one_only', $a);
        $this->assertArrayHasKey('both', $a);
        $this->assertArrayNotHasKey('rec_only', $a);
    }

    public function test_no_country_supplied_skips_country_check(): void
    {
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('de_only', ['one_time'], ['card'], ['DE'], ['*']));

        $a = $gm->availableFor(null, 'EUR', 'one_time');
        $this->assertArrayHasKey('de_only', $a, 'unknown donor country falls through');
    }

    public function test_currency_match_is_case_insensitive(): void
    {
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('eur', ['one_time'], ['card'], ['*'], ['eur']));   // lowercase in gateway

        $a = $gm->availableFor('DE', 'EUR', 'one_time');
        $this->assertArrayHasKey('eur', $a);
    }

    public function test_options_for_empty_allowed_returns_all_enabled_in_registration_order(): void
    {
        $GLOBALS['_dono_test_options'] = [];
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('offline', ['one_time', 'recurring'], ['card'], ['*'], ['*']));
        $gm->register(new FakeGateway('stripe',  ['one_time', 'recurring'], ['card'], ['*'], ['*']));

        $this->assertSame(['offline', 'stripe'], $gm->optionsFor([], 'DE', 'EUR', 'one_time'));
    }

    public function test_options_for_excludes_explicitly_disabled_gateway(): void
    {
        $GLOBALS['_dono_test_options'] = ['dono_gateway_config' => ['stripe' => ['enabled' => false]]];
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('offline', ['one_time'], ['card'], ['*'], ['*']));
        $gm->register(new FakeGateway('stripe',  ['one_time'], ['card'], ['*'], ['*']));

        $this->assertSame(['offline'], $gm->optionsFor([], 'DE', 'EUR', 'one_time'));
    }

    public function test_options_for_intersects_and_orders_by_allowed_list(): void
    {
        $GLOBALS['_dono_test_options'] = [];
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('offline', ['one_time'], ['card'], ['*'], ['*']));
        $gm->register(new FakeGateway('stripe',  ['one_time'], ['card'], ['*'], ['*']));

        // Declared order wins; an allowed id that is not available is dropped.
        $this->assertSame(['stripe', 'offline'], $gm->optionsFor(['stripe', 'offline'], 'DE', 'EUR', 'one_time'));
        $this->assertSame(['offline'], $gm->optionsFor(['offline', 'paypal'], 'DE', 'EUR', 'one_time'));
    }

    public function test_options_for_drops_context_unavailable_gateway(): void
    {
        $GLOBALS['_dono_test_options'] = [];
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('offline',  ['one_time'], ['card'], ['*'],  ['*']));
        $gm->register(new FakeGateway('usd_only', ['one_time'], ['card'], ['*'],  ['USD']));

        $this->assertSame(['offline'], $gm->optionsFor(['usd_only', 'offline'], 'DE', 'EUR', 'one_time'));
    }

    public function test_options_meta_for_is_not_context_filtered_and_carries_metadata(): void
    {
        $GLOBALS['_dono_test_options'] = [];
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('offline',  ['one_time', 'recurring'], ['card'], ['*'],  ['*']));
        $gm->register(new FakeGateway('usd_only', ['one_time'],              ['card'], ['DE'], ['USD']));

        // No context filter: usd_only is present so the runtime can re-resolve.
        $meta = $gm->optionsMetaFor([]);
        $this->assertSame(['offline', 'usd_only'], array_column($meta, 'id'));
        $this->assertSame(['USD'], $meta[1]['currencies']);
        $this->assertSame(['DE'], $meta[1]['countries']);
        $this->assertSame('', $meta[0]['description']);
    }

    public function test_options_meta_for_respects_org_disable_and_allowed_order(): void
    {
        $GLOBALS['_dono_test_options'] = ['dono_gateway_config' => ['usd_only' => ['enabled' => false]]];
        $gm = new GatewayManager();
        $gm->register(new FakeGateway('offline',  ['one_time'], ['card'], ['*'], ['*']));
        $gm->register(new FakeGateway('usd_only', ['one_time'], ['card'], ['*'], ['USD']));

        $this->assertSame(['offline'], array_column($gm->optionsMetaFor(['usd_only', 'offline']), 'id'));
    }
}
