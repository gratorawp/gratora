<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\DonationRepository;
use Gratora\Donations\DonationService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Crypto\Crypto;
use Gratora\Foundation\Plugin;
use Gratora\Foundation\Time\Clock;
use Gratora\Forms\Form;
use Gratora\Forms\FormReadinessService;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Gateways\Stripe\StripeApi;
use Gratora\Gateways\Stripe\StripeGateway;
use Gratora\Recurring\RecurringPlanRepository;
use WP_REST_Request;

/**
 * The mode a donation runs in is its form's, not the site's: a form can tick
 * test mode while the org switch is off. The picker asked the site, so a site
 * with live keys only offered Stripe on that form, the donor picked it, and
 * createIntent failed with no test key to charge against. Every donation on
 * that form, and no screen said so.
 */
final class FormTestModeCredentialsTest extends IntegrationTestCase
{
    private int $formId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Live keys only, org switch off: exactly the shape the finding names.
        update_option('gratora_gateway_config', ['stripe' => ['enabled' => true]]);

        $account = Plugin::instance()->container->get(StripeAccount::class);
        $account->forget();
        $account->saveKeys(false, 'sk_live_only', 'pk_live_only');
        $account->refresh(['id' => 'acct_live', 'charges_enabled' => true]);

        $this->registerStripe();
        $this->formId = $this->makeForm(true);
    }

    protected function tearDown(): void
    {
        $this->deregisterGateway('stripe');
        parent::tearDown();
    }

    private function registerStripe(): void
    {
        $c       = Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);
        if ($manager->get('stripe')) {
            return;
        }

        $manager->register(new StripeGateway(
            $c->get(StripeApi::class),
            $c->get(DonationRepository::class),
            $c->get(DonationService::class),
            $c->get(StripeAccount::class),
            $c->get(DonorRepository::class),
            $c->get(DonorService::class),
            $c->get(Clock::class),
            $c->get(RecurringPlanRepository::class),
        ));
    }

    private function makeForm(bool $testMode): int
    {
        $campaign = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $campaign->set_header('content-type', 'application/json');
        $campaign->set_body((string) wp_json_encode(['title' => 'Mode campaign', 'status' => 'published']));
        $campaignId = (int) rest_do_request($campaign)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Test mode form',
            'campaign_id' => $campaignId,
            'blocks' => '<!-- wp:gratora/donation-amount {"presets":[1000]} /-->'
                . '<!-- wp:gratora/email {"required":true} /-->'
                . '<!-- wp:gratora/payment-gateways /-->'
                . '<!-- wp:gratora/submit-button /-->',
            'settings' => ['test_mode' => $testMode],
        ]));

        $res = rest_do_request($req);
        $this->assertContains($res->get_status(), [200, 201], (string) wp_json_encode($res->get_data()));

        $id   = (int) $res->get_data()['id'];
        $form = Form::query()->find('id', $id);
        $form->status = 'published';
        $form->save();

        return $id;
    }

    private function form(): Form
    {
        return Form::query()->find('id', $this->formId);
    }

    private function readiness(): array
    {
        $checks = Plugin::instance()->container->get(FormReadinessService::class)->check($this->form());

        foreach ($checks as $check) {
            if ($check['id'] === 'test-mode') {
                return $check;
            }
        }

        return [];
    }

    public function test_a_gateway_with_no_test_keys_is_not_offered_on_a_test_mode_form(): void
    {
        $offered = Plugin::instance()->container->get(GatewayManager::class)
            ->optionsFor([], null, 'USD', 'one_time', true);

        $this->assertNotContains('stripe', $offered, 'live keys cannot take a test donation');
    }

    public function test_the_same_gateway_is_still_offered_when_the_form_is_live(): void
    {
        $offered = Plugin::instance()->container->get(GatewayManager::class)
            ->optionsFor([], null, 'USD', 'one_time', false);

        $this->assertContains('stripe', $offered);
    }

    /** The author is told before a donor finds out. */
    public function test_readiness_calls_it_a_failure_rather_than_a_warning(): void
    {
        $check = $this->readiness();

        $this->assertSame('fail', $check['status'] ?? '');
        $this->assertStringContainsString('no test credentials', (string) ($check['label'] ?? ''));
    }

    public function test_with_test_keys_it_is_only_a_reminder(): void
    {
        Plugin::instance()->container->get(StripeAccount::class)
            ->saveKeys(true, 'sk_test_present', 'pk_test_present');

        $this->assertSame('warn', $this->readiness()['status'] ?? '');
    }

    public function test_the_endpoint_refuses_a_gateway_the_form_cannot_run(): void
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'form_id'      => $this->formId,
            'email'        => 'mode-' . uniqid() . '@example.test',
            'amount_cents' => 1000,
            'currency'     => 'USD',
            'gateway'      => 'stripe',
        ]));

        $res = rest_do_request($req);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('gratora_gateway_not_allowed', $res->as_error()->get_error_code());
    }
}
