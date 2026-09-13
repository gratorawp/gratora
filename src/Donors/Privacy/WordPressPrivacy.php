<?php

declare(strict_types=1);

namespace Gratora\Donors\Privacy;

use Gratora\Donors\ConsentService;
use Gratora\Donors\Donor;
use Gratora\Donors\DonorMetricsService;
use Gratora\Donors\DonorRepository;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Identity\IdentityHasher;
use Gratora\Recurring\PlanStatus;

/**
 * Connect WordPress privacy tools to the existing export and erasure services, including add-on
 * handlers.
 *
 * @since 1.0.0
 */
final class WordPressPrivacy
{
    /** WordPress pages these; a donor is one subject, so one page is enough. */
    public const GROUP = 'gratora-donor';

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
        $exporters['gratora'] = [
            'exporter_friendly_name' => __('Gratora donations', 'gratora-donation-platform'),
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
        $erasers['gratora'] = [
            'eraser_friendly_name' => __('Gratora donations', 'gratora-donation-platform'),
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
            'group_label' => __('Donor record', 'gratora-donation-platform'),
            'item_id'     => 'gratora-donor-' . (int) $donor->id,
            'data'        => $this->donorFields($donor, $bundle['donor'] ?? []),
        ]];

        foreach ($this->rows($bundle, 'donations') as $i => $row) {
            $groups[] = $this->group(
                'gratora-donation',
                __('Donations', 'gratora-donation-platform'),
                'gratora-donation-' . ($row['id'] ?? $i),
                $this->donationFields($row)
            );
        }

        foreach ($this->rows($bundle['recurring'] ?? [], 'plans') as $i => $row) {
            $groups[] = $this->group(
                'gratora-recurring',
                __('Recurring plans', 'gratora-donation-platform'),
                'gratora-plan-' . ($row['id'] ?? $i),
                $this->planFields($row)
            );
        }

        foreach ($this->rows($bundle, 'receipts') as $i => $row) {
            $groups[] = $this->group(
                'gratora-receipt',
                __('Receipts', 'gratora-donation-platform'),
                'gratora-receipt-' . ($row['id'] ?? $i),
                $this->receiptFields($row)
            );
        }

        foreach ($this->rows($bundle, 'events') as $i => $row) {
            $groups[] = $this->group(
                'gratora-activity',
                __('Activity', 'gratora-donation-platform'),
                'gratora-event-' . ($row['id'] ?? $i),
                $this->eventFields($row)
            );
        }

        foreach ($this->rows($bundle['consents'] ?? [], 'history') as $i => $row) {
            $groups[] = $this->group(
                'gratora-consent',
                __('Consents', 'gratora-donation-platform'),
                'gratora-consent-' . ($row['id'] ?? $i),
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
                'messages'       => [__('This donor was already erased.', 'gratora-donation-platform')],
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
                __('The donor record was erased. Their donations were kept as anonymous records, because the amounts are part of the accounts.', 'gratora-donation-platform'),
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
            [__('First name', 'gratora-donation-platform'), $donor->first_name ?? ''],
            [__('Last name', 'gratora-donation-platform'), $donor->last_name ?? ''],
            [__('Company', 'gratora-donation-platform'), $donor->company ?? ''],
            [__('Email', 'gratora-donation-platform'), $exported['email'] ?? ''],
            [__('Phone', 'gratora-donation-platform'), $exported['phone'] ?? ''],
            [__('Address', 'gratora-donation-platform'), $exported['address'] ?? ''],
            [__('Country', 'gratora-donation-platform'), $donor->country ?? ''],
            [__('First seen', 'gratora-donation-platform'), $donor->created_at ?? ''],
            [__('First donation', 'gratora-donation-platform'), $donor->first_donation_at ?? ''],
            [__('Last donation', 'gratora-donation-platform'), $donor->last_donation_at ?? ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function donationFields(array $row): array
    {
        return $this->fields([
            [__('Reference', 'gratora-donation-platform'), $row['reference'] ?? ''],
            [__('Amount', 'gratora-donation-platform'), $this->money($row)],
            [__('Frequency', 'gratora-donation-platform'), $row['frequency'] ?? ''],
            [__('Status', 'gratora-donation-platform'), $row['status'] ?? ''],
            [__('Payment method', 'gratora-donation-platform'), $row['gateway'] ?? ''],
            [__('Paid', 'gratora-donation-platform'), $row['paid_at'] ?? ''],
            [__('Created', 'gratora-donation-platform'), $row['created_at'] ?? ''],
            [__('Test donation', 'gratora-donation-platform'), ! empty($row['is_test']) ? __('Yes', 'gratora-donation-platform') : ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function planFields(array $row): array
    {
        return $this->fields([
            [__('Amount', 'gratora-donation-platform'), $this->money($row)],
            [__('Frequency', 'gratora-donation-platform'), $row['frequency'] ?? ''],
            [__('Status', 'gratora-donation-platform'), PlanStatus::label((string) ($row['status'] ?? ''))],
            [__('Payment method', 'gratora-donation-platform'), $row['gateway'] ?? ''],
            [__('Started', 'gratora-donation-platform'), $row['started_at'] ?? ''],
            [__('Next payment', 'gratora-donation-platform'), $row['next_payment_at'] ?? ''],
            [__('Last payment', 'gratora-donation-platform'), $row['last_payment_at'] ?? ''],
            [__('Cancelled', 'gratora-donation-platform'), $row['cancelled_at'] ?? ''],
            [__('Payments made', 'gratora-donation-platform'), (int) ($row['payments_count'] ?? 0) ?: ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function receiptFields(array $row): array
    {
        return $this->fields([
            [__('Receipt number', 'gratora-donation-platform'), $row['receipt_number'] ?? ''],
            [__('Donation', 'gratora-donation-platform'), $row['donation_reference'] ?? ''],
            [__('Issued', 'gratora-donation-platform'), $row['issued_at'] ?? ''],
            [__('Emailed', 'gratora-donation-platform'), $row['sent_to_email_at'] ?? ''],
            [__('Voided', 'gratora-donation-platform'), ! empty($row['voided']) ? __('Yes', 'gratora-donation-platform') : ''],
        ]);
    }

    /** @param array<string,mixed> $row */
    private function consentFields(array $row): array
    {
        $key     = (string) ($row['purpose'] ?? '');
        $purpose = $this->consents->findPurpose($key);

        return $this->fields([
            [__('Purpose', 'gratora-donation-platform'), $purpose['label'] ?? $key],
            [
                __('Consent', 'gratora-donation-platform'),
                ! empty($row['granted'])
                    ? __('Given', 'gratora-donation-platform')
                    : __('Withdrawn', 'gratora-donation-platform'),
            ],
            [__('Recorded from', 'gratora-donation-platform'), $row['source'] ?? ''],
            [__('Recorded', 'gratora-donation-platform'), $row['occurred_at'] ?? ''],
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
            [__('Event', 'gratora-donation-platform'), $row['type'] ?? ''],
            [__('Amount', 'gratora-donation-platform'), $this->money($row)],
            [__('Recorded', 'gratora-donation-platform'), $row['occurred_at'] ?? ''],
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
