<?php

declare(strict_types=1);

namespace Dono\Admin;

use Dono\Donors\Portal\PortalPage;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Vendor\Queryable\DB;
use WP_Post;

/**
 * Labels the pages Dono owns in wp-admin's Pages list, the way core labels the
 * front page and the privacy policy.
 *
 * Without it a campaign page and the donor portal are indistinguishable from
 * anything else somebody wrote by hand, so the two pages that break the product
 * when they are edited or trashed carry no warning that they are load-bearing.
 *
 * @version 1.0.0
 */
final class ManagedPageStates extends HookProvider
{
    /** @var list<int>|null Campaign main-page ids, read once per request. */
    private ?array $campaignPageIds = null;

    protected function filters(): array
    {
        return [
            'display_post_states' => ['label', 10, 2],
        ];
    }

    /**
     * @param array<string,string> $states
     * @return array<string,string>
     */
    public function label(array $states, WP_Post $post): array
    {
        if ($post->post_type !== 'page') {
            return $states;
        }

        if (in_array((int) $post->ID, $this->campaignPageIds(), true)) {
            $states['dono_campaign'] = __('Dono Campaign', 'dono');
            return $states;
        }

        if ((int) get_option(PortalPage::OPTION_PAGE_ID, 0) === (int) $post->ID) {
            $states['dono_portal'] = __('Dono Donor Portal', 'dono');
        }

        return $states;
    }

    /**
     * A campaign's own page, not every page carrying its id: P2P layout
     * subpages hold _dono_campaign_id too, and calling those the campaign
     * would put the same label on several rows.
     *
     * Read in one query rather than per row, or a list of twenty pages costs
     * twenty lookups to draw a label.
     *
     * @return list<int>
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
