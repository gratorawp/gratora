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
     * @return list<array{id:string,name:string,category:string,description:string,best_for:string}>
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        $templates = [
            [
                'id'          => self::DEFAULT_ID,
                'category'    => 'General',
                'name'        => __( 'Standard campaign', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Image, raised and goal figures, a progress bar, your description, then recent donations and top donors, with the form alongside.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'Most campaigns. Start here if none of the others obviously fit.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'hero',
                'category'    => 'General',
                'name'        => __( 'Colour hero', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Opens on a full-width band in your campaign colour carrying the title, the figures and the progress bar, with everything else below it.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A campaign that should look like an event rather than a page.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'cover',
                'category'    => 'General',
                'name'        => __( 'Photo cover', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Opens on the campaign image running the full width, with the title, the figures and the progress bar laid over it.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A campaign with one photograph strong enough to carry the page.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'split',
                'category'    => 'General',
                'name'        => __( 'Split panel', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'The figures sit in a coloured panel next to the form, so the ask and the progress share the first screen.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A short campaign where the number is the argument.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'story',
                'category'    => 'General',
                'name'        => __( 'Story first', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'A full-width image and the description before any figures at all. The ask comes after the reader knows what they are being asked about.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'An appeal that has to explain itself before it asks, and campaigns with a strong photograph.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'gallery',
                'category'    => 'General',
                'name'        => __( 'With other campaigns', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'The usual layout, then a band at the foot showing your other campaigns, so somebody who has just given sees what else needs them.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'An organisation running several appeals at once.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'deadline',
                'category'    => 'Appeals',
                'name'        => __( 'Appeal with a deadline', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Leads with the goal and how far off it is, puts the form above the fold, and holds the description back until after the ask.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A crisis or a matched appeal, where the reason to give now is the deadline.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'urgent',
                'category'    => 'Appeals',
                'name'        => __( 'Emergency appeal', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'A colour band carrying what is still needed and how long is left, then the form. Nothing on the page that is not the ask.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A crisis, where anything the reader has to scroll past is a reader you lose.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'matched',
                'category'    => 'Appeals',
                'name'        => __( 'Matched giving', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Raised, goal and donor count together in a coloured band under the title, so the size of the effort reads before the description.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A match or a challenge, where how many have joined in matters as much as the total.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'supporters',
                'category'    => 'Community',
                'name'        => __( 'Supporter wall', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'The people who have already given are the main content, with their messages shown and the wall running the full width beneath the form.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A community appeal where seeing familiar names is the reason somebody gives.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'leaderboard',
                'category'    => 'Community',
                'name'        => __( 'Leaderboard', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Top donors and recent donations carry the page, over a tinted panel of the donor and donation counts.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A competitive campaign, a challenge between teams or offices.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'thermometer',
                'category'    => 'Community',
                'name'        => __( 'Thermometer', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'The percentage, the amount raised and what is left, large and in colour, with the supporter wall beneath.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A campaign with one number everybody is watching.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'tiers',
                'category'    => 'Impact',
                'name'        => __( 'Impact figures', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Three tinted cards carrying the raised total, the number of donors and the average donation, above the image and the form.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'An appeal where the shape of the giving is the story.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'transparency',
                'category'    => 'Impact',
                'name'        => __( 'Open books', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Four figures across a tinted band: raised, donors, donations and the average, with the image above them.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'An organisation that wants the numbers visible before the ask.', 'giveflow-fundraising-campaigns' ),
            ],
            [
                'id'          => 'minimal',
                'category'    => 'Bare',
                'name'        => __( 'Just the form', 'giveflow-fundraising-campaigns' ),
                'description' => __( 'Title, description and the donation form. No figures, no donor lists, nothing that needs data to look right.', 'giveflow-fundraising-campaigns' ),
                'best_for'    => __( 'A page you will design yourself, and a campaign with no goal to show.', 'giveflow-fundraising-campaigns' ),
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
    private const HERO = <<<'BLOCKS'
<!-- wp:group {"align":"wide","className":"dp-panel dp-panel--accent dp-panel--lead"} -->
<div class="wp-block-group alignwide dp-panel dp-panel--accent dp-panel--lead">
<!-- wp:heading {"level":1,"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}}} -->
<h1 class="wp-block-heading">%%TITLE%%</h1>
<!-- /wp:heading -->

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
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
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

    /**
     * The campaign's photograph is the ground rather than a block, so there is
     * no image block here: the stylesheet paints it from the campaign, and the
     * title and figures sit on top. A campaign with no image still renders,
     * falling back to the accent underneath.
     *
     * @since 1.0.0
     */
    private const COVER = <<<'BLOCKS'
<!-- wp:group {"align":"wide","className":"dp-panel dp-cover"} -->
<div class="wp-block-group alignwide dp-panel dp-cover">
<!-- wp:group {"className":"dp-cover__body"} -->
<div class="wp-block-group dp-cover__body">
<!-- wp:heading {"level":1,"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}}} -->
<h1 class="wp-block-heading">%%TITLE%%</h1>
<!-- /wp:heading -->

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
</div>
<!-- /wp:group -->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
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
    private const SPLIT = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"50%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:50%">
<!-- wp:group {"className":"dp-panel dp-panel--accent"} -->
<div class="wp-block-group dp-panel dp-panel--accent">
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
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"50%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:50%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->
BLOCKS;

    /** @since 1.0.0 */
    private const STORY = <<<'BLOCKS'
<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%,"aspectRatio":"16-9"} /-->
</div>
<!-- /wp:group -->

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
    private const GALLERY = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/campaign-grid {"count":3,"heading":""} /-->
</div>
<!-- /wp:group -->
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
    private const URGENT = <<<'BLOCKS'
<!-- wp:group {"align":"wide","className":"dp-panel dp-panel--accent dp-panel--lead"} -->
<div class="wp-block-group alignwide dp-panel dp-panel--accent dp-panel--lead">
<!-- wp:heading {"level":1,"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}}} -->
<h1 class="wp-block-heading">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:columns {"className":"dp-figures"} -->
<div class="wp-block-columns dp-figures">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"remaining","size":"lg"} /-->
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
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"55%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:55%">
<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"45%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:45%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->
BLOCKS;

    /** @since 1.0.0 */
    private const MATCHED = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","className":"dp-panel dp-panel--accent dp-panel--lead"} -->
<div class="wp-block-group alignwide dp-panel dp-panel--accent dp-panel--lead">
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
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"donors","size":"lg"} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->

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

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/supporter-wall {"campaignId":%%CAMPAIGN_ID%%,"title":%%WALL_TITLE%%,"limit":50,"showMessage":true} /-->
</div>
<!-- /wp:group -->
BLOCKS;

    /** @since 1.0.0 */
    private const LEADERBOARD = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:group {"className":"dp-panel dp-panel--soft"} -->
<div class="wp-block-group dp-panel dp-panel--soft">
<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->

<!-- wp:columns {"className":"dp-figures"} -->
<div class="wp-block-columns dp-figures">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"donors","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"donations","size":"lg"} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:giveflow/top-donors {"campaignId":%%CAMPAIGN_ID%%,"title":%%TOP_TITLE%%,"limit":10,"layout":"list"} /-->

<!-- wp:giveflow/recent-donations {"campaignId":%%CAMPAIGN_ID%%,"title":%%RECENT_TITLE%%,"limit":8} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
BLOCKS;

    /** @since 1.0.0 */
    private const THERMOMETER = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","className":"dp-panel dp-panel--accent dp-panel--lead"} -->
<div class="wp-block-group alignwide dp-panel dp-panel--accent dp-panel--lead">
<!-- wp:columns {"className":"dp-figures"} -->
<div class="wp-block-columns dp-figures">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"percent","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"raised","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"remaining","size":"lg"} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
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

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/supporter-wall {"campaignId":%%CAMPAIGN_ID%%,"title":%%WALL_TITLE%%,"limit":40,"showMessage":true} /-->
</div>
<!-- /wp:group -->
BLOCKS;

    /** @since 1.0.0 */
    private const TIERS = <<<'BLOCKS'
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

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%">
<!-- wp:group {"className":"dp-panel dp-panel--soft"} -->
<div class="wp-block-group dp-panel dp-panel--soft">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"raised","size":"lg"} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%">
<!-- wp:group {"className":"dp-panel dp-panel--soft"} -->
<div class="wp-block-group dp-panel dp-panel--soft">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"donors","size":"lg"} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"33.33%"} -->
<div class="wp-block-column" style="flex-basis:33.33%">
<!-- wp:group {"className":"dp-panel dp-panel--soft"} -->
<div class="wp-block-group dp-panel dp-panel--soft">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"average","size":"lg"} /-->
</div>
<!-- /wp:group -->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"55%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:55%">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"45%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:45%">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->
BLOCKS;

    /** @since 1.0.0 */
    private const TRANSPARENCY = <<<'BLOCKS'
<!-- wp:heading {"level":1,"align":"wide","metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"title","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading alignwide dp-display dp-rail dp-top">%%TITLE%%</h1>
<!-- /wp:heading -->

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/campaign-image {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->

<!-- wp:group {"align":"wide","className":"dp-panel dp-panel--soft"} -->
<div class="wp-block-group alignwide dp-panel dp-panel--soft">
<!-- wp:columns {"className":"dp-figures"} -->
<div class="wp-block-columns dp-figures">
<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"raised","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"donors","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"donations","size":"lg"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column -->
<div class="wp-block-column">
<!-- wp:giveflow/campaign-stat {"campaignId":%%CAMPAIGN_ID%%,"metric":"average","size":"lg"} /-->
</div>
<!-- /wp:column -->

</div>
<!-- /wp:columns -->
</div>
<!-- /wp:group -->

<!-- wp:columns {"align":"wide","className":"dp-layout"} -->
<div class="wp-block-columns alignwide dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"giveflow/campaign","args":{"key":"description","campaign_id":%%CAMPAIGN_ID%%}}}},"className":"dp-body"} -->
<p class="dp-body">%%DESCRIPTION%%</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:giveflow/campaign-progress {"campaignId":%%CAMPAIGN_ID%%} /-->
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

<!-- wp:group {"align":"wide","className":"dp-wide"} -->
<div class="wp-block-group alignwide dp-wide">
<!-- wp:giveflow/donation-form {"campaignId":%%CAMPAIGN_ID%%} /-->
</div>
<!-- /wp:group -->
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
            case 'hero':         return self::HERO;
            case 'cover':        return self::COVER;
            case 'split':        return self::SPLIT;
            case 'story':        return self::STORY;
            case 'gallery':      return self::GALLERY;
            case 'deadline':     return self::DEADLINE;
            case 'urgent':       return self::URGENT;
            case 'matched':      return self::MATCHED;
            case 'supporters':   return self::SUPPORTERS;
            case 'leaderboard':  return self::LEADERBOARD;
            case 'thermometer':  return self::THERMOMETER;
            case 'tiers':        return self::TIERS;
            case 'transparency': return self::TRANSPARENCY;
            case 'minimal':      return self::MINIMAL;
            default:             return self::STANDARD;
        }
    }

    /** Whether an id names a template that exists. @since 1.0.0 */
    public static function exists(string $id): bool
    {
        return self::find($id) !== null;
    }
}
