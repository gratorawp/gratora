<?php

declare(strict_types=1);

namespace Dono\Gateways\PayPal;

use Dono\Gateways\AccountFingerprint;
use RuntimeException;

/**
 * Provisions the Product and Billing Plans that PayPal subscriptions require.
 *
 * PayPal has no equivalent of Stripe's ad-hoc price: a subscription can only be
 * opened against a Plan, and a Plan carries a fixed amount plus interval. So a
 * plan is created on demand per (amount, currency, interval) and then reused,
 * otherwise a busy month would litter the merchant's account with duplicates.
 *
 * Both the product id and the plan ids are cached in options, keyed by mode and
 * account: sandbox and live are separate PayPal accounts.
 *
 * @since 1.0.0
 */
final class PayPalPlans
{
    private const PRODUCT_OPTION = 'dono_paypal_product';
    private const PLANS_OPTION   = 'dono_paypal_plans';

    /** @since 1.0.0 */
    public function __construct(private PayPalApi $api, private PayPalAccount $account)
    {
    }

    /**
     * Plan id for this amount + interval, created on first use.
     *
     * @throws RuntimeException when PayPal refuses to create the plan.
     *
     * @since 1.0.0
     */
    public function resolvePlan(bool $test, int $amountCents, string $currency, string $intervalUnit, int $intervalCount): string
    {
        $this->account->useTestMode($test);

        $key    = $this->planKey($test, $amountCents, $currency, $intervalUnit, $intervalCount);
        $cached = $this->plans();

        if (isset($cached[$key]) && is_string($cached[$key]) && $cached[$key] !== '') {
            return $cached[$key];
        }

        $plan = $this->api->post('/v1/billing/plans', [
            'product_id' => $this->resolveProduct($test),
            'name'       => sprintf(
                /* translators: 1: amount, 2: currency, 3: interval */
                __('Donation %1$s %2$s / %3$s', 'dono-fundraising-platform'),
                PayPalMoney::toValue($amountCents, $currency),
                $currency,
                $this->intervalLabel($intervalUnit, $intervalCount)
            ),
            'billing_cycles' => [[
                'frequency' => [
                    'interval_unit'  => strtoupper($intervalUnit),
                    'interval_count' => $intervalCount,
                ],
                'tenure_type'  => 'REGULAR',
                'sequence'     => 1,
                // 0 = bill forever, which is what an open-ended donation wants.
                'total_cycles' => 0,
                'pricing_scheme' => [
                    'fixed_price' => [
                        'currency_code' => $currency,
                        'value'         => PayPalMoney::toValue($amountCents, $currency),
                    ],
                ],
            ]],
            'payment_preferences' => [
                'auto_bill_outstanding'     => true,
                'setup_fee_failure_action'  => 'CONTINUE',
                'payment_failure_threshold' => 3,
            ],
        ], ['PayPal-Request-Id' => 'dono_plan_' . $key]);

        $planId = (string) ($plan['id'] ?? '');
        if ($planId === '') {
            throw new RuntimeException(esc_html('PayPal did not return a plan id.'));
        }

        $cached[$key] = $planId;
        update_option(self::PLANS_OPTION, $cached, false);

        return $planId;
    }

    /**
     * The single "Donation" product every plan hangs off. Created once per
     * mode and remembered.
     *
     * @since 1.0.0
     */
    private function resolveProduct(bool $test): string
    {
        $stored = get_option(self::PRODUCT_OPTION, []);
        $stored = is_array($stored) ? $stored : [];
        // Same rule as the plans: scope to the account that owns the product.
        $key    = ($test ? 'test' : 'live') . '_' . AccountFingerprint::of($this->account->clientIdFor($test));

        if (! empty($stored[$key]) && is_string($stored[$key])) {
            return $stored[$key];
        }

        $product = $this->api->post('/v1/catalogs/products', [
            'name'        => __('Donation', 'dono-fundraising-platform'),
            'description' => __('Recurring donation', 'dono-fundraising-platform'),
            'type'        => 'SERVICE',
            'category'    => 'NONPROFIT',
        ], ['PayPal-Request-Id' => 'dono_product_' . $key]);

        $productId = (string) ($product['id'] ?? '');
        if ($productId === '') {
            throw new RuntimeException(esc_html('PayPal did not return a product id.'));
        }

        $stored[$key] = $productId;
        update_option(self::PRODUCT_OPTION, $stored, false);

        return $productId;
    }

    /**
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function plans(): array
    {
        $stored = get_option(self::PLANS_OPTION, []);
        return is_array($stored) ? $stored : [];
    }

    /** @since 1.0.0 */
    /**
     * The amount a plan id was minted for, from the cache that minted it.
     *
     * PayPal applies a revise only once the donor approves it, and says so on
     * its own event rather than back through the call that asked. The plan id
     * the subscription then carries is the only thing naming the new amount,
     * and every id here was created against a key that records one.
     *
     * Null when the id is not one of ours, which a plan built in PayPal's own
     * dashboard would be.
     *
     * @since 1.0.0
     */
    public function amountForPlan(string $planId): ?int
    {
        if ($planId === '') {
            return null;
        }

        foreach ($this->plans() as $key => $id) {
            if ((string) $id !== $planId) {
                continue;
            }

            // live_<fingerprint>_<currency>_<amount>_<unit>_<count>
            $parts = explode('_', (string) $key);
            if (count($parts) < 6) {
                continue;
            }
            $amount = $parts[count($parts) - 3];

            return ctype_digit($amount) ? (int) $amount : null;
        }

        return null;
    }

    /** @since 1.0.0 */
    private function planKey(bool $test, int $amountCents, string $currency, string $unit, int $count): string
    {
        return implode('_', [
            $test ? 'test' : 'live',
            // A plan hangs off a product inside one merchant account and means
            // nothing in another.
            AccountFingerprint::of($this->account->clientIdFor($test)),
            strtolower($currency),
            $amountCents,
            strtolower($unit),
            $count,
        ]);
    }

    /** @since 1.0.0 */
    private function intervalLabel(string $unit, int $count): string
    {
        if ($count === 1) {
            return $unit;
        }
        return $count . ' ' . $unit . 's';
    }
}
