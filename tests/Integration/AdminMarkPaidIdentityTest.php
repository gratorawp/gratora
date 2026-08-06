<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donations\Donation;
use Dono\Donations\DonationRepository;
use Dono\Foundation\Plugin;
use WP_REST_Request;

/**
 * What "mark as paid" records about how the money arrived.
 *
 * The action exists for two different situations that look the same from the
 * button: a cheque an admin banked, and a card donation the site never heard
 * back about because the webhook did not arrive. They are not the same event.
 * Stamping every one of them `offline` with an invented transaction id
 * mislabelled card payments and put an id in the record that exists nowhere
 * else, so reconciling the site against a Stripe payout found nothing to match.
 */
final class AdminMarkPaidIdentityTest extends IntegrationTestCase
{
    private function reload(string $reference): Donation
    {
        return Plugin::instance()->container->get(DonationRepository::class)->findByReference($reference);
    }

    /** @param array<string,mixed> $extra */
    private function createDonation(string $gateway, array $extra = []): Donation
    {
        $request = new WP_REST_Request('POST', '/dono/v1/donations');
        $request->set_header('content-type', 'application/json');
        $request->set_body((string) wp_json_encode(array_merge([
            'email'        => 'sarah@example.com',
            'amount_cents' => 5000,
            'currency'     => 'EUR',
            'gateway'      => $gateway,
            'profile'      => ['first_name' => 'Sarah', 'last_name' => 'Muller'],
        ], $extra)));

        return $this->reload((string) rest_do_request($request)->get_data()['reference']);
    }

    private function markPaid(Donation $donation): void
    {
        rest_do_request(new WP_REST_Request(
            'POST',
            '/dono/v1/admin/donations/' . $donation->reference . '/mark-paid'
        ));
    }

    /** A cheque really did arrive outside any gateway, so it is marked as such. */
    public function test_an_offline_donation_still_gets_an_offline_marker(): void
    {
        $donation = $this->createDonation('offline');

        $this->markPaid($donation);
        $row = $this->reload((string) $donation->reference);

        $this->assertSame('paid', $row->status);
        $this->assertSame('offline', $row->payment_method);
        $this->assertStringStartsWith('offline-', (string) $row->gateway_txn_id);
    }

    /**
     * The money moved through Stripe whether or not the webhook told us. The
     * record has to keep saying so.
     */
    public function test_a_gateway_donation_is_not_relabelled_offline(): void
    {
        $donation = $this->createDonation('offline');
        // Stand in for a card donation left pending by a webhook that never came.
        $donation->gateway           = 'stripe';
        $donation->gateway_intent_id = 'pi_3U1S7EI8Lgx2tL3317eG7wog';
        $donation->save();

        $this->markPaid($donation);
        $row = $this->reload((string) $donation->reference);

        $this->assertSame('paid', $row->status);
        $this->assertNotSame('offline', $row->payment_method);
        $this->assertStringStartsNotWith('offline-', (string) $row->gateway_txn_id);
    }

    /** With no settlement id of its own, the intent is what identifies it. */
    public function test_the_intent_identifies_a_hand_confirmed_gateway_donation(): void
    {
        $donation = $this->createDonation('offline');
        $donation->gateway           = 'stripe';
        $donation->gateway_intent_id = 'pi_3U1S7EI8Lgx2tL3317eG7wog';
        $donation->save();

        $this->markPaid($donation);

        $this->assertSame(
            'pi_3U1S7EI8Lgx2tL3317eG7wog',
            (string) $this->reload((string) $donation->reference)->gateway_txn_id
        );
    }

    /** A settlement id the gateway already gave us is never overwritten. */
    public function test_an_existing_gateway_transaction_id_survives(): void
    {
        $donation = $this->createDonation('offline');
        $donation->gateway           = 'stripe';
        $donation->gateway_intent_id = 'pi_3U1S7EI8Lgx2tL3317eG7wog';
        $donation->gateway_txn_id    = 'ch_3U1S7EI8Lgx2tL3317eG7wog';
        $donation->save();

        $this->markPaid($donation);

        $this->assertSame(
            'ch_3U1S7EI8Lgx2tL3317eG7wog',
            (string) $this->reload((string) $donation->reference)->gateway_txn_id
        );
    }
}
