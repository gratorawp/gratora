<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Foundation\Auth\Capabilities;
use Dono\Vendor\Queryable\DB;

/**
 * Query helpers for the Campaign model.
 *
 * @version 1.0.0
 */
final class CampaignRepository
{
    public function findById(int $id): ?Campaign
    {
        return Campaign::query()->find('id', $id);
    }

    /**
     * Resolve a campaign for PUBLIC rendering: published only, except that
     * edit-capable users still get draft/archived so they can preview a
     * campaign's pages while building it. Null when it must not render.
     */
    public function findRenderable(int $id): ?Campaign
    {
        $campaign = $this->findById($id);
        if ($campaign === null) return null;
        // Drafts/archived render only for users who can actually manage
        // campaigns (not any edit_posts holder like a Contributor); public and
        // under-privileged visitors get nothing.
        if ($campaign->status !== 'published' && ! Capabilities::userCan('dono_manage_campaigns')) {
            return null;
        }
        return $campaign;
    }

    /**
     * Other PUBLISHED campaigns for the "more campaigns" grid block. Excludes
     * the given id; ordered by recency, funds raised, or soonest end date.
     *
     * @return list<Campaign>
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
                $q = $q->whereIsNotNull('ends_at')->orderBy('ends_at', 'ASC');
                break;
            default:
                $q = $q->orderBy('created_at', 'DESC');
                break;
        }
        return $q->limit($limit)->getAll();
    }

    public function findBySlug(string $slug): ?Campaign
    {
        return Campaign::query()->find('slug', $slug);
    }

    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $q = Campaign::query()->where('slug', $slug);
        if ($exceptId !== null) {
            $q = $q->where('id', $exceptId, '!=');
        }
        return $q->get() !== null;
    }

    /** Picker source for the form editor; archived campaigns excluded. @return array<Campaign> */
    public function listForPicker(int $limit = 200): array
    {
        return Campaign::query()
            ->whereIn('status', ['published', 'draft'])
            ->orderBy('updated_at', 'DESC')
            ->limit($limit)
            ->getAll();
    }

    /**
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,status?:string,search?:string} $args
     * @return array{items: array<Campaign>, total: int}
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
                $q = $q->where('status', (string) $args['status']);
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
     */
    public function aggregateAdmin(array $args = []): array
    {
        $term = trim((string) ($args['search'] ?? ''));

        $applyFilters = function ($q) use ($args, $term) {
            if (! empty($args['status'])) {
                $q = $q->where('status', (string) $args['status']);
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
        $base = fn () => DB::table('dono_campaigns');

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
