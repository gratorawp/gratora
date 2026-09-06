<?php

declare(strict_types=1);

namespace FundKit\Funds;

use FundKit\Analytics\ErrorLog;
use FundKit\Campaigns\Campaign;
use FundKit\Forms\Form;

/**
 * Resolves which fund a donation belongs to.
 *
 * Precedence (first open wins): donor choice, form default, campaign
 * default, org default, any open fund. A fund outside its schedule is closed:
 * an admin who gave it an end date meant it to stop taking money then.
 *
 * @since 1.0.0
 */
final class FundResolver
{
    /** @since 1.0.0 */
    public function __construct(private FundRepository $funds)
    {
    }

    /** @since 1.0.0 */
    public function resolve(?int $submittedFundId, ?int $formId, ?int $campaignId): ?int
    {
        if ($id = $this->selectableId($submittedFundId)) {
            return $id;
        }

        if ($formId) {
            $form = Form::query()->where('id', $formId)->get();
            if ($form && ($id = $this->activeId($form->default_fund_id ?? null))) {
                return $id;
            }
        }

        if ($campaignId) {
            $campaign = Campaign::query()->where('id', $campaignId)->get();
            if ($campaign && ($id = $this->activeId($campaign->default_fund_id ?? null))) {
                return $id;
            }
        }

        $default = $this->funds->default();
        if ($default && $default->isOpen()) {
            return (int) $default->id;
        }

        $open = $this->funds->listOpen();
        if ($open === []) {
            return null;
        }

        // Untagged money filed against a fund the org never nominated. Recorded
        // rather than silently rerouted, because the donation still has to go
        // somewhere and nothing else would ever say where.
        if ($default) {
            ErrorLog::record(
                'funds.default_closed',
                'The default fund is not taking donations, so untagged donations are being filed against another fund.',
                ['fund_id' => (int) $default->id, 'filed_against' => (int) $open[0]->id]
            );
        } else {
            ErrorLog::record(
                'funds.default_missing',
                'No fund is marked as the default, so untagged donations are being filed against whichever fund sorts first.',
                ['filed_against' => (int) $open[0]->id]
            );
        }

        return (int) $open[0]->id;
    }

    /** @since 1.0.0 */
    private function activeId(mixed $fundId): ?int
    {
        $fundId = (int) $fundId;
        if ($fundId <= 0) {
            return null;
        }
        $fund = Fund::query()->where('id', $fundId)->get();
        return $fund && $fund->isOpen() ? (int) $fund->id : null;
    }

    /**
     * Donor-submitted choices only: a parent fund with active children is a
     * picker group header, not a choice, and the renderer never offers it.
     * Admin-configured defaults keep plain activeId semantics.
     *
     * @since 1.0.0
     */
    private function selectableId(mixed $fundId): ?int
    {
        $id = $this->activeId($fundId);
        if ($id === null) {
            return null;
        }
        foreach (Fund::query()->where('parent_fund_id', $id)->where('is_active', 1)->getAll() as $child) {
            if ($child->isOpen()) {
                return null;
            }
        }

        return $id;
    }
}
