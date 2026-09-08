<?php

declare(strict_types=1);

namespace FundKit\Admin;

use FundKit\Donors\Portal\PortalPage;
use FundKit\Foundation\Hooks\HookProvider;
use FundKit\Vendor\Queryable\DB;
use WP_Post;

/**
 * Label managed campaign and portal pages in the Pages list.
 *
 * @since 1.0.0
 */
final class ManagedPageStates extends HookProvider
{
    private ?array $campaignPageIds = null;

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            'display_post_states' => ['label', 10, 2],
        ];
    }

    /** @since 1.0.0 */
    public function label(array $states, WP_Post $post): array
    {
        if ($post->post_type !== 'page') {
            return $states;
        }

        if (in_array((int) $post->ID, $this->campaignPageIds(), true)) {
            $states['fundkit_campaign'] = __('Fundraising Toolkit Campaign', 'fundraising-toolkit');
            return $states;
        }

        if ((int) get_option(PortalPage::OPTION_PAGE_ID, 0) === (int) $post->ID) {
            $states['fundkit_portal'] = __('Fundraising Toolkit Donor Portal', 'fundraising-toolkit');
        }

        return $states;
    }

    /**
     * Exclude P2P layout subpages that share _fundkit_campaign_id.
     *
     * @since 1.0.0
     */
    private function campaignPageIds(): array
    {
        if ($this->campaignPageIds === null) {
            $this->campaignPageIds = array_values(array_filter(array_map(
                'intval',
                DB::table('fundkit_campaigns')->where('page_id', 0, '>')->pluck('page_id')
            )));
        }

        return $this->campaignPageIds;
    }
}
