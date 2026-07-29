<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Donations\Donation;
use Dono\Forms\Form;
use Dono\Foundation\Plugin;
use Dono\Gateways\BrowserAware;
use Dono\Gateways\GatewayConfirmResult;
use Dono\Gateways\GatewayIntentResult;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\PaymentGateway;
use Dono\Gateways\RefundResult;
use Dono\Gateways\WebhookOutcome;
use WP_REST_Request;

/**
 * An add-on gateway has to reach the browser through the same two places a
 * built-in one does: the form's config script, and the create-donation
 * response. Both were once hardcoded to stripe/paypal/razorpay, so a registered
 * gateway could be chosen and then had no way to render its payment step.
 *
 * Razorpay now goes through the seam like every add-on gateway, so its key
 * appears only when it is registered and has something to say, rather than
 * always being present and usually null. Stripe and PayPal still have their own
 * branches; they ship in core, so nothing forces the issue.
 */
final class BrowserAwareGatewayTest extends IntegrationTestCase
{
    private string $formSlug;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);

        $manager = Plugin::instance()->container->get(GatewayManager::class);
        if (! $manager->get('fakepay')) {
            $manager->register(new FakeBrowserGateway());
        }

        $this->formSlug = $this->publishedForm();
    }

    /** @return array<string,mixed> the JSON the runtime reads out of the rendered form */
    private function renderedConfig(): array
    {
        $html = do_shortcode('[dono_donation_form slug="' . $this->formSlug . '"]');

        $this->assertMatchesRegularExpression(
            '/data-dono-form-config>(.*?)<\/script>/s',
            $html,
            'The form did not render its config script.'
        );
        preg_match('/data-dono-form-config>(.*?)<\/script>/s', $html, $m);
        $config = json_decode(html_entity_decode($m[1]), true);

        return is_array($config) ? $config : [];
    }

    public function test_a_browser_aware_gateway_reaches_the_form_config(): void
    {
        $config = $this->renderedConfig();

        $this->assertArrayHasKey('fakepay', $config);
        $this->assertSame('pk_fake_test', $config['fakepay']['publicKey']);
    }

    /** Nothing secret should ride along just because the gateway said so. */
    public function test_the_built_in_gateway_config_keys_are_untouched(): void
    {
        $config = $this->renderedConfig();

        foreach (['stripe', 'paypal', 'gateways', 'testMode'] as $key) {
            $this->assertArrayHasKey($key, $config);
        }
    }

    public function test_a_browser_aware_gateway_reaches_the_create_response(): void
    {
        $data = $this->submit();

        $this->assertArrayHasKey('fakepay', $data, 'The gateway payload never reached the browser.');
        $this->assertSame('sess_fake_1', $data['fakepay']['sessionId']);
    }

    /** The response must still carry what the shipped gateways put there. */
    public function test_the_create_response_still_carries_the_built_in_keys(): void
    {
        $data = $this->submit();

        foreach (['reference', 'status', 'gateway', 'intent_id', 'paypal'] as $key) {
            $this->assertArrayHasKey($key, $data);
        }
    }

    /** A gateway that does not opt in must add no key at all, not an empty one. */
    public function test_a_plain_gateway_adds_no_key(): void
    {
        $config = $this->renderedConfig();

        $this->assertArrayNotHasKey('offline', $config);
    }

    private function submit(): array
    {
        $request = new WP_REST_Request('POST', '/dono/v1/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode([
            'form_slug'    => $this->formSlug,
            'email'        => 'browser@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'fakepay',
            'frequency'    => 'one_time',
            'profile'      => ['first_name' => 'Bee', 'last_name' => 'Browser'],
        ]));

        $data = (array) rest_do_request($request)->get_data();
        if (! isset($data['reference'])) {
            $this->fail('Donation creation failed: ' . wp_json_encode($data));
        }

        return $data;
    }

    private function publishedForm(): string
    {
        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Browser gateway', 'status' => 'published']));
        $campaignId = (int) rest_do_request($req)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Browser gateway form',
            'campaign_id' => $campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount /-->',
        ]));
        $created = (array) rest_do_request($req)->get_data();

        $form = Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        $campaign = Campaign::query()->find('id', $campaignId);
        $campaign->default_form_id = (int) $created['id'];
        $campaign->save();

        return (string) $created['slug'];
    }
}

/** Minimal third-party gateway: enough surface to be registered and chosen. */
final class FakeBrowserGateway implements PaymentGateway, BrowserAware
{
    public function id(): string { return 'fakepay'; }
    public function label(): string { return 'FakePay'; }
    public function description(): string { return 'Pay with the test double.'; }
    public function frequencies(): array { return ['one_time']; }
    public function paymentMethods(): array { return ['card']; }
    public function countries(): array { return ['*']; }
    public function currencies(): array { return ['*']; }
    public function canCharge(): bool { return true; }

    public function createIntent(Donation $donation): GatewayIntentResult
    {
        return new GatewayIntentResult(
            intent_id: 'fake_intent_1',
            metadata:  ['fakepay_session' => 'sess_fake_1', 'fakepay_secret' => 'never-show-me'],
        );
    }

    public function confirm(Donation $donation, array $payload = []): GatewayConfirmResult
    {
        return new GatewayConfirmResult(success: true, gateway_txn_id: 'fake_txn_1');
    }

    public function handleWebhook(WP_REST_Request $request): WebhookOutcome
    {
        return WebhookOutcome::notSupported('fakepay');
    }

    public function refund(Donation $donation, int $amountCents, ?string $reason = null): RefundResult
    {
        return new RefundResult(success: true, gateway_refund_id: 'fake_ref_1', amount_cents: $amountCents);
    }

    public function publicConfig(bool $test, string $currency): array
    {
        return ['publicKey' => $test ? 'pk_fake_test' : 'pk_fake_live'];
    }

    public function browserPayload(GatewayIntentResult $result): ?array
    {
        $meta = $result->metadata ?? [];

        return ['sessionId' => (string) ($meta['fakepay_session'] ?? '')];
    }
}
