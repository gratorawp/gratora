<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donors\Donor;
use Dono\Forms\Blocks\GiftAidBlock;
use Dono\Foundation\Plugin;
use Dono\GiftAid\GiftAidDeclaration;
use Dono\GiftAid\GiftAidDeclarations;
use Dono\GiftAid\GiftAidEligibility;
use WP_REST_Request;

/**
 * UK Gift Aid: a charity reclaims 25p per pound from HMRC on an eligible gift.
 *
 * The stamp on the donation is a claim to a tax authority, so these pin the
 * cases where it must NOT be set as hard as the case where it must.
 */
final class GiftAidTest extends IntegrationTestCase
{
    private string $email;

    protected function setUp(): void
    {
        parent::setUp();
        // AntiSpamGuard caps donations per email per hour, and its transients
        // outlive the per-test transaction. One donor per test, several gifts
        // within it, is both under the cap and properly isolated.
        $this->email = 'uk-' . uniqid() . '@example.test';
        update_option('dono_gift_aid', ['enabled' => true, 'charity_reference' => 'AB12345']);
        update_option('dono_currency_locale', [
            'default_currency'     => 'GBP',
            'supported_currencies' => ['GBP', 'USD'],
        ]);
    }

    protected function tearDown(): void
    {
        delete_option('dono_gift_aid');
        parent::tearDown();
    }

    /** @param array<string,mixed> $body */
    private function donate(array $body = []): array
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode($body + [
            'email'        => $this->email,
            'amount_cents' => 5000,
            'currency'     => 'GBP',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ada', 'last_name' => 'Lovelace'],
        ]));

        return (array) rest_do_request($req)->get_data();
    }

    private function donationFor(array $res): Donation
    {
        return Donation::query()->find('reference', (string) $res['reference']);
    }

    public function test_a_ticked_declaration_makes_the_gift_claimable(): void
    {
        $donation = $this->donationFor($this->donate(['gift_aid' => true]));

        $this->assertTrue((bool) $donation->gift_aid);
    }

    public function test_an_unticked_box_claims_nothing(): void
    {
        $donation = $this->donationFor($this->donate());

        $this->assertFalse((bool) $donation->gift_aid);
    }

    /** The declaration is evidence HMRC can ask for, so it is written down. */
    public function test_the_declaration_is_recorded_with_the_wording_shown(): void
    {
        $this->donate(['gift_aid' => true]);

        $row = GiftAidDeclaration::query()->orderBy('id', 'DESC')->limit(1)->get();
        $this->assertNotNull($row);
        $this->assertTrue((bool) $row->declared);
        $this->assertSame('form', $row->source);
        $this->assertSame(GiftAidBlock::defaultStatement(), $row->statement);
        $this->assertNotNull($row->source_donation_id, 'the gift it was made on');
    }

    /**
     * The enduring declaration is the point: a donor who ticked the box once
     * should not have to tick it again.
     */
    public function test_a_later_gift_is_claimable_without_ticking_again(): void
    {
        $this->donate(['gift_aid' => true]);
        $second = $this->donationFor($this->donate());

        $this->assertTrue((bool) $second->gift_aid);
    }

    public function test_withdrawing_stops_future_gifts_being_claimed(): void
    {
        $first = $this->donationFor($this->donate(['gift_aid' => true]));

        $declarations = Plugin::instance()->container->get(GiftAidDeclarations::class);
        $declarations->record((int) $first->donor_id, false, ['source' => 'portal']);

        $second = $this->donationFor($this->donate());

        $this->assertFalse((bool) $second->gift_aid);
        $this->assertTrue(
            (bool) Donation::query()->find('id', (int) $first->id)->gift_aid,
            'but the gift already claimed stays claimed: the declaration was live when it was made'
        );
    }

    /** Gift Aid is relief on UK income tax, so the gift is in sterling. */
    public function test_a_non_sterling_gift_is_not_claimable(): void
    {
        $donation = $this->donationFor($this->donate(['currency' => 'USD', 'gift_aid' => true]));

        $this->assertFalse((bool) $donation->gift_aid);
    }

    public function test_a_test_mode_gift_is_not_claimable(): void
    {
        update_option('dono_gateway_config', ['test_mode' => true]);

        $donation = $this->donationFor($this->donate(['gift_aid' => true]));

        $this->assertFalse((bool) $donation->gift_aid, 'a test donation is not money, so it is not a claim');

        delete_option('dono_gateway_config');
    }

    /** A company gets corporation tax relief instead, not Gift Aid. */
    public function test_an_organisation_cannot_gift_aid(): void
    {
        $donation = $this->donationFor($this->donate(['gift_aid' => true]));
        $this->assertTrue((bool) $donation->gift_aid, 'claimable as an individual');

        Donor::query()->where('id', (int) $donation->donor_id)->update(['donor_type' => 'organization']);
        $donor = Donor::query()->find('id', (int) $donation->donor_id);

        $eligibility = Plugin::instance()->container->get(GiftAidEligibility::class);
        $this->assertFalse($eligibility->qualifies($donation, $donor, true));
    }

    public function test_nothing_is_claimed_while_the_feature_is_off(): void
    {
        update_option('dono_gift_aid', ['enabled' => false]);

        $donation = $this->donationFor($this->donate(['gift_aid' => true]));

        $this->assertFalse((bool) $donation->gift_aid);
    }

    /**
     * A declaration on a form that cannot claim is a promise the charity will
     * not keep, and the donor has no way to tell.
     */
    public function test_the_block_renders_nothing_while_the_feature_is_off(): void
    {
        $block = new GiftAidBlock();

        $this->assertNotSame('', $block->render([], ''));

        update_option('dono_gift_aid', ['enabled' => false]);
        $this->assertSame('', $block->render([], ''));
    }

    public function test_the_statutory_wording_is_the_default(): void
    {
        $this->assertStringContainsString('UK taxpayer', GiftAidBlock::statement());
        $this->assertStringContainsString('past 4 years', GiftAidBlock::statement());

        update_option('dono_gift_aid', ['enabled' => true, 'statement' => 'Our own wording.']);
        $this->assertSame('Our own wording.', GiftAidBlock::statement());
    }
}
