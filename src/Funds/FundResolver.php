<?php

declare(strict_types=1);

namespace Dono\Funds;

use Dono\Campaigns\Campaign;
use Dono\Forms\Form;

/**
 * Resolves which fund a donation belongs to.
 *
 * Precedence (first active wins): donor choice, form default, campaign
 * default, org default, any active fund.
 *
 * @version 1.0.0
 */
final class FundResolver
{
    public function __construct(private FundRepository $funds)
    {
    }

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
        if ($default && $default->is_active) {
            return (int) $default->id;
        }

        $anyActive = Fund::query()->where('is_active', 1)->orderBy('sort_order', 'ASC')->get();
        return $anyActive ? (int) $anyActive->id : null;
    }

    private function activeId(mixed $fundId): ?int
    {
        $fundId = (int) $fundId;
        if ($fundId <= 0) {
            return null;
        }
        $fund = Fund::query()->where('id', $fundId)->get();
        return $fund && $fund->is_active ? (int) $fund->id : null;
    }

    /**
     * Donor-submitted choices only: a parent fund with active children is a
     * picker group header, not a choice, and the renderer never offers it.
     * Admin-configured defaults keep plain activeId semantics.
     */
    private function selectableId(mixed $fundId): ?int
    {
        $id = $this->activeId($fundId);
        if ($id === null) {
            return null;
        }
        $hasActiveChild = Fund::query()
            ->where('parent_fund_id', $id)
            ->where('is_active', 1)
            ->get();
        return $hasActiveChild ? null : $id;
    }
}
