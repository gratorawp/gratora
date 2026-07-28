<?php

declare(strict_types=1);

namespace Dono\Gateways\Razorpay;

use RuntimeException;

/**
 * Provisions the Plans that Razorpay subscriptions require.
 *
 * Like PayPal, a subscription can only be opened against a Plan carrying a
 * fixed amount plus period, so a plan is created on demand per (amount,
 * currency, period, interval) and then reused. Cached in an option keyed by
 * mode, since test and live plans are separate objects.
 *
 * @version 1.0.0
 */
final class RazorpayPlans
{
    private const PLANS_OPTION = 'dono_razorpay_plans';

    public function __construct(private RazorpayApi $api, private RazorpayAccount $account)
    {
    }

    /**
     * Plan id for this amount + period, created on first use.
     *
     * @param string $period one of daily, weekly, monthly, yearly.
     * @throws RuntimeException when Razorpay refuses to create the plan.
     */
    public function resolvePlan(bool $test, int $amountCents, string $currency, string $period, int $interval): string
    {
        $this->account->useTestMode($test);

        $key    = $this->planKey($test, $amountCents, $currency, $period, $interval);
        $cached = $this->plans();

        if (isset($cached[$key]) && is_string($cached[$key]) && $cached[$key] !== '') {
            return $cached[$key];
        }

        $plan = $this->api->post('/v1/plans', [
            'period'   => $period,
            'interval' => $interval,
            'item'     => [
                'name'     => __('Donation', 'dono'),
                'amount'   => RazorpayMoney::toAmount($amountCents, $currency),
                'currency' => $currency,
            ],
            'notes' => ['dono_plan_key' => $key],
        ]);

        $planId = (string) ($plan['id'] ?? '');
        if ($planId === '') {
            throw new RuntimeException('Razorpay did not return a plan id.');
        }

        $cached[$key] = $planId;
        update_option(self::PLANS_OPTION, $cached, false);

        return $planId;
    }

    /** @return array<string,string> */
    private function plans(): array
    {
        $stored = get_option(self::PLANS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    private function planKey(bool $test, int $amountCents, string $currency, string $period, int $interval): string
    {
        return implode('_', [
            $test ? 'test' : 'live',
            strtolower($currency),
            $amountCents,
            strtolower($period),
            $interval,
        ]);
    }
}
