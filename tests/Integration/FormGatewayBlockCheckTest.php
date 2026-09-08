<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\Form;
use FundKit\Forms\FormReadinessService;
use FundKit\Foundation\Plugin;
use FundKit\Gateways\GatewayManager;
use FundKit\Gateways\Stripe\StripeAccount;
use FundKit\Settings\SettingsService;

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
            $manager->register(new \FundKit\Gateways\Stripe\StripeGateway(
                $c->get(\FundKit\Gateways\Stripe\StripeApi::class),
                $c->get(\FundKit\Donations\DonationRepository::class),
                $c->get(\FundKit\Donations\DonationService::class),
                $account,
                $c->get(\FundKit\Donors\DonorRepository::class),
                $c->get(\FundKit\Donors\DonorService::class),
                $c->get(\FundKit\Foundation\Time\Clock::class),
                $c->get(\FundKit\Recurring\RecurringPlanRepository::class),
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
        $check = $this->check($this->form('<!-- wp:fundkit/donation-amount /-->'));

        $this->assertSame('warn', $check['status'] ?? null);
        $this->assertArrayHasKey('action_url', $check);
    }

    public function test_placing_the_block_clears_the_warning(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form(
            '<!-- wp:fundkit/donation-amount /--><!-- wp:fundkit/payment-gateways {"style":"cards"} /-->'
        ));

        $this->assertSame('pass', $check['status'] ?? null);
    }

    public function test_the_block_is_found_inside_inner_blocks(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form(
            '<!-- wp:fundkit/step --><!-- wp:fundkit/payment-gateways /--><!-- /wp:fundkit/step -->'
        ));

        $this->assertSame('pass', $check['status'] ?? null);
    }
}
