<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Campaigns\Campaign;
use Dono\Funds\Fund;
use WP_REST_Request;

final class DonationFlowTest extends IntegrationTestCase
{
    public function test_post_donations_creates_donor_and_pending_donation(): void
    {
        $res = $this->postDonation([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Müller', 'country' => 'DE'],
            'source_attribution' => ['utm_source' => 'integration_test'],
        ]);

        $this->assertSame(201, $res->get_status());
        $data = $res->get_data();
        $this->assertMatchesRegularExpression('/^DONO-\d{4}-\d{5}$/', $data['reference']);
        $this->assertSame('pending', $data['status']);
        $this->assertSame('offline', $data['gateway']);
        $this->assertStringStartsWith('offline_', $data['intent_id']);

        // Donor row materialized with hashed email + Sarah's profile fields.
        $donor = self::$wpdb->get_row(
            "SELECT email_hash, first_name, last_name, country FROM " . self::$prefix . "dono_donors LIMIT 1"
        );
        $this->assertSame(64,        strlen($donor->email_hash));
        $this->assertSame('Sarah',   $donor->first_name);
        $this->assertSame('Müller',  $donor->last_name);
        $this->assertSame('DE',      $donor->country);

        // Donation row in pending status with the gateway intent populated.
        $donation = self::$wpdb->get_row(self::$wpdb->prepare(
            "SELECT status, amount_cents, currency, gateway_intent_id, country FROM " . self::$prefix . "dono_donations WHERE reference = %s",
            $data['reference']
        ));
        $this->assertSame('pending', $donation->status);
        $this->assertSame(5000,      (int) $donation->amount_cents);
        $this->assertSame('EUR',     $donation->currency);
        $this->assertSame('DE',      $donation->country);
        $this->assertNotEmpty($donation->gateway_intent_id);

        // Event recorded.
        $events = self::$wpdb->get_results(
            "SELECT type FROM " . self::$prefix . "dono_events ORDER BY id"
        );
        $this->assertContains('donation.intent_created', array_column($events, 'type'));
    }

    public function test_confirm_moves_donation_to_paid_and_emits_completed_event(): void
    {
        $reference = $this->postDonation([
            'email' => 'sarah@example.com', 'amount_cents' => 5000, 'currency' => 'EUR', 'gateway' => 'offline',
        ])->get_data()['reference'];

        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $res = rest_do_request($req);

        $this->assertSame(200, $res->get_status());
        $data = $res->get_data();
        $this->assertSame('paid', $data['status']);
        $this->assertNotEmpty($data['paid_at']);
        $this->assertNotEmpty($data['gateway_txn_id']);

        $types = array_column(
            self::$wpdb->get_results("SELECT type FROM " . self::$prefix . "dono_events ORDER BY id"),
            'type'
        );
        $this->assertContains('donation.intent_created', $types);
        $this->assertContains('donation.completed',     $types);
    }

    public function test_confirm_updates_fund_raised_cents(): void
    {
        $fund = $this->makeFund('general', 'General', true, true);

        $reference = $this->postDonation([
            'email' => 'r@x.com', 'amount_cents' => 5000, 'currency' => 'EUR', 'gateway' => 'offline',
        ])->get_data()['reference'];
        $this->assertSame((int) $fund->id, $this->fundIdOf($reference));

        $req = new WP_REST_Request('POST', "/dono/v1/donations/{$reference}/confirm");
        $req->set_header('content-type', 'application/json');
        $req->set_body('{}');
        $this->assertSame(200, rest_do_request($req)->get_status());

        $raised = (int) self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT raised_cents FROM " . self::$prefix . "dono_funds WHERE id = %d",
            $fund->id
        ));
        $this->assertSame(5000, $raised, 'Fund raised_cents reflects the paid donation');
    }

    public function test_repeat_donor_does_not_create_duplicate_donor_row(): void
    {
        $this->postDonation(['email' => 's@x.com', 'amount_cents' => 1000, 'currency' => 'EUR', 'gateway' => 'offline']);
        $this->postDonation(['email' => 'S@X.COM', 'amount_cents' => 2500, 'currency' => 'EUR', 'gateway' => 'offline']);

        $donorCount = (int) self::$wpdb->get_var("SELECT COUNT(*) FROM " . self::$prefix . "dono_donors");
        $donationCount = (int) self::$wpdb->get_var("SELECT COUNT(*) FROM " . self::$prefix . "dono_donations");

        $this->assertSame(1, $donorCount, 'Email normalization → same donor for case/whitespace variants');
        $this->assertSame(2, $donationCount);
    }

    /**
     * Structural validation (email format, amount minimum, currency pattern) is
     * enforced by the JSON-Schema arg specs and surfaces as WP's standard
     * `rest_invalid_param`. The dynamic "gateway must be registered" check
     * still lives in the handler - no static enum can express that.
     */
    public function test_post_donations_rejects_bad_payloads(): void
    {
        $bad = [
            // Schema-level rejections (return WP's generic param error).
            ['payload' => ['email' => 'nope',    'amount_cents' => 5000, 'currency' => 'EUR', 'gateway' => 'offline'], 'code' => 'rest_invalid_param'],
            ['payload' => ['email' => 'x@y.com', 'amount_cents' => 0,    'currency' => 'EUR', 'gateway' => 'offline'], 'code' => 'rest_invalid_param'],
            ['payload' => ['email' => 'x@y.com', 'amount_cents' => 5000, 'currency' => 'EU',  'gateway' => 'offline'], 'code' => 'rest_invalid_param'],
            // Domain-level rejection (gateway list is dynamic).
            ['payload' => ['email' => 'x@y.com', 'amount_cents' => 5000, 'currency' => 'EUR', 'gateway' => 'nope'],   'code' => 'dono_invalid_gateway'],
        ];

        foreach ($bad as $case) {
            $res = $this->postDonation($case['payload']);
            $this->assertSame(400, $res->get_status());
            $this->assertSame($case['code'], $res->get_data()['code']);
        }
    }

    public function test_post_donations_rejects_missing_required_fields(): void
    {
        // Missing email - schema requires it.
        $res = $this->postDonation(['amount_cents' => 1000, 'currency' => 'EUR', 'gateway' => 'offline']);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_missing_callback_param', $res->get_data()['code']);
    }

    public function test_public_payload_cannot_set_fundraiser_attribution(): void
    {
        // fundraiser_id/team_id are derived server-side from a signed context;
        // a raw value in the public payload must be stripped, never persisted,
        // or an attacker could inflate any fundraiser's totals + leaderboard.
        $ref = $this->postDonation([
            'email'   => 'attr@x.com',
            'amount_cents' => 1000,
            'currency' => 'EUR',
            'gateway' => 'offline',
            'extra'   => [ 'fundraiser_id' => 999, 'fundraiser_team_id' => 888 ],
        ])->get_data()['reference'];

        $this->assertSame(0, $this->columnOf($ref, 'fundraiser_id'), 'raw fundraiser_id must not be persisted');
        $this->assertSame(0, $this->columnOf($ref, 'fundraiser_team_id'), 'raw fundraiser_team_id must not be persisted');
    }

    public function test_note_to_org_is_private_unless_the_donor_opts_in(): void
    {
        // note_to_org is a note to the organisation; it only appears on the
        // public supporter wall / recent donations when the donor ticks the
        // opt-in, so the stored note_public flag must reflect that choice.
        $public = $this->postDonation([
            'email'        => 'wall@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'note_to_org'  => 'Proud to support this!',
            'note_public'  => true,
        ])->get_data()['reference'];
        $this->assertSame(1, $this->columnOf($public, 'note_public'), 'opted-in message is marked public');

        $private = $this->postDonation([
            'email'        => 'private@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'note_to_org'  => 'In memory of my father - please keep private',
        ])->get_data()['reference'];
        $this->assertSame(0, $this->columnOf($private, 'note_public'), 'note stays private by default');
    }

    public function test_post_donations_rejects_currency_off_the_org_allow_list(): void
    {
        // The base fixture accepts USD/EUR/GBP; JPY is a valid ISO code but not
        // on the org's accepted list, so the create endpoint must reject it.
        $res = $this->postDonation([
            'email'        => 'jpy@example.com',
            'amount_cents' => 100000,
            'currency'     => 'JPY',
            'gateway'      => 'offline',
        ]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_unsupported_currency', $res->get_data()['code'] ?? null);
    }

    public function test_zero_decimal_currency_rejects_fractional_amounts(): void
    {
        // JPY has no sub-unit; internal storage is always major x 100, so an
        // amount that is not a whole yen (5050 -> Y50.50) cannot be represented
        // and would mischarge at the gateway. Make JPY the base, then the create
        // endpoint must reject the fractional amount but allow the whole-yen one.
        update_option('dono_currency_locale', [
            'default_currency'     => 'JPY',
            'supported_currencies' => ['JPY'],
        ]);

        $bad = $this->postDonation([
            'email'        => 'yen@example.com',
            'amount_cents' => 5050,
            'currency'     => 'JPY',
            'gateway'      => 'offline',
        ]);
        $this->assertSame(400, $bad->get_status());
        $this->assertSame('dono_invalid_amount', $bad->get_data()['code'] ?? null);

        $ok = $this->postDonation([
            'email'        => 'yen2@example.com',
            'amount_cents' => 5000,
            'currency'     => 'JPY',
            'gateway'      => 'offline',
        ]);
        $this->assertSame(201, $ok->get_status(), 'a whole-yen amount passes the precision guard');
    }

    public function test_post_donations_accepts_the_base_currency_even_when_not_in_supported_list(): void
    {
        // An org can switch its base currency without re-adding it to the
        // supported list; the base must always be accepted regardless.
        update_option('dono_currency_locale', [
            'default_currency'     => 'USD',
            'supported_currencies' => ['EUR', 'GBP'],
        ]);

        $res = $this->postDonation([
            'email'        => 'base@example.com',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
        ]);

        $this->assertNotSame(400, $res->get_status(), 'base-currency donation must not be rejected');
        $this->assertNotSame('', (string) ($res->get_data()['reference'] ?? ''), 'a base-currency donation was created');
    }

    public function test_post_donations_rejects_unknown_frequency_value(): void
    {
        $res = $this->postDonation([
            'email'        => 'x@y.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'frequency'    => 'fortnightly', // not in enum
        ]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_invalid_param', $res->get_data()['code']);
    }

    public function test_post_donations_rejects_bad_country_pattern_in_profile(): void
    {
        $res = $this->postDonation([
            'email'        => 'x@y.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => ['country' => 'Germany'], // schema wants ISO-2
        ]);
        $this->assertSame(400, $res->get_status());
        $this->assertSame('rest_invalid_param', $res->get_data()['code']);
    }

    /**
     * The form runtime in assets/donation-form/runtime.jsx builds a specific
     * payload shape from the block-based form HTML. This test posts that exact
     * shape - any future drift between the runtime payload and the REST
     * controller's expectations will fail here.
     */
    public function test_payload_built_by_form_runtime_is_accepted(): void
    {
        // Mirrors the payload built by assets/donation-form/runtime.jsx
        $payload = [
            'email'        => 'donor@example.com',
            'amount_cents' => 2500,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => [
                'first_name' => 'Sarah',
                'last_name'  => 'Donor',
                'country'    => 'DE',
            ],
        ];

        $res = $this->postDonation($payload);
        $this->assertSame(201, $res->get_status(), 'Form runtime payload should be accepted.');
        $data = $res->get_data();
        $this->assertSame('pending', $data['status']);
        $this->assertSame('offline',  $data['gateway']);
    }

    /** Form sometimes omits optional profile fields - they come through as undefined → JSON skip. */
    public function test_runtime_payload_works_with_only_email_and_amount(): void
    {
        $res = $this->postDonation([
            'email'        => 'minimal@example.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
            'profile'      => new \stdClass(), // empty object - same as JS skipping the keys
        ]);

        $this->assertSame(201, $res->get_status());
    }

    public function test_explicit_active_fund_is_attributed(): void
    {
        $fund = $this->makeFund('arts', 'Arts', true);
        $ref  = $this->postDonation([
            'email' => 'a@x.com', 'amount_cents' => 1000, 'currency' => 'EUR',
            'gateway' => 'offline', 'fund_id' => $fund->id,
        ])->get_data()['reference'];
        $this->assertSame((int) $fund->id, $this->fundIdOf($ref));
    }

    public function test_no_fund_falls_back_to_org_default(): void
    {
        $def = $this->makeFund('general', 'General', true, true);
        $ref = $this->postDonation([
            'email' => 'b@x.com', 'amount_cents' => 1000, 'currency' => 'EUR', 'gateway' => 'offline',
        ])->get_data()['reference'];
        $this->assertSame((int) $def->id, $this->fundIdOf($ref));
    }

    public function test_inactive_submitted_fund_falls_through_to_default(): void
    {
        $inactive = $this->makeFund('old', 'Old', false);
        $def      = $this->makeFund('general', 'General', true, true);
        $ref = $this->postDonation([
            'email' => 'c@x.com', 'amount_cents' => 1000, 'currency' => 'EUR',
            'gateway' => 'offline', 'fund_id' => $inactive->id,
        ])->get_data()['reference'];
        $this->assertSame((int) $def->id, $this->fundIdOf($ref));
    }

    public function test_form_default_fund_beats_org_default(): void
    {
        $this->makeFund('general', 'General', true, true);
        $formFund = $this->makeFund('events', 'Events', true);
        $form = \Dono\Forms\Form::make();
        $form->title           = 'Tickets';
        $form->slug            = 'tickets-' . uniqid();
        $form->blocks          = '';
        $form->default_fund_id = (int) $formFund->id;
        $form->status          = 'published';
        $form->created_at      = gmdate('Y-m-d H:i:s');
        $form->updated_at      = $form->created_at;
        $form->save();

        $ref = $this->postDonation([
            'email' => 'd@x.com', 'amount_cents' => 1000, 'currency' => 'EUR',
            'gateway' => 'offline', 'form_id' => $form->id,
        ])->get_data()['reference'];
        $this->assertSame((int) $formFund->id, $this->fundIdOf($ref));
    }

    public function test_campaign_default_fund_used_when_no_form_default(): void
    {
        $this->makeFund('general', 'General', true, true);
        $campFund = $this->makeFund('campaignfund', 'Campaign Fund', true);
        $campaign = Campaign::make();
        $campaign->title           = 'C';
        $campaign->slug            = 'c-' . uniqid();
        $campaign->default_fund_id = (int) $campFund->id;
        $campaign->created_at      = gmdate('Y-m-d H:i:s');
        $campaign->updated_at      = $campaign->created_at;
        $campaign->save();

        $ref = $this->postDonation([
            'email' => 'e@x.com', 'amount_cents' => 1000, 'currency' => 'EUR',
            'gateway' => 'offline', 'campaign_id' => $campaign->id,
        ])->get_data()['reference'];
        $this->assertSame((int) $campFund->id, $this->fundIdOf($ref));
    }

    public function test_unbacked_campaign_id_is_not_attributed(): void
    {
        // No form, body names a campaign that does not exist: the donation must
        // not credit it (crafted payloads can't inflate arbitrary campaigns).
        $ref = $this->postDonation([
            'email' => 'spoof@x.com', 'amount_cents' => 1000, 'currency' => 'EUR',
            'gateway' => 'offline', 'campaign_id' => 999999,
        ])->get_data()['reference'];

        $this->assertSame(0, $this->columnOf($ref, 'campaign_id'),
            'a non-existent campaign_id is dropped, not stored');
    }

    public function test_campaign_bound_form_pins_attribution_ignoring_body(): void
    {
        $real = Campaign::make();
        $real->title = 'Real'; $real->slug = 'real-' . uniqid();
        $real->status = 'published';
        $real->created_at = gmdate('Y-m-d H:i:s'); $real->updated_at = $real->created_at;
        $real->save();

        $form = \Dono\Forms\Form::make();
        $form->title = 'Bound'; $form->slug = 'bound-' . uniqid();
        $form->blocks = ''; $form->status = 'published';
        $form->campaign_id = (int) $real->id;
        $form->created_at = gmdate('Y-m-d H:i:s'); $form->updated_at = $form->created_at;
        $form->save();

        // Body tries to credit a different campaign; the form's binding wins.
        $ref = $this->postDonation([
            'email' => 'pinned@x.com', 'amount_cents' => 1000, 'currency' => 'EUR',
            'gateway' => 'offline', 'form_id' => $form->id, 'campaign_id' => 888888,
        ])->get_data()['reference'];

        $this->assertSame((int) $real->id, $this->columnOf($ref, 'campaign_id'),
            'a campaign-bound form is authoritative over the body campaign_id');
    }

    public function test_fee_covered_cents_is_capped_at_amount(): void
    {
        $ref = $this->postDonation([
            'email' => 'fee@x.com', 'amount_cents' => 5000, 'currency' => 'EUR',
            'gateway' => 'offline', 'fee_covered_cents' => 999999,
        ])->get_data()['reference'];

        $this->assertSame(5000, $this->columnOf($ref, 'fee_covered_cents'),
            'fee_covered_cents is capped at the donation amount');
    }

    private function makeFund(string $code, string $name, bool $active, bool $default = false): Fund
    {
        $f = Fund::make();
        $f->code       = $code;
        $f->name       = $name;
        $f->is_active  = $active;
        $f->is_default = $default;
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = $f->created_at;
        $f->save();
        return $f;
    }

    private function fundIdOf(string $reference): int
    {
        return $this->columnOf($reference, 'fund_id');
    }

    private function columnOf(string $reference, string $column): int
    {
        // $column is a fixed test-supplied identifier, never user input.
        return (int) self::$wpdb->get_var(self::$wpdb->prepare(
            "SELECT {$column} FROM " . self::$prefix . "dono_donations WHERE reference = %s",
            $reference
        ));
    }

    public function test_post_donation_rejects_a_gateway_the_form_does_not_offer(): void
    {
        // Org-disable offline; with Stripe unconnected nothing is available,
        // so a submit naming offline is rejected by server-side validation.
        update_option('dono_gateway_config', ['offline' => ['enabled' => false]]);

        $res = $this->postDonation([
            'email'        => 'blocked@example.com',
            'amount_cents' => 1000,
            'currency'     => 'EUR',
            'gateway'      => 'offline',
        ]);

        $this->assertSame(400, $res->get_status());
        $this->assertSame('dono_gateway_not_allowed', $res->get_data()['code'] ?? null);

        delete_option('dono_gateway_config');
    }

    private function postDonation(array $body): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/dono/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body(json_encode($body));
        return rest_do_request($req);
    }
}
