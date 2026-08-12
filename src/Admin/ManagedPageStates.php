<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Vendor\Queryable\DB;
use WP_Post;

/**
 * Labels campaign pages and the donor portal in wp-admin's Pages list, so the
 * two pages that break the product when edited or trashed read as load-bearing.
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
            $states['dono_campaign'] = __('Dono Campaign', 'dono-fundraising-platform');
            return $states;
        }

        if ((int) get_option(PortalPage::OPTION_PAGE_ID, 0) === (int) $post->ID) {
            $states['dono_portal'] = __('Dono Donor Portal', 'dono-fundraising-platform');
        }

        return $states;
    }

    /**
     * A campaign's own page, not every page carrying its id: P2P layout
     * subpages hold _dono_campaign_id too, and those are not the campaign.
     *
     * @since 1.0.0
     */
    private function campaignPageIds(): array
    {
        if ($this->campaignPageIds === null) {
            $this->campaignPageIds = array_values(array_filter(array_map(
                'intval',
                DB::table('dono_campaigns')->where('page_id', 0, '>')->pluck('page_id')
            )));
        }

        return $this->campaignPageIds;
    }
}
