<?php

declare(strict_types=1);

namespace FundKit\Donors\Privacy;

use FundKit\Donors\Donor;
use FundKit\Donors\DonorRepository;
use FundKit\Donors\DonorService;
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

        return [
            'data' => [[
                'group_id'    => self::GROUP,
                'group_label' => __('Donor record', 'fundraising-toolkit'),
                'item_id'     => 'fundkit-donor-' . (int) $donor->id,
                'data'        => $this->donorFields($donor),
            ]],
            'done' => true,
        ];
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
     * @return list<array{name:string, value:string}>
     */
    private function donorFields(Donor $donor): array
    {
        $fields = [
            [__('First name', 'fundraising-toolkit'), (string) ($donor->first_name ?? '')],
            [__('Last name', 'fundraising-toolkit'), (string) ($donor->last_name ?? '')],
            [__('Company', 'fundraising-toolkit'), (string) ($donor->company ?? '')],
            [__('First seen', 'fundraising-toolkit'), (string) ($donor->created_at ?? '')],
        ];

        $out = [];
        foreach ($fields as [$name, $value]) {
            if ($value !== '') {
                $out[] = ['name' => $name, 'value' => $value];
            }
        }

        return $out;
    }
}
