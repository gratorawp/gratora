<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Form;
use WP_REST_Request;

/**
 * The form a page block carries runs on the JSON config printed beside it. No
 * allow-list passes that script, and kses would rewrite every & in it, so it
 * has to reach the page exactly as the form wrote it.
 */
final class CampaignFormBlocksCarryTheFormConfigTest extends IntegrationTestCase
{
    private const THANKS  = 'Thanks & welcome, "friend" <3';
    private const PRIVACY = 'https://example.org/privacy?lang=en&v=2';

    private int $campaignId;
    private string $formSlug;

    protected function setUp(): void
    {
        parent::setUp();

        update_option('gratora_privacy', ['privacy_policy_url' => self::PRIVACY]);

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Config carry probe', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];

        // The block draws the campaign's default form, which creating the
        // campaign seeded.
        $campaign = Campaign::query()->find('id', $this->campaignId);
        $form     = Form::query()->find('id', (int) $campaign->default_form_id);
        $form->status   = 'published';
        $form->settings = array_merge(is_array($form->settings) ? $form->settings : [], ['thank_you_message' => self::THANKS]);
        $form->save();

        $this->formSlug = (string) $form->slug;
    }

    /** @return array<string, array{string}> */
    public static function blocks(): array
    {
        return [
            'donation form' => ['donation-form'],
            'donate button' => ['donate-button'],
        ];
    }

    /** @dataProvider blocks */
    public function test_the_block_carries_the_config_its_form_writes(string $block): void
    {
        $carried = $this->formConfigIn(do_blocks('<!-- wp:gratora/' . $block . ' {"campaignId":' . $this->campaignId . '} /-->'));
        $written = $this->formConfigIn(do_shortcode('[gratora_donation_form slug="' . $this->formSlug . '"]'));

        $this->assertSame($this->withoutMintedValues($written), $this->withoutMintedValues($carried));
        $this->assertSame(self::THANKS, $carried['thanks']['message'] ?? null);
        $this->assertSame(self::PRIVACY, $carried['privacyPolicyUrl'] ?? null);
    }

    /**
     * Each render mints these afresh: the honeypot name at random and the
     * tokens per time bucket.
     *
     * @param array<string, mixed> $config
     * @return array<string, mixed>
     */
    private function withoutMintedValues(array $config): array
    {
        unset($config['spam']['honeypotName'], $config['spam']['formToken'], $config['portal']['token']);

        return $config;
    }
}
