<?php

declare(strict_types=1);

namespace Dono\Donations;

use Dono\Foundation\Crypto\Crypto;
use Dono\Foundation\Time\Clock;

/**
 * Persistence and decryption for donation tribute records.
 *
 * @version 1.0.0
 */
final class DonationTributeRepository
{
    public function __construct(
        private Crypto $crypto,
        private Clock $clock,
    ) {
    }

    /** @param array{type:string,name:string,notify_email?:?string,message?:?string,convert_to_annual?:bool} $data */
    public function persist(Donation $donation, array $data): DonationTribute
    {
        $existing = DonationTribute::query()->find('donation_id', $donation->id);
        $row = $existing ?: DonationTribute::make();

        $row->donation_id = $donation->id;
        $row->donor_id    = $donation->donor_id;
        $row->campaign_id = $donation->campaign_id;
        $row->type        = $data['type'];
        $row->name        = $data['name'];
        $row->notify_email_encrypted = ! empty($data['notify_email'])
            ? $this->crypto->encrypt((string) $data['notify_email'])
            : null;
        $row->message_encrypted = ! empty($data['message'])
            ? $this->crypto->encrypt((string) $data['message'])
            : null;
        $row->convert_to_annual  = ! empty($data['convert_to_annual']);
        $row->annual_anchor_date = $row->convert_to_annual
            ? $this->clock->now()->format('Y-m-d')
            : null;
        if (! $existing) {
            $row->created_at = $this->clock->now()->format('Y-m-d H:i:s');
        }
        $row->save();

        if ($row->convert_to_annual) {
            do_action('dono.tribute.convert_to_annual_requested', $row, $donation);
        }

        return $row;
    }

    public function forDonation(int $donationId): ?DonationTribute
    {
        return DonationTribute::query()->find('donation_id', $donationId);
    }

    public function decryptedNotifyEmail(DonationTribute $row): ?string
    {
        return $row->notify_email_encrypted ? $this->crypto->decrypt($row->notify_email_encrypted) : null;
    }

    public function decryptedMessage(DonationTribute $row): ?string
    {
        return $row->message_encrypted ? $this->crypto->decrypt($row->message_encrypted) : null;
    }
}
