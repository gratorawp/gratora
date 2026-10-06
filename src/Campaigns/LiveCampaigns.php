<?php

declare(strict_types=1);

namespace Gratora\Campaigns;

use Gratora\Forms\Form;

/**
 * The campaigns a donor can reach: published, with a published default form.
 * A published campaign whose form is still a draft keeps a draft page, so the
 * operator sees "published" and the public sees a 404.
 *
 * @unreleased
 */
final class LiveCampaigns
{
    /**
     * @return list<Campaign> oldest first
     *
     * @unreleased
     */
    public function all(): array
    {
        $published = Campaign::query()->where('status', 'published')->orderBy('id', 'ASC')->getAll();

        $formIds = array_values(array_filter(array_map(
            static fn (Campaign $campaign): int => (int) ($campaign->default_form_id ?? 0),
            $published
        )));
        if ($formIds === []) {
            return [];
        }

        $publishedForms = array_map('intval', Form::query()
            ->whereIn('id', $formIds)
            ->where('status', 'published')
            ->pluck('id'));

        return array_values(array_filter(
            $published,
            static fn (Campaign $campaign): bool => in_array((int) ($campaign->default_form_id ?? 0), $publishedForms, true)
        ));
    }

    /** @unreleased */
    public function first(): ?Campaign
    {
        return $this->all()[0] ?? null;
    }
}
