<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\CampaignService;
use Gratora\Forms\Form;
use Gratora\Foundation\Plugin;

/**
 * A form with no payment method tells a donor only that it cannot take a
 * donation. Whoever manages the plugin is told why and where to put it right,
 * in words the runtime shows to nobody else.
 */
final class AFormThatCannotTakeADonationTellsItsOwnerWhyTest extends IntegrationTestCase
{
    /** @return array<string,mixed> the form's config as that person's browser receives it */
    private function formAs(int $user): array
    {
        wp_set_current_user(1);
        $campaign = Plugin::instance()->container->get(CampaignService::class)
            ->create(['title' => 'Winter food drive', 'status' => 'published']);
        $slug = (string) Form::query()->find('id', (int) $campaign->default_form_id)->slug;

        wp_set_current_user($user);

        return $this->formConfigIn(do_shortcode('[gratora_donation_form slug="' . $slug . '"]'));
    }

    public function test_whoever_manages_the_plugin_is_told_why_and_where_to_put_it_right(): void
    {
        update_option('gratora_gateway_config', ['test_mode' => false]);

        $this->assertSame([
            'text'      => 'Only you can see this. This form cannot take a donation, because no payment method is switched on. Connect payments, or turn on test mode.',
            'linkLabel' => 'Open payment settings',
            'linkUrl'   => admin_url('admin.php?page=gratora-settings#gateways'),
        ], $this->formAs(1)['ownerNotice']);
    }

    public function test_with_test_mode_on_they_are_not_told_to_turn_it_on(): void
    {
        update_option('gratora_gateway_config', ['test_mode' => true]);

        $this->assertSame(
            'Only you can see this. This form cannot take a donation, because none of the payment methods it allows is switched on.',
            $this->formAs(1)['ownerNotice']['text']
        );
    }

    public function test_a_visitor_is_sent_nothing_of_the_kind(): void
    {
        $this->assertArrayNotHasKey('ownerNotice', $this->formAs(0));
    }

    public function test_nor_is_someone_signed_in_who_does_not_manage_the_plugin(): void
    {
        $subscriber = self::factory()->user->create(['role' => 'subscriber']);

        $this->assertArrayNotHasKey('ownerNotice', $this->formAs($subscriber));
    }
}
