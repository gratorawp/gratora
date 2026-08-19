<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\DonationRepository;
use Dono\Donations\DonationService;
use Dono\Foundation\Plugin;
use Dono\Foundation\Time\Clock;
use Dono\Gateways\PayPal\PayPalAccount;
use Dono\Gateways\PayPal\PayPalApi;
use Dono\Gateways\PayPal\PayPalGateway;
use Dono\Gateways\PayPal\PayPalPlanRecorder;
use Dono\Gateways\PayPal\PayPalPlans;
use Dono\Recurring\RecurringPlan;
use Dono\Recurring\RecurringPlanRepository;
use RuntimeException;

/**
 * Pausing a subscription PayPal has already suspended, and resuming one it has
 * already made active, are both no-ops rather than failures: the donor asked
 * for a state the subscription is in, so there is nothing to report.
 *
 * PayPal refuses those calls, and the refusal is what has to be recognised.
 * Its shared error model requires `name` and leaves `details` optional, so the
 * refusal can arrive naming itself and carrying nothing else. Recognised only
 * by the detail issues, that shape carries nothing to match: the refusal is
 * passed on, and a donor who pressed pause twice is shown an error for a
 * subscription that is already paused.
 *
 * The state is confirmed by re-reading the subscription before anything is
 * swallowed, so recognising the refusal only decides whether to ask.
 */
final class PayPalAlreadyInThatStateTest extends IntegrationTestCase
{
    /** How PayPal describes it when the subscription is already there. */
    private const WITH_DETAILS = [
        'name'    => 'UNPROCESSABLE_ENTITY',
        'message' => 'The requested action could not be performed.',
        'details' => [[
            'issue'       => 'SUBSCRIPTION_STATUS_INVALID',
            'description' => 'Invalid subscription status for suspend action; subscription status should be active.',
        ]],
    ];

    /** The same refusal, named and nothing more. */
    private const NAME_ONLY = [
        'name'     => 'INVALID_RESOURCE_STATE',
        'message'  => 'The requested action could not be performed.',
        'debug_id' => 'f2c1a7d9e4b30',
    ];

    /** @var array<string,mixed> */
    private array $refusal = self::WITH_DETAILS;

    private string $subscriptionStatus = 'SUSPENDED';

    protected function setUp(): void
    {
        parent::setUp();

        update_option('dono_gateway_config', ['test_mode' => true]);

        $c       = Plugin::instance()->container;
        $account = $c->get(PayPalAccount::class);
        $account->forget();
        $account->saveKeys(true, 'AeA1QIZ_client', 'EO422dn3_secret');
        $account->saveWebhookId(true, 'WH-TEST-1');

        add_filter('pre_http_request', function ($pre, $args, $url) {
            if (! is_string($url) || ! str_contains($url, 'paypal.com')) return $pre;

            $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');

            if (str_contains($path, '/v1/oauth2/token')) {
                return $this->reply(['access_token' => 'A21AAF_test', 'expires_in' => 32400], 200);
            }

            // The action PayPal refuses, because the subscription is already
            // in the state being asked for.
            if (str_ends_with($path, '/suspend') || str_ends_with($path, '/activate')) {
                return $this->reply($this->refusal, 422);
            }

            // The read that settles it.
            if (str_contains($path, '/v1/billing/subscriptions/')) {
                return $this->reply(['id' => 'I-STATE-1', 'status' => $this->subscriptionStatus], 200);
            }

            return $this->reply(['id' => 'OBJ-1'], 200);
        }, 10, 3);
    }

    /**
     * @param  array<string,mixed> $body
     * @return array<string,mixed>
     */
    private function reply(array $body, int $code): array
    {
        return [
            'headers'  => [],
            'body'     => (string) wp_json_encode($body),
            'response' => ['code' => $code, 'message' => $code === 200 ? 'OK' : 'Unprocessable Entity'],
            'cookies'  => [],
            'filename' => null,
        ];
    }

    private function gateway(): PayPalGateway
    {
        $c = Plugin::instance()->container;

        return new PayPalGateway(
            $c->get(PayPalApi::class),
            $c->get(PayPalAccount::class),
            $c->get(DonationRepository::class),
            $c->get(DonationService::class),
            $c->get(PayPalPlans::class),
            $c->get(RecurringPlanRepository::class),
            $c->get(Clock::class),
            $c->get(PayPalPlanRecorder::class),
        );
    }

    private function plan(): RecurringPlan
    {
        $now = gmdate('Y-m-d H:i:s');

        $plan = RecurringPlan::make();
        $plan->donor_id                = 1;
        $plan->gateway                 = 'paypal';
        $plan->gateway_subscription_id = 'I-STATE-1';
        $plan->amount_cents            = 2500;
        $plan->currency                = 'USD';
        $plan->status                  = 'active';
        $plan->is_test                 = true;
        $plan->started_at              = $now;
        $plan->created_at              = $now;
        $plan->updated_at              = $now;
        $plan->save();

        return $plan;
    }

    public function test_a_refusal_carrying_details_is_recognised(): void
    {
        $this->refusal = self::WITH_DETAILS;

        $this->gateway()->pauseSubscription($this->plan());

        $this->addToAssertionCount(1);
    }

    /** The shape the shared error model permits, and the one that used to escape. */
    public function test_a_refusal_carrying_only_a_name_is_recognised(): void
    {
        $this->refusal = self::NAME_ONLY;

        $this->gateway()->pauseSubscription($this->plan());

        $this->addToAssertionCount(1);
    }

    public function test_a_resume_is_recognised_the_same_way(): void
    {
        $this->refusal            = self::NAME_ONLY;
        $this->subscriptionStatus = 'ACTIVE';

        $this->gateway()->resumeSubscription($this->plan());

        $this->addToAssertionCount(1);
    }

    /**
     * Recognising the refusal only decides whether to ask. A subscription that
     * is not in the asked-for state is a real failure, and must still be one,
     * or a pause that never happened is reported to the donor as done.
     */
    public function test_a_subscription_not_in_that_state_is_still_a_failure(): void
    {
        $this->refusal            = self::NAME_ONLY;
        $this->subscriptionStatus = 'ACTIVE';

        $this->expectException(RuntimeException::class);
        $this->gateway()->pauseSubscription($this->plan());
    }
}
