<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\Form;
use Gratora\Forms\FormReadinessService;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeAccount;
use Gratora\Settings\SettingsService;

final class FormGatewayBlockCheckTest extends IntegrationTestCase
{
    private function readiness(): FormReadinessService
    {
        return Plugin::instance()->container->get(FormReadinessService::class);
    }

    private function form(string $blocks): Form
    {
        $form = Form::make();
        $form->campaign_id = 1;
        $form->slug        = 'gateway-block-' . bin2hex(random_bytes(3));
        $form->title       = 'Gateway block probe';
        $form->status      = 'published';
        $form->blocks      = $blocks;
        $form->settings    = [];
        $form->save();

        return $form;
    }

    /** @return array<string,mixed>|null */
    private function check(Form $form): ?array
    {
        foreach ($this->readiness()->check($form) as $c) {
            if (($c['id'] ?? '') === 'gateway-block') return $c;
        }
        return null;
    }

    /**
     * Offline plus a chargeable Stripe. Two is the threshold the check cares
     * about, so a single-gateway environment would make every assertion vacuous.
     */
    private function enableTwoGateways(): void
    {
        $c = Plugin::instance()->container;
        // Instructions, not just the flag: OfflineGateway::canCharge wants a way
        // to pay, and isOn asks it. Test mode because the Stripe keys below are
        // test keys, and isOn asks chargesInMode for the mode the site is in.
        $c->get(SettingsService::class)->update('gateways', [
            'test_mode' => true,
            'offline'   => ['enabled' => true, 'instructions' => 'Transfer quoting your reference.'],
        ]);

        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_block_check', 'pk_test_block_check');
        $account->refresh(['id' => 'acct_block_check', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \Gratora\Gateways\Stripe\StripeGateway(
                $c->get(\Gratora\Gateways\Stripe\StripeApi::class),
                $c->get(\Gratora\Donations\DonationRepository::class),
                $c->get(\Gratora\Donations\DonationService::class),
                $account,
                $c->get(\Gratora\Donors\DonorRepository::class),
                $c->get(\Gratora\Donors\DonorService::class),
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class),
            ));
        }

        $on = [];
        foreach (array_keys($manager->all()) as $id) {
            if ($manager->isOn($id)) $on[] = $id;
        }
        $this->assertGreaterThanOrEqual(
            2,
            count($on),
            'the check is only meaningful with a real choice; on: ' . implode(', ', $on)
        );
    }

    public function test_a_form_with_two_gateways_and_no_block_is_flagged(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form('<!-- wp:gratora/donation-amount /-->'));

        $this->assertSame('warn', $check['status'] ?? null);
        $this->assertArrayHasKey('action_url', $check);
    }

    public function test_placing_the_block_clears_the_warning(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form(
            '<!-- wp:gratora/donation-amount /--><!-- wp:gratora/payment-gateways {"style":"cards"} /-->'
        ));

        $this->assertSame('pass', $check['status'] ?? null);
    }

    public function test_the_block_is_found_inside_inner_blocks(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form(
            '<!-- wp:gratora/step --><!-- wp:gratora/payment-gateways /--><!-- /wp:gratora/step -->'
        ));

        $this->assertSame('pass', $check['status'] ?? null);
    }
}
