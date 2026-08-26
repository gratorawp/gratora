<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Forms\Form;
use GiveFlow\Forms\FormReadinessService;
use GiveFlow\Foundation\Plugin;
use GiveFlow\Gateways\GatewayManager;
use GiveFlow\Gateways\Stripe\StripeAccount;
use GiveFlow\Settings\SettingsService;

/**
 * The payment-gateways block decides where the selector goes, and whether there
 * is one at all.
 *
 * The runtime used to draw it on the last page when the block was absent, so
 * deleting it in the editor did not delete it from the form. Blocks render
 * where they are dropped, so that fallback is gone -- and the cost of removing
 * it is that a form offering two gateways with no block picks one for the donor
 * silently. Readiness is where the author finds that out.
 */
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
        $c->get(SettingsService::class)->update('gateways', ['offline' => ['enabled' => true]]);

        $account = $c->get(StripeAccount::class);
        $account->saveKeys(true, 'sk_test_block_check', 'pk_test_block_check');
        $account->refresh(['id' => 'acct_block_check', 'charges_enabled' => true]);

        $manager = $c->get(GatewayManager::class);
        if (! $manager->get('stripe')) {
            $manager->register(new \GiveFlow\Gateways\Stripe\StripeGateway(
                $c->get(\GiveFlow\Gateways\Stripe\StripeApi::class),
                $c->get(\GiveFlow\Donations\DonationRepository::class),
                $c->get(\GiveFlow\Donations\DonationService::class),
                $account,
                $c->get(\GiveFlow\Donors\DonorRepository::class),
                $c->get(\GiveFlow\Donors\DonorService::class),
                $c->get(\GiveFlow\Foundation\Time\Clock::class),
                $c->get(\GiveFlow\Recurring\RecurringPlanRepository::class),
            ));
        }

        $on = 0;
        foreach (array_keys($manager->all()) as $id) {
            if ($manager->isOn($id)) $on++;
        }
        $this->assertGreaterThanOrEqual(2, $on, 'the check is only meaningful with a real choice');
    }

    public function test_a_form_with_two_gateways_and_no_block_is_flagged(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form('<!-- wp:giveflow/donation-amount /-->'));

        $this->assertSame('warn', $check['status'] ?? null);
        $this->assertArrayHasKey('action_url', $check);
    }

    public function test_placing_the_block_clears_the_warning(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form(
            '<!-- wp:giveflow/donation-amount /--><!-- wp:giveflow/payment-gateways {"style":"cards"} /-->'
        ));

        $this->assertSame('pass', $check['status'] ?? null);
    }

    /** Nested inside a step or row still counts as placed. */
    public function test_the_block_is_found_inside_inner_blocks(): void
    {
        $this->enableTwoGateways();
        $check = $this->check($this->form(
            '<!-- wp:giveflow/step --><!-- wp:giveflow/payment-gateways /--><!-- /wp:giveflow/step -->'
        ));

        $this->assertSame('pass', $check['status'] ?? null);
    }
}
