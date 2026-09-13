<?php

declare(strict_types=1);

namespace Gratora\Funds;

use Gratora\Vendor\Queryable\DB;

/** @since 1.0.0 */
final class FundRepository
{
    /** @since 1.0.0 */
    public function findById(int $id): ?Fund
    {
        return Fund::query()->find('id', $id);
    }

    /** @since 1.0.0 */
    public function findByCode(string $code): ?Fund
    {
        return Fund::query()->find('code', $code);
    }

    /** @since 1.0.0 */
    public function default(): ?Fund
    {
        // Ordered, because nothing in the schema stops a second row carrying
        // the flag: a restore can bring one in beside this site's own. The
        // oldest is the one the site has been filing against.
        return Fund::query()->where('is_default', 1)->orderBy('id', 'ASC')->get();
    }

    /**
     * Active funds for donor-facing pickers, parents before their children so
     * the UI can group a one-level hierarchy.
     *
     * @return array<Fund>
     *
     * @since 1.0.0
     */
    public function listActive(): array
    {
        $all = Fund::query()
            ->where('is_active', 1)
            ->orderBy('sort_order', 'ASC')
            ->orderBy('name', 'ASC')
            ->getAll();

        $roots    = [];
        $children = [];
        foreach ($all as $f) {
            if ($f->parent_fund_id) {
                $children[(int) $f->parent_fund_id][] = $f;
            } else {
                $roots[] = $f;
            }
        }

        $ordered = [];
        foreach ($roots as $root) {
            $ordered[] = $root;
            foreach ($children[(int) $root->id] ?? [] as $child) {
                $ordered[] = $child;
            }
            unset($children[(int) $root->id]);
        }
        // Orphans (parent inactive/removed) fall back to top level.
        foreach ($children as $group) {
            foreach ($group as $orphan) {
                $ordered[] = $orphan;
            }
        }
        return $ordered;
    }

    /**
     * Active funds inside their schedule, in the same order.
     *
     * @return array<Fund>
     *
     * @since 1.0.0
     */
    public function listOpen(?string $now = null): array
    {
        return array_values(array_filter(
            $this->listActive(),
            static fn (Fund $f): bool => $f->isOpen($now),
        ));
    }

    /**
     * Active funds shaped for a picker: one-level hierarchy where a parent
     * with active children is a non-selectable header. $allowedIds, when non-null,
     * filters to those ids but keeps parent headers whose children survive.
     *
     * $openOnly is what a donor sees: a fund scheduled to open next month is
     * still one an author picks as a default today, so the editor's own lists
     * do not apply the window.
     *
     * @param list<int>|null $allowedIds
     * @return list<array{id:string,label:string,description:string,depth:int,selectable:bool}>
     *
     * @since 1.0.0
     */
    public function pickerOptions(?array $allowedIds = null, bool $openOnly = false, bool $withDescriptions = true): array
    {
        $active = $openOnly ? $this->listOpen() : $this->listActive();

        if ($allowedIds !== null) {
            $allow = array_flip(array_map('intval', $allowedIds));
            // Keep parents of allowed children so the picker can still group.
            $parentsOfAllowed = [];
            foreach ($active as $f) {
                if (isset($allow[(int) $f->id]) && $f->parent_fund_id) {
                    $parentsOfAllowed[(int) $f->parent_fund_id] = true;
                }
            }
            $active = array_values(array_filter(
                $active,
                static fn ($f) => isset($allow[(int) $f->id]) || isset($parentsOfAllowed[(int) $f->id])
            ));
        }

        $hasChildren = [];
        foreach ($active as $f) {
            if ($f->parent_fund_id) {
                $hasChildren[(int) $f->parent_fund_id] = true;
            }
        }

        $options = [];
        foreach ($active as $f) {
            $isChild = (bool) $f->parent_fund_id;
            $options[] = [
                'id'          => (string) (int) $f->id,
                'label'       => (string) $f->name,
                'description' => $withDescriptions ? (string) ($f->description ?? '') : '',
                'depth'       => $isChild ? 1 : 0,
                'selectable'  => $isChild || empty($hasChildren[(int) $f->id]),
            ];
        }
        return $options;
    }

    /**
     * @return array{total:int,active:int,restricted:int,raised_cents:int,default:?array{id:int,name:string}}
     *
     * @since 1.0.0
     */
    public function stats(): array
    {
        $default = Fund::query()->where('is_default', 1)->get();

        return [
            'total'        => (int) Fund::query()->count(),
            // Open, not merely flagged active: a fund outside its own window
            // takes no donations, and the row beside this figure says so.
            'active'       => count($this->idsInState('active')),
            'restricted'   => (int) Fund::query()->where('is_restricted', 1)->count(),
            'raised_cents' => (int) Fund::query()->sum('raised_cents'),
            'default'      => $default
                ? ['id' => (int) $default->id, 'name' => (string) $default->name]
                : null,
        ];
    }

    /**
     * The funds in one of the four states the screen shows.
     *
     * Asked of the model rather than of a WHERE clause: the window boundaries
     * are resolved in the org timezone, and a date-only end means the end of
     * that day. Restating that in SQL would be a second reading of the dates,
     * free to drift from the one the badge uses. The table is small enough
     * that reading it whole costs less than that risk.
     *
     * @return list<int>
     *
     * @since 1.0.0
     */
    private function idsInState(string $state): array
    {
        $out = [];

        foreach (Fund::query()->getAll() as $fund) {
            $schedule = $fund->scheduleState();
            $actual   = ! $fund->is_active ? 'inactive' : ($schedule ?? 'active');

            if ($actual === $state) {
                $out[] = (int) $fund->id;
            }
        }

        return $out;
    }

    /** @since 1.0.0 */
    public function codeExists(string $code, ?int $exceptId = null): bool
    {
        $q = Fund::query()->where('code', $code);
        if ($exceptId !== null) {
            $q = $q->where('id', $exceptId, '!=');
        }
        return $q->get() !== null;
    }

    /**
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,status?:string,search?:string} $args
     * @return array{items: array<Fund>, total: int}
     *
     * @since 1.0.0
     */
    public function listAdmin(array $args = []): array
    {
        $page    = max(1, (int) ($args['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $allowedSort = ['sort_order', 'name', 'code', 'is_restricted', 'raised_cents', 'created_at', 'updated_at'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'sort_order';
        $order = strtoupper((string) ($args['order'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        $term = trim((string) ($args['search'] ?? ''));

        $applyFilters = function ($q) use ($args, $term) {
            $status = (string) ($args['status'] ?? '');
            if (in_array($status, ['active', 'scheduled', 'ended', 'inactive'], true)) {
                // The same four the badge draws, so picking Active cannot
                // return a row that reads Ended.
                $ids = $this->idsInState($status);
                $q = $ids === [] ? $q->whereRaw('1 = 0') : $q->whereIn('id', $ids);
            } elseif ($status === 'restricted') {
                $q = $q->where('is_restricted', 1);
            }
            if ($term !== '') {
                $q = $q->where(function ($g) use ($term): void {
                    $g->whereLike('name', $term)->orWhereLike('code', $term);
                });
            }
            return $q;
        };

        $total = (int) $applyFilters(Fund::query())->count();
        $sorted = $applyFilters(Fund::query())->orderBy($orderBy, $order);

        // sort_order is 0 on every row until someone reorders it, so the whole
        // list ties on the default key. Name is what the donor-facing picker
        // breaks that tie with, so the table reads the same way.
        if ($orderBy === 'sort_order') {
            $sorted = $sorted->orderBy('name', $order);
        }

        // Unique, so a LIMIT window is settled whatever the sort key: without
        // it MySQL may break a tie differently per page, and a fund lands on
        // two pages while another lands on none.
        $items = $sorted
            ->orderBy('id', $order)
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        $this->rollUpParents($items);

        return ['items' => $items, 'total' => $total];
    }

    /**
     * Donations land against the exact fund they name, so a parent fund with
     * children collects nothing on its own row and would read 0 raised / 0%.
     * Fold each parent's descendant totals into the parent rows we return.
     * Display-only: the stored counters and stats() SUM stay exact, so the
     * total-raised KPI is never double-counted.
     *
     * @param array<Fund> $items
     *
     * @since 1.0.0
     */
    private function rollUpParents(array $items): void
    {
        $parentIds = [];
        foreach ($items as $f) {
            if ($f->parent_fund_id === null) {
                $parentIds[(int) $f->id] = true;
            }
        }
        if ($parentIds === []) {
            return;
        }

        $rows = DB::table('gratora_funds')
            ->whereIn('parent_fund_id', array_keys($parentIds))
            ->selectRaw('parent_fund_id, SUM(raised_cents) AS r, SUM(donations_count) AS dc')
            ->groupBy('parent_fund_id')
            ->getAll();

        $byParent = [];
        foreach ($rows as $row) {
            $byParent[(int) $row['parent_fund_id']] = $row;
        }

        foreach ($items as $f) {
            $agg = $byParent[(int) $f->id] ?? null;
            if ($agg !== null) {
                $f->raised_cents    += (int) $agg['r'];
                $f->donations_count += (int) $agg['dc'];
            }
        }
    }
}
