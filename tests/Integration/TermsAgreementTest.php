<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Consent;
use Dono\Forms\Blocks\TermsBlock;
use Dono\Forms\Form;
use Dono\Forms\FormSubmissionValidator;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * Agreement to the organization's terms, and the record it leaves.
 *
 * The checkbox is the cheap half: a tick the client could skip is not
 * agreement, so the refusal is enforced server-side. What has value afterwards
 * is the record, and only if it says which revision of the text was accepted.
 * Terms get edited, and "they agreed to the terms" is worth nothing when the
 * terms today are not the terms they saw.
 */
final class TermsAgreementTest extends IntegrationTestCase
{
    private const TERMS = 'Gifts are accepted at the discretion of the trustees.';

    private function campaignId(): int
    {
        $service  = Plugin::instance()->container->get(\Dono\Campaigns\CampaignService::class);
        $campaign = $service->create([
            'title'      => 'Terms probe campaign',
            'goal_type'  => 'amount',
            'goal_cents' => 100000,
        ]);
        $service->update($campaign, ['status' => 'published']);

        return (int) $campaign->id;
    }

    private function formWith(string $terms, string $linkUrl = ''): Form
    {
        $attrs = ['terms' => $terms];
        if ($linkUrl !== '') $attrs['linkUrl'] = $linkUrl;

        $form = Form::make();
        $form->campaign_id = $this->campaignId();
        $form->slug        = 'terms-' . bin2hex(random_bytes(3));
        $form->title       = 'Terms probe';
        $form->status      = 'published';
        $form->blocks      = '<!-- wp:dono/donation-amount /-->'
            . '<!-- wp:dono/terms ' . wp_json_encode($attrs) . ' /-->';
        $form->settings    = [];
        $form->save();

        return $form;
    }

    /** @param array<string,mixed> $body */
    private function donate(Form $form, array $body): \WP_REST_Response|\WP_Error
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body + [
            'email'        => 'terms-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'gateway'      => 'offline',
            'currency'     => (string) \Dono\Campaigns\Campaign::query()->find('id', (int) $form->campaign_id)->currency,
            'campaign_id'  => (int) $form->campaign_id,
            'form_id'      => (int) $form->id,
            '_ft'          => Plugin::instance()->container
                ->get(\Dono\Donations\AntiSpamGuard::class)
                ->mintFormToken((int) $form->id),
        ]));

        return rest_do_request($req);
    }

    public function test_a_donation_without_agreement_is_refused(): void
    {
        $res = $this->donate($this->formWith(self::TERMS), []);

        $this->assertSame(400, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertStringContainsString(
            'agree to the terms',
            (string) ($res->get_data()['message'] ?? ''),
            'refused for the terms, not for something else'
        );
        $this->assertSame(0, Consent::query()->where('purpose', TermsBlock::PURPOSE)->count());
    }

    public function test_agreeing_lets_the_donation_through_and_is_recorded(): void
    {
        $form = $this->formWith(self::TERMS);

        $res = $this->donate($form, ['consents' => [TermsBlock::PURPOSE => true]]);
        $this->assertLessThan(400, $res->get_status(), (string) wp_json_encode($res->get_data()));

        $row = Consent::query()->where('purpose', TermsBlock::PURPOSE)->get();
        $this->assertNotNull($row, 'the agreement is recorded');
        $this->assertTrue((bool) $row->granted);
        $this->assertSame('donation', $row->source);
        $this->assertSame((int) $form->id, (int) $row->source_form_id);
        $this->assertNotNull($row->source_donation_id, 'tied to the donation it was given with');
    }

    /**
     * The point of the record. Editing the terms must not rewrite what somebody
     * already agreed to, so the row carries the revision as it stood.
     */
    public function test_the_record_says_which_revision_was_agreed_to(): void
    {
        $form = $this->formWith(self::TERMS);
        $this->donate($form, ['consents' => [TermsBlock::PURPOSE => true]]);

        $recorded = (int) Consent::query()->where('purpose', TermsBlock::PURPOSE)->get()->purpose_version;
        $this->assertSame(TermsBlock::revisionOf(self::TERMS), $recorded);

        // The org rewrites its policy. The old row still says what it said.
        $form->blocks = str_replace(self::TERMS, 'Entirely different terms.', (string) $form->blocks);
        $form->save();

        $this->assertNotSame(
            $recorded,
            FormSubmissionValidator::termsRevision((string) $form->blocks),
            'a changed policy is a changed revision'
        );
        $this->assertSame(
            $recorded,
            (int) Consent::query()->where('purpose', TermsBlock::PURPOSE)->get()->purpose_version,
            'and the earlier agreement is untouched'
        );
    }

    /** Reflowing a paragraph is not a new policy. */
    public function test_whitespace_alone_is_not_a_new_revision(): void
    {
        $this->assertSame(
            TermsBlock::revisionOf("Gifts are accepted\nat the discretion of the trustees."),
            TermsBlock::revisionOf('Gifts   are accepted at the   discretion of the trustees.')
        );
    }

    /** Linking to a policy page counts as having terms, and versions with them. */
    public function test_a_link_is_terms_too(): void
    {
        $form = $this->formWith('', 'https://example.test/terms');

        $this->assertNotNull(FormSubmissionValidator::termsRevision((string) $form->blocks));

        $res = $this->donate($form, []);
        $this->assertSame(400, $res->get_status());
        $this->assertStringContainsString('agree to the terms', (string) ($res->get_data()['message'] ?? ''));
    }

    /**
     * A block dropped in and never filled in asks the donor for nothing, so it
     * must not block them either. Enforcing an empty agreement would take a form
     * offline the moment the block was added.
     */
    public function test_an_unconfigured_block_blocks_nothing(): void
    {
        $form = $this->formWith('');

        $this->assertNull(FormSubmissionValidator::termsRevision((string) $form->blocks));
        $this->assertLessThan(400, $this->donate($form, [])->get_status());
    }

    /** A form with no terms block is unaffected. */
    public function test_a_form_without_the_block_is_untouched(): void
    {
        $form = Form::make();
        $form->campaign_id = $this->campaignId();
        $form->slug        = 'no-terms-' . bin2hex(random_bytes(3));
        $form->title       = 'No terms';
        $form->status      = 'published';
        $form->blocks      = '<!-- wp:dono/donation-amount /-->';
        $form->settings    = [];
        $form->save();

        $this->assertLessThan(400, $this->donate($form, [])->get_status());
    }

    /**
     * Only a form that asks for it can record it. Otherwise any caller could
     * post a terms consent against a form that never showed any.
     */
    public function test_an_agreement_is_not_recorded_for_a_form_that_asks_for_none(): void
    {
        $form = Form::make();
        $form->campaign_id = $this->campaignId();
        $form->slug        = 'unasked-' . bin2hex(random_bytes(3));
        $form->title       = 'Unasked';
        $form->status      = 'published';
        $form->blocks      = '<!-- wp:dono/donation-amount /-->';
        $form->settings    = [];
        $form->save();

        $this->donate($form, ['consents' => [TermsBlock::PURPOSE => true]]);

        $this->assertSame(0, Consent::query()->where('purpose', TermsBlock::PURPOSE)->count());
    }
}
