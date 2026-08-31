<?php

declare(strict_types=1);

namespace FundKit\Campaigns;

use FundKit\Foundation\Auth\Capabilities;
use FundKit\Vendor\Queryable\DB;

/**
 * Query helpers for the Campaign model.
 *
 * @since 1.0.0
 */
final class CampaignRepository
{
    /** @since 1.0.0 */
    public function findById(int $id): ?Campaign
    {
        return Campaign::query()->find('id', $id);
    }

    /**
     * Resolve a campaign for PUBLIC rendering: published only, except that
     * edit-capable users still get draft/archived so they can preview a
     * campaign's pages while building it. Null when it must not render.
     *
     * @since 1.0.0
     */
    public function findRenderable(int $id): ?Campaign
    {
        $campaign = $this->findById($id);
        if ($campaign === null) return null;
        // Drafts/archived render only for users who can actually manage
        // campaigns (not any edit_posts holder like a Contributor); public and
        // under-privileged visitors get nothing.
        if ($campaign->status !== 'published' && ! Capabilities::userCan('fundkit_manage_campaigns')) {
            return null;
        }
        return $campaign;
    }

    /**
     * Other PUBLISHED campaigns for the "more campaigns" grid block. Excludes
     * the given id; ordered by recency, funds raised, or soonest end date.
     *
     * @return list<Campaign>
     *
     * @since 1.0.0
     */
    public function otherPublished(int $excludeId = 0, int $limit = 3, string $orderBy = 'recent'): array
    {
        $limit = max(1, min(12, $limit));
        $q = Campaign::query()->where('status', 'published');
        if ($excludeId > 0) {
            $q = $q->where('id', $excludeId, '!=');
        }
        switch ($orderBy) {
            case 'most-funded':
                $q = $q->orderBy('raised_cents', 'DESC');
                break;
            case 'ending-soon':
                // Only campaigns that have not already ended; an ends_at in the
                // past would otherwise sort first and surface dead campaigns.
                $q = $q->whereIsNotNull('ends_at')
                    ->where('ends_at', gmdate('Y-m-d H:i:s'), '>=')
                    ->orderBy('ends_at', 'ASC');
                break;
            default:
                $q = $q->orderBy('created_at', 'DESC');
                break;
        }
        return $q->limit($limit)->getAll();
    }

    /** @since 1.0.0 */
    public function findBySlug(string $slug): ?Campaign
    {
        return Campaign::query()->find('slug', $slug);
    }

    /** @since 1.0.0 */
    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $q = Campaign::query()->where('slug', $slug);
        if ($exceptId !== null) {
            $q = $q->where('id', $exceptId, '!=');
        }
        return $q->get() !== null;
    }

    /**
     * Picker source for the form editor; archived campaigns excluded.
     *
     * @return array<Campaign>
     *
     * @since 1.0.0
     */
    public function listForPicker(int $limit = 200): array
    {
        return Campaign::query()
            ->whereIn('status', ['published', 'draft'])
            ->orderBy('updated_at', 'DESC')
            ->limit($limit)
            ->getAll();
    }


    /**
     * Narrow a query to one status, stored or derived.
     *
     * Three of the states the list shows are not in the status column: a
     * campaign outside its schedule, or past a goal it is set to close on, is
     * still "published" in the row. The badge derives them, so the filter has
     * to derive the same ones or picking Ended returns nothing.
     *
     * The order matters and mirrors Campaign::notAcceptingReason(): a campaign
     * that both ended and met its goal reads as Ended, so it must not also
     * answer to Goal met, or the two filters would return overlapping sets that
     * disagree with the badges in them.
     *
     * @since 1.0.0
     */
    private function whereStatus($q, string $status)
    {
        $now = gmdate('Y-m-d H:i:s');

        if ($status === 'scheduled') {
            return $q->where('status', 'published')
                ->whereIsNotNull('starts_at')
                ->where('starts_at', $now, '>');
        }

        if ($status === 'ended') {
            return $q->where('status', 'published')
                ->whereIsNotNull('ends_at')
                ->where('ends_at', $now, '<');
        }

        if ($status === 'goal_met') {
            return $q->where('status', 'published')
                ->where('close_at_goal', 1)
                ->where(function ($g) use ($now): void {
                    $g->whereIsNull('ends_at')->orWhere('ends_at', $now, '>=');
                })
                ->where(function ($g) use ($now): void {
                    $g->whereIsNull('starts_at')->orWhere('starts_at', $now, '<=');
                })
                ->where(function ($g): void {
                    // A goal of zero or null is not a goal, so it is never met.
                    $g->where(function ($a): void {
                        $a->where('goal_type', 'amount')
                          ->where('goal_cents', 0, '>')
                          ->whereColumn('raised_cents', 'goal_cents', '>=');
                    })->orWhere(function ($a): void {
                        $a->where('goal_type', 'donations')
                          ->where('goal_count', 0, '>')
                          ->whereColumn('donations_count', 'goal_count', '>=');
                    })->orWhere(function ($a): void {
                        $a->where('goal_type', 'donors')
                          ->where('goal_count', 0, '>')
                          ->whereColumn('donors_count', 'goal_count', '>=');
                    });
                });
        }

        return $q->where('status', $status);
    }

    /**
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,status?:string,search?:string} $args
     * @return array{items: array<Campaign>, total: int}
     *
     * @since 1.0.0
     */
    public function listAdmin(array $args = []): array
    {
        $page    = max(1, (int) ($args['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $allowedSort = ['updated_at', 'created_at', 'title', 'status', 'raised_cents', 'donations_count', 'donors_count'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'updated_at';
        $order = strtoupper((string) ($args['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

        $term = trim((string) ($args['search'] ?? ''));

        $applyFilters = function ($q) use ($args, $term) {
            if (! empty($args['status'])) {
                $q = $this->whereStatus($q, (string) $args['status']);
            }
            if ($term !== '') {
                $q = $q->where(function ($g) use ($term): void {
                    $g->whereLike('title', $term)->orWhereLike('slug', $term);
                });
            }
            return $q;
        };

        $total = (int) $applyFilters(Campaign::query())->count();
        $items = $applyFilters(Campaign::query())
            ->orderBy($orderBy, $order)
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        return ['items' => $items, 'total' => $total];
    }

    /**
     * KPI-strip aggregates for the campaigns admin list; honors the same status + search
     * filters as listAdmin(). Sums the denormalized per-campaign counters (lifetime totals,
     * not a live donations aggregate); currency is the most common among raising rows, else null.
     *
     * @param array{status?:?string,search?:?string} $args
     * @return array{total_count:int,active_count:int,raised_cents:int,currency:?string,donations_count:int}
     *
     * @since 1.0.0
     */
    public function aggregateAdmin(array $args = []): array
    {
        $term = trim((string) ($args['search'] ?? ''));

        $applyFilters = function ($q) use ($args, $term) {
            if (! empty($args['status'])) {
                $q = $this->whereStatus($q, (string) $args['status']);
            }
            if ($term !== '') {
                $q = $q->where(function ($g) use ($term): void {
                    $g->whereLike('title', $term)->orWhereLike('slug', $term);
                });
            }
            return $q;
        };

        // DB::table (raw query builder) returns plain arrays from selectRaw,
        // which is what we need for the SUM/COUNT aggregates here.
        $base = fn () => DB::table('fundkit_campaigns');

        $totalCount  = (int) $applyFilters($base())->count();
        $activeCount = (int) $applyFilters($base())->where('status', 'published')->count();

        $sumsRow = $applyFilters($base())
            ->selectRaw('COALESCE(SUM(raised_cents),0) AS raised, COALESCE(SUM(donations_count),0) AS donations')
            ->get();

        $currencyRow = $applyFilters($base())
            ->where('raised_cents', 0, '>')
            ->selectRaw('currency, COUNT(*) AS cnt')
            ->groupBy('currency')
            ->orderByRaw('cnt DESC')
            ->limit(1)
            ->get();

        return [
            'total_count'     => $totalCount,
            'active_count'    => $activeCount,
            'raised_cents'    => (int) ($sumsRow['raised']    ?? 0),
            'donations_count' => (int) ($sumsRow['donations'] ?? 0),
            'currency'        => is_array($currencyRow) ? ((string) ($currencyRow['currency'] ?? '')) ?: null : null,
        ];
    }
}
