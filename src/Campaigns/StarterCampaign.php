<?php

declare(strict_types=1);

namespace Gratora\Campaigns;

use Gratora\Receipts\OrgProfile;
use Gratora\Vendor\Queryable\DB;
use Gratora\Vendor\Queryable\QueryBuilder;
use Throwable;

/**
 * Makes a site's first campaign, published with its form and page, so someone
 * new has a page that takes a donation without filling in a form first.
 *
 * Asked for, never automatic: a campaign published at the end of setup left
 * every install with one whether or not it was wanted.
 *
 * @unreleased
 */
final class StarterCampaign
{
    /**
     * The id of the campaign this made, or a claim while it is being made.
     * Read and written as a row, never through the options cache, which would
     * answer from what an earlier request left in it.
     */
    public const OPTION = 'gratora_starter_campaign';

    private const CLAIM = 'creating.';

    /** A claim older than this was left by a request that died. */
    private const CLAIM_SECONDS = 60;

    /** @unreleased */
    public function __construct(private CampaignService $campaigns)
    {
    }

    /**
     * The campaign this made, making it first if it has not yet.
     *
     * @throws StarterCampaignRefused
     *
     * @unreleased
     */
    public function ensure(): Campaign
    {
        $stored = $this->stored();

        $made = ctype_digit($stored) ? Campaign::query()->find('id', (int) $stored) : null;
        if ($made) {
            return $made;
        }

        if (Campaign::query()->count() > 0) {
            throw new StarterCampaignRefused(esc_html__('This site already has a campaign.', 'gratora-donation-platform'));
        }

        $claim = $this->claim($stored);
        if ($claim === null) {
            throw new StarterCampaignRefused(esc_html__('The page is being created. Reload in a moment.', 'gratora-donation-platform'));
        }

        try {
            $campaign = $this->campaigns->create([
                'title'  => $this->title(),
                'status' => 'published',
            ]);
        } catch (Throwable $e) {
            $this->row()->where('option_value', $claim)->delete();
            throw $e;
        }

        $this->row()->where('option_value', $claim)->update(['option_value' => (string) $campaign->id]);

        return $campaign;
    }

    private function title(): string
    {
        $name = trim(wp_specialchars_decode((string) OrgProfile::load()['name'], ENT_QUOTES));
        if ($name === '') {
            return __('Donate', 'gratora-donation-platform');
        }

        /* translators: %s: the organization's name. The title of its first donation page. */
        return sprintf(__('Support %s', 'gratora-donation-platform'), $name);
    }

    /**
     * One INSERT IGNORE decides which request makes the campaign, for the
     * reason ChargeLock gives: add_option reads before it writes.
     *
     * @return ?string the claim this request holds
     */
    private function claim(string $stored): ?string
    {
        if ($stored !== '') {
            if ($this->isStandingClaim($stored)) {
                return null;
            }

            // A claim nobody finished, or the id of a campaign since deleted.
            // Removed only as read, so a claim taken in between stands.
            $this->row()->where('option_value', $stored)->delete();
        }

        $claim  = self::CLAIM . time();
        $result = DB::raw(
            'INSERT IGNORE INTO ' . DB::getPrefix() . "options (option_name, option_value, autoload) VALUES (%s, %s, 'no')",
            [self::OPTION, $claim]
        );

        return $result->affectedRows === 1 ? $claim : null;
    }

    private function isStandingClaim(string $stored): bool
    {
        return str_starts_with($stored, self::CLAIM)
            && (int) substr($stored, strlen(self::CLAIM)) + self::CLAIM_SECONDS > time();
    }

    private function stored(): string
    {
        return (string) ($this->row()->pluck('option_value')[0] ?? '');
    }

    private function row(): QueryBuilder
    {
        return DB::table('options')->where('option_name', self::OPTION);
    }
}
