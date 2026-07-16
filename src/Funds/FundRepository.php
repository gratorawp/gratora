<?php

declare(strict_types=1);

namespace Dono\Funds;

/**
 * Fund query helpers.
 *
 * @version 1.0.0
 */
final class FundRepository
{
    public function findById(int $id): ?Fund
    {
        return Fund::query()->find('id', $id);
    }

    public function findByCode(string $code): ?Fund
    {
        return Fund::query()->find('code', $code);
    }

    public function default(): ?Fund
    {
        return Fund::query()->where('is_default', 1)->get();
    }

    /**
     * Active funds for donor-facing pickers, parents before their children so
     * the UI can group a one-level hierarchy. @return array<Fund>
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
     * Active funds shaped for donor-facing pickers: one-level hierarchy where a parent
     * with active children is a non-selectable header. $allowedIds, when non-null,
     * filters to those ids but keeps parent headers whose children survive.
     *
     * @param list<int>|null $allowedIds
     * @return list<array{id:string,label:string,description:string,depth:int,selectable:bool}>
     */
    public function pickerOptions(?array $allowedIds = null): array
    {
        $active = $this->listActive();

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
                'description' => (string) ($f->description ?? ''),
                'depth'       => $isChild ? 1 : 0,
                'selectable'  => $isChild || empty($hasChildren[(int) $f->id]),
            ];
        }
        return $options;
    }

    /** @return array{total:int,active:int,restricted:int,raised_cents:int,default:?array{id:int,name:string}} */
    public function stats(): array
    {
        $default = Fund::query()->where('is_default', 1)->get();

        return [
            'total'        => (int) Fund::query()->count(),
            'active'       => (int) Fund::query()->where('is_active', 1)->count(),
            'restricted'   => (int) Fund::query()->where('is_restricted', 1)->count(),
            'raised_cents' => (int) Fund::query()->sum('raised_cents'),
            'default'      => $default
                ? ['id' => (int) $default->id, 'name' => (string) $default->name]
                : null,
        ];
    }

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
     */
    public function listAdmin(array $args = []): array
    {
        $page    = max(1, (int) ($args['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $allowedSort = ['sort_order', 'name', 'code', 'raised_cents', 'created_at', 'updated_at'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'sort_order';
        $order = strtoupper((string) ($args['order'] ?? 'asc')) === 'DESC' ? 'DESC' : 'ASC';

        $term = trim((string) ($args['search'] ?? ''));

        $applyFilters = function ($q) use ($args, $term) {
            $status = (string) ($args['status'] ?? '');
            if ($status === 'active') {
                $q = $q->where('is_active', 1);
            } elseif ($status === 'inactive') {
                $q = $q->where('is_active', 0);
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
        $items = $applyFilters(Fund::query())
            ->orderBy($orderBy, $order)
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        return ['items' => $items, 'total' => $total];
    }
}
