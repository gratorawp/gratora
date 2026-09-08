<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Forms\Form;
use FundKit\Forms\FormService;
use FundKit\Foundation\Plugin;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * What the editor shows an author about the form they are looking at, rather
 * than about the one last saved.
 */
final class FormEditingTruthTest extends IntegrationTestCase
{
    private int $campaignId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Forms ' . uniqid(), 'status' => 'published']));
        $this->campaignId = (int) rest_do_request($req)->get_data()['id'];
    }

    private const REQUIRED_BLOCKS = '<!-- wp:fundkit/donation-amount {"presets":[{"cents":2500}]} /-->'
        . '<!-- wp:fundkit/name /-->'
        . '<!-- wp:fundkit/email /-->'
        . '<!-- wp:fundkit/submit-button /-->';

    private function forms(): FormService
    {
        return Plugin::instance()->container->get(FormService::class);
    }

    private function form(string $blocks, string $status = 'draft'): Form
    {
        return $this->forms()->create([
            'title'       => 'Form ' . uniqid(),
            'campaign_id' => $this->campaignId,
            'blocks'      => $blocks,
            'status'      => $status,
        ]);
    }

    /** @return list<array<string,mixed>> */
    private function readiness(Form $form, string $blocks): array
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/admin/forms/' . (int) $form->id . '/readiness');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['blocks' => $blocks]));

        return (array) rest_do_request($req)->get_data()['checks'];
    }

    private function check(array $checks, string $id): ?array
    {
        foreach ($checks as $c) {
            if (($c['id'] ?? '') === $id) return $c;
        }

        return null;
    }

    public function test_readiness_reads_the_gateways_block_in_the_editor(): void
    {
        $form = $this->form(self::REQUIRED_BLOCKS);

        // Restricted to a gateway this site does not have: the form can take
        // no money, and the check has to say so about the blocks in front of
        // the author rather than the ones last saved.
        $edited = self::REQUIRED_BLOCKS
            . '<!-- wp:fundkit/payment-gateways {"allowed":["stripe"]} /-->';

        $this->assertSame(
            'fail',
            $this->check($this->readiness($form, $edited), 'gateway')['status'] ?? null,
            'no gateway this form allows is switched on'
        );

        $widened = self::REQUIRED_BLOCKS
            . '<!-- wp:fundkit/payment-gateways {"allowed":["offline"]} /-->';

        $this->assertSame(
            'pass',
            $this->check($this->readiness($form, $widened), 'gateway')['status'] ?? null,
            'and the same edit the other way round'
        );
    }

    public function test_a_consent_purpose_that_no_longer_exists_is_reported(): void
    {
        Plugin::instance()->container->get(SettingsService::class)->update('consents', [
            'purposes' => [[
                'key'         => 'terms',
                'label'       => 'Terms and privacy',
                'description' => '',
                'required'    => true,
                'default'     => false,
                'version'     => 1,
            ]],
        ]);

        $form = $this->form(self::REQUIRED_BLOCKS . '<!-- wp:fundkit/consent {"purposeKeys":["terms"]} /-->');

        $ok = $this->check($this->readiness($form, (string) $form->blocks), 'consent-purposes');
        $this->assertSame('pass', $ok['status'] ?? null);

        // The org renames the key.
        Plugin::instance()->container->get(SettingsService::class)->update('consents', [
            'purposes' => [[
                'key'         => 'terms-and-privacy',
                'label'       => 'Terms and privacy',
                'description' => '',
                'required'    => true,
                'default'     => false,
                'version'     => 1,
            ]],
        ]);

        $gone = $this->check($this->readiness($form, (string) $form->blocks), 'consent-purposes');
        $this->assertSame('fail', $gone['status'] ?? null, 'nothing is being recorded and nothing said so');
        $this->assertStringContainsString('terms', (string) ($gone['detail'] ?? ''));
    }

    public function test_a_form_with_no_consent_block_passes_that_check(): void
    {
        $form = $this->form(self::REQUIRED_BLOCKS);

        $this->assertSame(
            'pass',
            $this->check($this->readiness($form, (string) $form->blocks), 'consent-purposes')['status'] ?? null
        );
    }

    /**
     * The refusal is right; the sentence was not. A live form cannot be saved
     * without the block, and the way out is to take it off the page first.
     */
    public function test_a_live_form_missing_a_block_is_told_the_way_out(): void
    {
        $form = $this->form(self::REQUIRED_BLOCKS, 'published');

        try {
            $this->forms()->update($form, [
                'blocks' => '<!-- wp:fundkit/name /--><!-- wp:fundkit/email /--><!-- wp:fundkit/submit-button /-->',
            ]);
            $this->fail('a live form cannot lose its amount block');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('draft', $e->getMessage(), 'the message names the way out');
        }
    }

    public function test_publishing_a_draft_still_says_what_is_needed(): void
    {
        $form = $this->form('<!-- wp:fundkit/email /--><!-- wp:fundkit/submit-button /-->');

        try {
            $this->forms()->update($form, ['status' => 'published']);
            $this->fail('a form without an amount cannot be published');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('needs these blocks', $e->getMessage());
        }
    }
}
