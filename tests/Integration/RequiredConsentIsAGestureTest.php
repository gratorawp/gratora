<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donors\Consent;
use FundKit\Forms\Blocks\Block;
use FundKit\Forms\Blocks\BlockRegistry;
use FundKit\Forms\Form;
use FundKit\Foundation\Plugin;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * A consent row is evidence: it says this donor, from this address, agreed to
 * this wording on this day, and it is what the organization would produce if
 * the donor later said they never opted in. A box the donor could not move is
 * not that. Required means the donor has to tick it, which is what the server
 * validator has always assumed, so the form has to let them.
 */
final class RequiredConsentIsAGestureTest extends IntegrationTestCase
{
    private int $campaignId;

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Consent campaign', 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    protected function tearDown(): void
    {
        delete_option('fundkit_consents');
        parent::tearDown();
    }

    private function registerPurpose(bool $required, bool $default = false): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('consents', [
            'purposes' => [[
                'key'         => 'campaign_news',
                'label'       => 'Email me about this campaign',
                'description' => '',
                'required'    => $required,
                'default'     => $default,
                'version'     => 1,
            ]],
        ]);
    }

    private function consentBlock(): Block
    {
        foreach (Plugin::instance()->container->get(BlockRegistry::class)->all() as $b) {
            if ($b->name() === 'fundkit/consent') return $b;
        }

        $this->fail('the consent block is not registered');
    }

    /** @return array<string,mixed> */
    private function runtimePurpose(): array
    {
        $html = do_shortcode('[fundkit_donation_form slug="' . $this->publishedForm() . '"]');
        preg_match('/data-fundkit-form-config>(.+?)<\/script>/s', $html, $m);
        $config = json_decode((string) ($m[1] ?? ''), true);

        foreach ((array) ($config['steps'] ?? []) as $step) {
            foreach ((array) ($step['items'] ?? []) as $item) {
                if (($item['kind'] ?? '') !== 'consent') continue;
                foreach ((array) ($item['purposes'] ?? []) as $p) {
                    if (($p['id'] ?? '') === 'campaign_news') return $p;
                }
            }
        }

        $this->fail('the form did not publish the consent purpose to the runtime');
    }

    private function publishedForm(): string
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Consent form',
            'campaign_id' => $this->campaignId,
            'blocks'      => '<!-- wp:fundkit/donation-amount {"presets":[{"cents":2500}]} /-->'
                . '<!-- wp:fundkit/email /-->'
                . '<!-- wp:fundkit/consent {"purposeKeys":["campaign_news"]} /-->'
                . '<!-- wp:fundkit/submit-button /-->',
        ]));
        $created = rest_do_request($req)->get_data();

        $form = Form::query()->find('id', (int) $created['id']);
        $form->status = 'published';
        $form->save();

        return (string) $created['slug'];
    }

    public function test_a_required_purpose_reaches_the_donor_unticked(): void
    {
        $this->registerPurpose(true);

        $purpose = $this->runtimePurpose();

        $this->assertTrue($purpose['required']);
        $this->assertFalse($purpose['checked'], 'the donor is shown a granted consent they never gave');
    }

    /**
     * An org that marks a purpose both required and pre-ticked is still asking
     * for a gesture, so the pre-tick loses.
     */
    public function test_required_beats_a_default_of_ticked(): void
    {
        $this->registerPurpose(true, true);

        $this->assertFalse($this->runtimePurpose()['checked']);
    }

    /** An optional purpose the org wants ticked is a suggestion the donor can undo. */
    public function test_an_optional_purpose_keeps_its_default(): void
    {
        $this->registerPurpose(false, true);

        $purpose = $this->runtimePurpose();

        $this->assertFalse($purpose['required']);
        $this->assertTrue($purpose['checked']);
    }

    public function test_the_server_rendered_control_is_not_locked_or_answered_for_the_donor(): void
    {
        $this->registerPurpose(true);

        $html = $this->consentBlock()->render(['purposeKeys' => ['campaign_news']], '');

        $this->assertStringContainsString('consents[campaign_news]', $html);
        $this->assertStringNotContainsString('disabled', $html, 'the donor cannot answer a control they cannot reach');
        $this->assertStringNotContainsString('type="hidden"', $html, 'a hidden true answers for the donor');
        $this->assertStringNotContainsString('checked', $html);
    }

    /**
     * The point of all of the above, end to end. The runtime seeds its consent
     * values from the `checked` flag the form publishes and submits them as
     * they stand, so this is literally what a donor who touches nothing sends:
     * the submission is refused, and no evidence row claims they agreed.
     */
    public function test_a_donor_who_touches_nothing_grants_nothing(): void
    {
        $this->registerPurpose(true);
        $purpose = $this->runtimePurpose();
        $form    = Form::query()->find('slug', $this->publishedForm());

        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'form_id'      => (int) $form->id,
            'email'        => 'untouched@example.com',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'consents'     => ['campaign_news' => (bool) $purpose['checked']],
        ]));
        $res = rest_do_request($req);

        $this->assertSame(400, $res->get_status(), 'the donation was banked without the donor agreeing to anything');
        $this->assertSame(
            [],
            Consent::query()->where('purpose', 'campaign_news')->where('granted', 1)->getAll(),
            'a consent row asserts an act the donor never performed'
        );
    }
}
