<?php

declare(strict_types=1);

namespace GiveFlow\Campaigns;

/**
 * Starter layouts for a campaign page.
 *
 * The counterpart to FormTemplates, one level up: that one composes a donation
 * form, this one composes the page the form sits on. Templates differ by the
 * SHAPE of the appeal rather than by cause, because a medical appeal and an
 * animal shelter appeal are the same page with different photographs, while an
 * appeal with a deadline and a long-scroll impact page genuinely are not.
 *
 * A template is a block layout, so anything a template does an author can
 * afterwards undo in the editor. Nothing here is a mode the page stays in.
 *
 * @since 1.0.0
 */
final class CampaignTemplates
{
    public const DEFAULT_ID = 'standard';

    /**
     * @return list<array{id:string,name:string,description:string,best_for:string}>
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        $templates = [
            [
                'id'          => self::DEFAULT_ID,
                'name'        => __('Standard campaign', 'giveflow-fundraising-campaigns'),
                'description' => __('Image, raised and goal figures, a progress bar, your description, then recent donations and top donors, with the form alongside.', 'giveflow-fundraising-campaigns'),
                'best_for'    => __('Most campaigns. Start here if none of the others obviously fit.', 'giveflow-fundraising-campaigns'),
            ],
            [
                'id'          => 'deadline',
                'name'        => __('Appeal with a deadline', 'giveflow-fundraising-campaigns'),
                'description' => __('Leads with the goal and how far off it is, puts the form above the fold, and holds the description back until after the ask.', 'giveflow-fundraising-campaigns'),
                'best_for'    => __('A crisis or a matched appeal, where the reason to give now is the deadline.', 'giveflow-fundraising-campaigns'),
            ],
            [
                'id'          => 'story',
                'name'        => __('Story first', 'giveflow-fundraising-campaigns'),
                'description' => __('A full-width image and the description before any figures at all. The ask comes after the reader knows what they are being asked about.', 'giveflow-fundraising-campaigns'),
                'best_for'    => __('An appeal that has to explain itself before it asks, and campaigns with a strong photograph.', 'giveflow-fundraising-campaigns'),
            ],
            [
                'id'          => 'supporters',
                'name'        => __('Supporter wall', 'giveflow-fundraising-campaigns'),
                'description' => __('The people who have already given are the main content, with their messages shown and the wall running the full width beneath the form.', 'giveflow-fundraising-campaigns'),
                'best_for'    => __('A community appeal where seeing familiar names is the reason somebody gives.', 'giveflow-fundraising-campaigns'),
            ],
            [
                'id'          => 'minimal',
                'name'        => __('Just the form', 'giveflow-fundraising-campaigns'),
                'description' => __('Title, description and the donation form. No figures, no donor lists, nothing that needs data to look right.', 'giveflow-fundraising-campaigns'),
                'best_for'    => __('A page you will design yourself, and a campaign with no goal to show.', 'giveflow-fundraising-campaigns'),
            ],
        ];

        return (array) apply_filters('giveflow.campaign.templates', $templates);
    }

    /** @since 1.0.0 */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $template) {
            if ($template['id'] === $id) {
                return $template;
            }
        }

        return null;
    }


    /** @since 1.0.0 */
    private const STANDARD = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:columns {"className":"dp-figures"} -->
<div class="wp-block-columns dp-figures">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"raised","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"goal","size":"lg"} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:giveflow/recent-donations {"campaignId":%%CAMPAIGN_ID%%,"title":%%RECENT_TITLE%%,"limit":5} /-->

<!-- wp:giveflow/top-donors {"campaignId":%%CAMPAIGN_ID%%,"title":%%TOP_TITLE%%,"limit":5,"layout":"list"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
BLOCKS;

    /** @since 1.0.0 */
    private const DEADLINE = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:columns {"className":"dp-figures"} -->
<div class="wp-block-columns dp-figures">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"raised","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"goal","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"days_left","size":"lg"} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:giveflow/recent-donations {"campaignId":%%CAMPAIGN_ID%%,"title":%%RECENT_TITLE%%,"limit":5} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
BLOCKS;

    /** @since 1.0.0 */
    private const STORY = <<<'BLOCKS'
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%,"aspectRatio":"16-9"} /-->

<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:group {"className":"dp-band"} -->
<div class="wp-block-group dp-band">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:giveflow/recent-donations {"campaignId":%%CAMPAIGN_ID%%,"title":%%RECENT_TITLE%%,"limit":5} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->
BLOCKS;

    /** @since 1.0.0 */
    private const SUPPORTERS = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

<!-- wp:giveflow/supporter-wall {"campaignId":%%CAMPAIGN_ID%%,"title":%%WALL_TITLE%%,"limit":50,"showMessage":true} /-->
BLOCKS;

    /** @since 1.0.0 */
    private const MINIMAL = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
BLOCKS;

    /**
     * The block layout for a template, with placeholders CampaignService fills.
     *
     * Kept here rather than in the service so a new template is one array entry
     * and one case, and so the substitution stays in one place. An unknown id
     * falls back to the standard layout: a template that has gone away should
     * leave a usable page, not an empty one.
     *
     * @since 1.0.0
     */
    public static function layout(string $id): string
    {
        switch ($id) {
            case 'deadline':
                return self::DEADLINE;
            case 'story':
                return self::STORY;
            case 'supporters':
                return self::SUPPORTERS;
            case 'minimal':
                return self::MINIMAL;
            default:
                return self::STANDARD;
        }
    }

    /** Whether an id names a template that exists. @since 1.0.0 */
    public static function exists(string $id): bool
    {
        return self::find($id) !== null;
    }
}
