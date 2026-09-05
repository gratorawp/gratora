<?php

declare(strict_types=1);

namespace FundKit\Donors\Privacy;

use FundKit\Donors\ConsentService;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorMetricsService;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Helpers\Money;
use FundKit\Foundation\Identity\IdentityHasher;

/**
 * FundKit answering WordPress's own privacy tools.
 *
 * Tools, Export Personal Data and Erase Personal Data are what a site owner is
 * told to use when a request arrives, and what a data protection officer looks
 * at. Until this, both returned nothing for donors, donations, tickets or
 * anything else in the fleet: the plugin had its own erasure and WordPress
 * could not reach it.
 *
 * Neither half re-implements anything. The eraser finds the donor and calls the
 * same DonorService::redact() the admin button calls, which runs the erasure
 * registry, which is where the add-ons already hook. So a ticket buyer's
 * attendee rows and a tribute's notify address go with the donor here for the
 * same reason they go there.
 *
 * @since 1.0.0
 */
final class WordPressPrivacy
{
    /** WordPress pages these; a donor is one subject, so one page is enough. */
    public const GROUP = 'fundkit-donor';

    /** @since 1.0.0 */
    public function __construct(
        private DonorRepository $donors,
        private DonorService $service,
        private IdentityHasher $hasher,
        private DonorMetricsService $metrics,
        private ConsentService $consents,
    ) {
    }

    /** @since 1.0.0 */
    public function register(): void
    {
        add_filter('wp_privacy_personal_data_exporters', [$this, 'registerExporter']);
        add_filter('wp_privacy_personal_data_erasers', [$this, 'registerEraser']);
    }

    /**
     * @param array<string,mixed> $exporters
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function registerExporter(array $exporters): array
    {
        $exporters['fundkit'] = [
            'exporter_friendly_name' => __('Fundraising Toolkit donations', 'fundraising-toolkit'),
            'callback'               => [$this, 'export'],
        ];

        return $exporters;
    }

    /**
     * @param array<string,mixed> $erasers
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    public function registerEraser(array $erasers): array
    {
        $erasers['fundkit'] = [
            'eraser_friendly_name' => __('Fundraising Toolkit donations', 'fundraising-toolkit'),
            'callback'             => [$this, 'erase'],
        ];

        return $erasers;
    }

    /**
     * @return array{data:list<array<string,mixed>>, done:bool}
     *
     * @since 1.0.0
     */
    public function export(string $email, int $page = 1): array
    {
        $donor = $this->donorFor($email);
        if ($donor === null) {
            return ['data' => [], 'done' => true];
        }

        // Built from the same bundle as the donor's own portal download, so
        // the screen a DPO is told to use answers with what is actually held.
        $bundle = $this->metrics->exportData((int) $donor->id) ?? [];

        $groups = [[
            'group_id'    => self::GROUP,
            'group_label' => __('Donor record', 'fundraising-toolkit'),
            'item_id'     => 'fundkit-donor-' . (int) $donor->id,
            'data'        => $this->donorFields($donor, $bundle['donor'] ?? []),
        ]];

        foreach ($this->rows($bundle, 'donations') as $i => $row) {
            $groups[] = $this->group(
                'fundkit-donation',
                __('Donations', 'fundraising-toolkit'),
                'fundkit-donation-' . ($row['id'] ?? $i),
                $this->donationFields($row)
            );
        }

        foreach ($this->rows($bundle['recurring'] ?? [], 'plans') as $i => $row) {
            $groups[] = $this->group(
                'fundkit-recurring',
                __('Recurring plans', 'fundraising-toolkit'),
                'fundkit-plan-' . ($row['id'] ?? $i),
                $this->planFields($row)
            );
        }

        foreach ($this->rows($bundle, 'receipts') as $i => $row) {
            $groups[] = $this->group(
                'fundkit-receipt',
                __('Receipts', 'fundraising-toolkit'),
                'fundkit-receipt-' . ($row['id'] ?? $i),
                $this->receiptFields($row)
            );
        }

        foreach ($this->rows($bundle, 'events') as $i => $row) {
            $groups[] = $this->group(
                'fundkit-activity',
                __('Activity', 'fundraising-toolkit'),
                'fundkit-event-' . ($row['id'] ?? $i),
                $this->eventFields($row)
            );
        }

        foreach ($this->rows($bundle['consents'] ?? [], 'history') as $i => $row) {
            $groups[] = $this->group(
                'fundkit-consent',
                __('Consents', 'fundraising-toolkit'),
                'fundkit-consent-' . ($row['id'] ?? $i),
                $this->consentFields($row)
            );
        }

        return ['data' => $groups, 'done' => true];
    }

    /**
     * @return array{items_removed:bool, items_retained:bool, messages:list<string>, done:bool}
     *
     * @since 1.0.0
     */
    public function erase(string $email, int $page = 1): array
    {
        $donor = $this->donorFor($email);
        if ($donor === null) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [],
                'done'           => true,
            ];
        }

        if ($donor->redacted_at !== null) {
            return [
                'items_removed'  => false,
                'items_retained' => false,
                'messages'       => [__('This donor was already erased.', 'fundraising-toolkit')],
                'done'           => true,
            ];
        }

        $this->service->redact($donor);

        return [
            'items_removed'  => true,
            // Said plainly rather than left implied: the donations survive with
            // their amounts and dates and no longer name anyone, because a
            // charity's books have to still add up after an erasure.
            'items_retained' => true,
            'messages'       => [
                __('The donor record was erased. Their donations were kept as anonymous records, because the amounts are part of the accounts.', 'fundraising-toolkit'),
            ],
            'done'           => true,
        ];
    }

    private function donorFor(string $email): ?Donor
    {
        $email = trim($email);
        if ($email === '') {
            return null;
        }

        return $this->donors->findByEmailHash($this->hasher->emailHash($email));
    }

    /**
     * What the donor gave us, for the person it belongs to. Internal ids and
     * hashes are left out: they identify nobody outside this database and
     * answer nothing a subject asked.
     *
     * @param array<string,mixed> $exported the bundle's decrypted donor record
     * @return list<array{name:string, value:string}>
     */
    private function donorFields(Donor $donor, array $exported): array
    {
        return $this->fields([
            [__('First name', 'fundraising-toolkit'), $donor->first_name ?? ''],
            [__('Last name', 'fundraising-toolkit'), $donor->last_name ?? ''],
            [__('Company', 'fundraising-toolkit'), $donor->company ?? ''],
            [__('Email', 'fundraising-toolkit'), $exported['email'] ?? ''],
            [__('Phone', 'fundraising-toolkit'), $exported['phone'] ?? ''],
            [__('Address', 'fundraising-toolkit'), $exported['address'] ?? ''],
            [__('Country', 'fundraising-toolkit'), $donor->country ?? ''],
            [__('First seen', 'fundraising-toolkit'), $donor->created_at ?? ''],
            [__('First donation', 'fundraising-toolkit'), $donor->first_donation_at ?? ''],
            [__('Last donation', 'fundraising-toolkit'), $donor->last_donation_at ?? ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function donationFields(array $row): array
    {
        return $this->fields([
            [__('Reference', 'fundraising-toolkit'), $row['reference'] ?? ''],
            [__('Amount', 'fundraising-toolkit'), $this->money($row)],
            [__('Frequency', 'fundraising-toolkit'), $row['frequency'] ?? ''],
            [__('Status', 'fundraising-toolkit'), $row['status'] ?? ''],
            [__('Payment method', 'fundraising-toolkit'), $row['gateway'] ?? ''],
            [__('Paid', 'fundraising-toolkit'), $row['paid_at'] ?? ''],
            [__('Created', 'fundraising-toolkit'), $row['created_at'] ?? ''],
            [__('Test donation', 'fundraising-toolkit'), ! empty($row['is_test']) ? __('Yes', 'fundraising-toolkit') : ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function planFields(array $row): array
    {
        return $this->fields([
            [__('Amount', 'fundraising-toolkit'), $this->money($row)],
            [__('Frequency', 'fundraising-toolkit'), $row['frequency'] ?? ''],
            [__('Status', 'fundraising-toolkit'), $row['status'] ?? ''],
            [__('Payment method', 'fundraising-toolkit'), $row['gateway'] ?? ''],
            [__('Started', 'fundraising-toolkit'), $row['started_at'] ?? ''],
            [__('Next payment', 'fundraising-toolkit'), $row['next_payment_at'] ?? ''],
            [__('Last payment', 'fundraising-toolkit'), $row['last_payment_at'] ?? ''],
            [__('Cancelled', 'fundraising-toolkit'), $row['cancelled_at'] ?? ''],
            [__('Payments made', 'fundraising-toolkit'), (int) ($row['payments_count'] ?? 0) ?: ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function receiptFields(array $row): array
    {
        return $this->fields([
            [__('Receipt number', 'fundraising-toolkit'), $row['receipt_number'] ?? ''],
            [__('Donation', 'fundraising-toolkit'), $row['donation_reference'] ?? ''],
            [__('Issued', 'fundraising-toolkit'), $row['issued_at'] ?? ''],
            [__('Emailed', 'fundraising-toolkit'), $row['sent_to_email_at'] ?? ''],
            [__('Voided', 'fundraising-toolkit'), ! empty($row['voided']) ? __('Yes', 'fundraising-toolkit') : ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function consentFields(array $row): array
    {
        $key     = (string) ($row['purpose'] ?? '');
        $purpose = $this->consents->findPurpose($key);

        return $this->fields([
            [__('Purpose', 'fundraising-toolkit'), $purpose['label'] ?? $key],
            [
                __('Consent', 'fundraising-toolkit'),
                ! empty($row['granted'])
                    ? __('Given', 'fundraising-toolkit')
                    : __('Withdrawn', 'fundraising-toolkit'),
            ],
            [__('Recorded from', 'fundraising-toolkit'), $row['source'] ?? ''],
            [__('Recorded', 'fundraising-toolkit'), $row['occurred_at'] ?? ''],
        ]);
    }

    /**
     * Type and date, not the payload: that is the machine's record of the
     * event, and a subject access request is answered in words.
     *
     * @param array<string,mixed> $row
     */
    private function eventFields(array $row): array
    {
        return $this->fields([
            [__('Event', 'fundraising-toolkit'), $row['type'] ?? ''],
            [__('Amount', 'fundraising-toolkit'), $this->money($row)],
            [__('Recorded', 'fundraising-toolkit'), $row['occurred_at'] ?? ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function money(array $row): string
    {
        $cents = (int) ($row['amount_cents'] ?? 0);

        return $cents === 0 ? '' : Money::format($cents, (string) ($row['currency'] ?? ''));
    }

    /**
     * @param array<string,mixed> $bundle
     * @return list<array<string,mixed>>
     */
    private function rows(array $bundle, string $key): array
    {
        $rows = $bundle[$key] ?? null;

        return is_array($rows) ? array_values(array_filter($rows, 'is_array')) : [];
    }

    /**
     * @param list<array{name:string, value:string}> $data
     * @return array<string,mixed>
     */
    private function group(string $groupId, string $label, string $itemId, array $data): array
    {
        return [
            'group_id'    => $groupId,
            'group_label' => $label,
            'item_id'     => $itemId,
            'data'        => $data,
        ];
    }

    /**
     * Blanks are dropped rather than exported as empty rows: a subject reading
     * the file should see what is held, not a form with gaps.
     *
     * @param list<array{0:string, 1:mixed}> $fields
     * @return list<array{name:string, value:string}>
     */
    private function fields(array $fields): array
    {
        $out = [];
        foreach ($fields as [$name, $value]) {
            $value = (string) $value;
            if ($value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }
}
