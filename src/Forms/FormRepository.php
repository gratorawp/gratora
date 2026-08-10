<?php

declare(strict_types=1);

namespace Dono\Forms;

/**
 * Thin repository over the Form model.
 *
 * @since 1.0.0
 */
final class FormRepository
{
    /** @since 1.0.0 */
    public function findById(int $id): ?Form
    {
        return Form::query()->find('id', $id);
    }

    /** @since 1.0.0 */
    public function findBySlug(string $slug): ?Form
    {
        return Form::query()->find('slug', $slug);
    }

    /**
     * The campaign's usable donation form: the preferred (default) form when it
     * is published, otherwise the most recently updated published form for the
     * campaign. Lets a campaign/fundraiser page render a working form even when
     * default_form_id still points at a draft.
     *
     * @since 1.0.0
     */
    public function publishedForCampaign(int $campaignId, ?int $preferFormId = null): ?Form
    {
        if ($campaignId <= 0) {
            return null;
        }
        if ($preferFormId !== null && $preferFormId > 0) {
            $form = $this->findById($preferFormId);
            if ($form !== null && $form->status === 'published') {
                return $form;
            }
        }
        return Form::query()
            ->where('campaign_id', $campaignId)
            ->where('status', 'published')
            ->orderBy('updated_at', 'DESC')
            ->get();
    }

    /**
     * Whether a slug is taken, optionally ignoring one form id.
     *
     * @since 1.0.0
     */
    public function slugExists(string $slug, ?int $exceptId = null): bool
    {
        $q = Form::query()->where('slug', $slug);
        if ($exceptId !== null) {
            $q = $q->where('id', $exceptId, '!=');
        }
        return $q->get() !== null;
    }

    /**
     * Paginated, filtered form list for the admin table.
     *
     * @param array{page?:int,per_page?:int,orderby?:string,order?:string,status?:string,campaign_id?:int,search?:string} $args
     * @return array{items: array<Form>, total: int}
     *
     * @since 1.0.0
     */
    public function listAdmin(array $args = []): array
    {
        $page    = max(1, (int) ($args['page']     ?? 1));
        $perPage = max(1, min(100, (int) ($args['per_page'] ?? 25)));
        $offset  = ($page - 1) * $perPage;

        $allowedSort = ['updated_at', 'created_at', 'title', 'status'];
        $orderBy = in_array($args['orderby'] ?? '', $allowedSort, true)
            ? $args['orderby']
            : 'updated_at';
        $order = strtoupper((string) ($args['order'] ?? 'desc')) === 'ASC' ? 'ASC' : 'DESC';

        $term = trim((string) ($args['search'] ?? ''));

        $applyFilters = function ($q) use ($args, $term) {
            if (! empty($args['status'])) {
                $q = $q->where('status', (string) $args['status']);
            }
            if (! empty($args['campaign_id'])) {
                $q = $q->where('campaign_id', (int) $args['campaign_id']);
            }
            if ($term !== '') {
                $q = $q->where(function ($g) use ($term): void {
                    $g->whereLike('title', $term)
                      ->orWhereLike('slug', $term);
                });
            }
            return $q;
        };

        $total = (int) $applyFilters(Form::query())->count();
        $items = $applyFilters(Form::query())
            ->orderBy($orderBy, $order)
            ->limit($perPage)
            ->offset($offset)
            ->getAll();

        return ['items' => $items, 'total' => $total];
    }
}
