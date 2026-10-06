<?php

declare(strict_types=1);

namespace Gratora\Campaigns;

use Gratora\Receipts\OrgProfile;
use Gratora\Vendor\Queryable\DB;
use Gratora\Vendor\Queryable\QueryBuilder;
use RuntimeException;
use Throwable;

/**
 * A page somebody new can be sent to and give on: the site's own if it has
 * one, otherwise a first campaign made here, published with its form and page.
 *
 * Asked for, never automatic: a campaign published at the end of setup left
 * every install with one whether or not it was wanted.
 *
 * @unreleased
 */
final class StarterCampaign
{
    /**
     * A claim held while the campaign is being made, so that two requests at
     * once make one. Read and written as a row, never through the options
     * cache, which would answer from what an earlier request left in it.
     */
    public const OPTION = 'gratora_starter_campaign';

    private const CLAIM = 'creating.';

    /** A claim older than this was left by a request that died. */
    private const CLAIM_SECONDS = 60;

    /** @unreleased */
    public function __construct(
        private CampaignService $campaigns,
        private LiveCampaigns $live,
    ) {
    }

    /**
     * @throws StarterCampaignRefused when the site has campaigns and none takes donations, or another request is making this one
     * @throws RuntimeException when the campaign could not be written
     *
     * @unreleased
     */
    public function ensure(): Campaign
    {
        // Asked first, and of the site rather than of a record kept here: a
        // request that died after writing the campaign, or a listener that
        // threw once it was written, leaves a page that is there to be used.
        $open = $this->live->firstOpen();
        if ($open) {
            return $open;
        }

        if (Campaign::query()->count() > 0) {
            throw new StarterCampaignRefused(esc_html__('You have a campaign, but none of them is taking donations right now.', 'gratora-donation-platform'));
        }

        $claim = $this->claim();
        if ($claim === null) {
            throw new StarterCampaignRefused(esc_html__('The page is being created. Try again in a moment.', 'gratora-donation-platform'));
        }

        try {
            return $this->campaigns->create([
                'title'  => $this->title(),
                'status' => 'published',
            ]);
        } finally {
            $this->row()->where('option_value', $claim)->delete();
        }
    }

    /**
     * The page is public, so it is named in the site's language. The request
     * that makes it speaks the language of whoever pressed the button.
     */
    private function title(): string
    {
        $switched = switch_to_locale(get_locale());

        try {
            $name = trim(wp_specialchars_decode((string) OrgProfile::load()['name'], ENT_QUOTES));
            if ($name === '') {
                return __('Donate', 'gratora-donation-platform');
            }

            /* translators: %s: the organization's name. The title of its first donation page. */
            return sprintf(__('Support %s', 'gratora-donation-platform'), $name);
        } finally {
            if ($switched) {
                restore_previous_locale();
            }
        }
    }

    /**
     * One INSERT IGNORE decides which request makes the campaign, for the
     * reason ChargeLock gives: add_option reads before it writes.
     *
     * @return ?string the claim this request holds
     */
    private function claim(): ?string
    {
        $standing = (string) ($this->row()->pluck('option_value')[0] ?? '');
        if ($standing !== '') {
            if ((int) substr($standing, strlen(self::CLAIM)) + self::CLAIM_SECONDS > time()) {
                return null;
            }

            // Removed only as read, so a claim taken in between stands.
            $this->row()->where('option_value', $standing)->delete();
        }

        $claim  = self::CLAIM . time();
        $result = DB::raw(
            'INSERT IGNORE INTO ' . DB::getPrefix() . "options (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            [self::OPTION, $claim]
        );

        return $result->affectedRows === 1 ? $claim : null;
    }

    private function row(): QueryBuilder
    {
        return DB::table('options')->where('option_name', self::OPTION);
    }
}
