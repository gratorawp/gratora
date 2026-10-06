<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignService;
use Gratora\Donations\Donation;
use Gratora\Forms\Form;
use Gratora\Forms\Shortcode\DonationFormShortcode;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayConfirmResult;
use Gratora\Gateways\GatewayIntentResult;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\ModeCredentialed;
use Gratora\Gateways\PaymentGateway;
use Gratora\Gateways\RefundResult;
use Gratora\Gateways\WebhookOutcome;
use WP_REST_Request;

/**
 * A form with no payment method tells a donor only that it cannot take a
 * donation. Whoever manages the plugin is told why, in the words the form's
 * own setup check uses, and where to put it right. Nobody else is sent them.
 */
final class AFormThatCannotTakeADonationTellsItsOwnerWhyTest extends IntegrationTestCase
{
    private const NOTHING_ON = ['test_mode' => false];
    private const BANK_READY = ['test_mode' => false, 'offline' => ['instructions' => 'Transfer within 7 days.']];

    /** @param ?callable(Form):void $prepare what to change on the form before anyone looks */
    private function formAs(int $user, ?callable $prepare = null): array
    {
        wp_set_current_user(1);
        $campaign = Plugin::instance()->container->get(CampaignService::class)
            ->create(['title' => 'Winter food drive', 'status' => 'published']);
        $form = Form::query()->find('id', (int) $campaign->default_form_id);
        if ($prepare) {
            $prepare($form);
            $form->save();
        }

        wp_set_current_user($user);

        return $this->formConfigIn(do_shortcode('[gratora_donation_form slug="' . $form->slug . '"]'));
    }

    public function test_with_nothing_switched_on_they_are_told_so_and_sent_to_the_payment_settings(): void
    {
        update_option('gratora_gateway_config', self::NOTHING_ON);

        $this->assertSame([
            'text'      => 'Only you can see this. No payment gateway enabled for this form. Donors cannot complete a donation without a gateway. Enable one, or widen the gateways this form allows.',
            'linkLabel' => 'Configure gateways',
            'linkUrl'   => admin_url('admin.php?page=gratora-settings#gateways'),
        ], $this->formAs(1)['ownerNotice']);
    }

    /** A method is on here. Being told that none is would send them looking for the wrong thing. */
    public function test_a_form_that_allows_only_a_method_that_is_off_is_not_told_nothing_is_on(): void
    {
        update_option('gratora_gateway_config', self::BANK_READY);

        $notice = $this->formAs(1, static function (Form $form): void {
            $form->settings = ['gateways' => ['allowed' => ['paypal']]];
        })['ownerNotice'];

        $this->assertStringContainsString('widen the gateways this form allows', $notice['text']);
        $this->assertStringNotContainsString('test mode', $notice['text']);
    }

    /** The form's own switch, on a site whose switch is off: the way out is on the form. */
    public function test_a_form_in_its_own_test_mode_with_no_test_keys_is_sent_to_the_form(): void
    {
        update_option('gratora_gateway_config', self::NOTHING_ON);
        Plugin::instance()->container->get(GatewayManager::class)->register($this->gatewayWithLiveKeysOnly());

        $formId = 0;
        $notice = $this->formAs(1, static function (Form $form) use (&$formId): void {
            $form->settings = ['test_mode' => true];
            $formId         = (int) $form->id;
        })['ownerNotice'];

        $this->assertStringContainsString('This form is in test mode, but Acme Cards has no test credentials', $notice['text']);
        $this->assertSame('Open this form', $notice['linkLabel']);
        $this->assertSame(admin_url('admin.php?page=gratora-forms&form=' . $formId), $notice['linkUrl']);
    }

    public function test_a_form_that_can_take_a_donation_carries_no_such_line(): void
    {
        update_option('gratora_gateway_config', self::BANK_READY);

        $this->assertArrayNotHasKey('ownerNotice', $this->formAs(1));
    }

    public function test_a_visitor_is_sent_nothing_of_the_kind(): void
    {
        update_option('gratora_gateway_config', self::NOTHING_ON);

        $this->assertArrayNotHasKey('ownerNotice', $this->formAs(0));
    }

    public function test_nor_is_someone_signed_in_who_does_not_manage_the_plugin(): void
    {
        update_option('gratora_gateway_config', self::NOTHING_ON);
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);

        $this->assertArrayNotHasKey('ownerNotice', $this->formAs($subscriber));
    }

    /** A preview sits in a frame inside wp-admin, where the link would load the admin into itself. */
    public function test_a_preview_of_the_form_carries_no_such_line(): void
    {
        update_option('gratora_gateway_config', self::NOTHING_ON);
        $c = Plugin::instance()->container;

        $preview = $c->get(DonationFormShortcode::class)->renderPreview('<!-- wp:gratora/donation-amount /-->');

        $this->assertArrayNotHasKey('ownerNotice', $this->formConfigIn((string) $preview['html']));
    }

    private function gatewayWithLiveKeysOnly(): PaymentGateway
    {
        return new class implements PaymentGateway, ModeCredentialed {
            public function id(): string { return 'acme-cards'; }
            public function label(): string { return 'Acme Cards'; }
            public function description(): string { return ''; }
            public function frequencies(): array { return ['one_time']; }
            public function paymentMethods(): array { return ['card']; }
            public function countries(): array { return ['*']; }
            public function currencies(): array { return ['*']; }
            public function canCharge(): bool { return true; }
            public function chargesInMode(bool $test): bool { return ! $test; }
            public function frequenciesInMode(bool $test): array { return ['one_time']; }
            public function currenciesInMode(bool $test): array { return ['*']; }
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
                return WebhookOutcome::notSupported('acme-cards');
            }
        };
    }
}
