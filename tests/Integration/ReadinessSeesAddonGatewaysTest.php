<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Foundation\Plugin;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\WebhookOutcome;
use Dono\Donations\Donation;
use WP_REST_Request;

/**
 * Readiness has to see every gateway, not the three core happens to ship.
 *
 * The check named the core gateways one by one, so an organization
 * whose only payment method arrives in an add-on was told it had none
 * configured and could not take donations. It could, and was: the screen was
 * wrong about the one thing it exists to report.
 */
final class ReadinessSeesAddonGatewaysTest extends IntegrationTestCase
{
    /**
     * The registry is shared and has no removal API, so a gateway registered
     * here would decide the outcome of every sibling test that assumes an empty
     * one. Put back exactly as found.
     */
    protected function tearDown(): void
    {
        $manager = Plugin::instance()->container->get(GatewayManager::class);

        $property = new \ReflectionProperty($manager, 'gateways');
        $property->setAccessible(true);
        $all = $property->getValue($manager);
        unset($all['acme-bank']);
        $property->setValue($manager, $all);

        parent::tearDown();
    }

    /**
     * Through the route the admin screen actually calls, so the test does not
     * depend on how the service happens to be constructed.
     *
     * @return array<string,mixed> the gateway row, whatever its shape
     */
    private function gatewayRow(): array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $data = (array) rest_do_request(new WP_REST_Request('GET', '/dono/v1/admin/readiness'))->get_data();

        foreach ((array) ($data['checks'] ?? $data) as $row) {
            if (is_array($row) && ($row['id'] ?? '') === 'gateway') {
                return $row;
            }
        }

        $this->fail('no gateway readiness row: ' . wp_json_encode($data));
    }

    private function registerAddonGateway(): void
    {
        // The registry outlives a single test, and registering twice throws.
        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if ($manager->get('acme-bank')) {
            return;
        }

        $manager->register(
            new class implements PaymentGateway {
                public function id(): string { return 'acme-bank'; }
                public function label(): string { return 'Acme Bank'; }
                public function description(): string { return ''; }
                public function frequencies(): array { return ['one_time']; }
                public function paymentMethods(): array { return ['card']; }
                public function countries(): array { return ['*']; }
                public function currencies(): array { return ['USD']; }
                public function canCharge(): bool { return true; }
                public function createIntent(Donation $d): GatewayIntentResult
                {
                    return new GatewayIntentResult(intent_id: 'x');
                }
                public function confirm(Donation $d, array $p = []): GatewayConfirmResult
                {
                    return new GatewayConfirmResult(success: true);
                }
                public function refund(Donation $d, int $c, ?string $r = null): RefundResult
                {
                    return RefundResult::failure('no');
                }
                public function handleWebhook(WP_REST_Request $r): WebhookOutcome
                {
                    return WebhookOutcome::notSupported('acme-bank');
                }
            }
        );
    }

    /**
     * The scenario: a Canadian charity on Moneris, or a UK one on GoCardless.
     * Their gateway works. Readiness said they had none.
     */
    public function test_a_site_whose_only_gateway_is_an_add_on_reads_as_ready(): void
    {
        $this->registerAddonGateway();

        $this->assertNotSame(
            'fail',
            (string) ($this->gatewayRow()['status'] ?? ''),
            'a working add-on gateway is a working gateway'
        );
    }

    /** And it should be named, so the admin can see what is answering. */
    public function test_the_add_on_gateway_is_named_in_the_readiness_row(): void
    {
        $this->registerAddonGateway();

        $this->assertStringContainsString('Acme Bank', (string) wp_json_encode($this->gatewayRow()));
    }

}
