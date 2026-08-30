<?php

declare(strict_types=1);

namespace GiveFlow\Campaigns;

use GiveFlow\Donations\Donation;
use GiveFlow\Forms\Form;
use GiveFlow\Forms\FormService;
use GiveFlow\Forms\FormTemplates;
use GiveFlow\Foundation\Helpers\Money;
use GiveFlow\Foundation\Time\Clock;
use GiveFlow\Recurring\RecurringPlan;
use InvalidArgumentException;
use GiveFlow\Vendor\Queryable\DB;
use RuntimeException;

/**
 * Creates, updates, duplicates, and deletes campaigns and their linked WP pages.
 *
 * @since 1.0.0
 */
final class CampaignService
{
    /**
     * Page ids being removed by delete() right now. wp_delete_post() fires the
     * before_delete_post-bound onPageDeleted() synchronously; this lets it skip
     * the page_lost handling for a campaign that is being deleted wholesale.
     *
     * @var array<int,true>
     */
    private array $deletingPageIds = [];

    /** @since 1.0.0 */
    public function __construct(
        private CampaignRepository $campaigns,
        private FormService $forms,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     *
     * @since 1.0.0
     */
    public function create(array $input): Campaign
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = __('Untitled campaign', 'giveflow-fundraising-campaigns');
        }

        $campaign = Campaign::make();
        $campaign->title       = $title;
        $campaign->slug        = $this->uniqueSlug($input['slug'] ?? $title);
        $campaign->status      = $this->coerceStatus($input['status'] ?? 'draft');
        $campaign->description = $input['description'] ?? null;
        // Campaigns have no independent currency: they report in the single org
        // currency. Donations in other currencies are converted into it.
        $campaign->currency    = Money::defaultCurrency();
        $campaign->goal_type   = $this->coerceGoalType($input['goal_type'] ?? 'amount');
        $campaign->goal_cents  = isset($input['goal_cents']) ? (int) $input['goal_cents'] : null;
        $campaign->goal_count  = isset($input['goal_count']) ? (int) $input['goal_count'] : null;
        $this->clearUnusedGoalTarget($campaign);
        $type = sanitize_key((string) ($input['campaign_type'] ?? 'standard'));
        $allowedTypes = array_keys((array) apply_filters('giveflow.campaign.types', ['standard' => '']));
        $campaign->campaign_type = in_array($type, $allowedTypes, true) ? $type : 'standard';
        $campaign->default_fund_id     = isset($input['default_fund_id']) && $input['default_fund_id'] !== '' && $input['default_fund_id'] !== null
            ? (int) $input['default_fund_id'] : null;
        $campaign->image_attachment_id = $this->validateImageAttachment($input['image_attachment_id'] ?? null);
        $campaign->starts_at   = $input['starts_at'] ?? null;
        $campaign->ends_at     = $input['ends_at']   ?? null;
        $campaign->created_at  = $now;
        $campaign->updated_at  = $now;

        $skipTemplate = ! empty($input['skip_template']);

        // An id nobody registered falls back to the standard layout. A template
        // that has been removed, or a typo from an API caller, should leave a
        // usable campaign page rather than an empty one.
        //
        // Validated against this campaign's own type, because the list depends
        // on it: a type whose add-on replaces the templates wholesale has ids
        // core has never heard of, and checking without the type rejects every
        // one of them.
        $pageTemplate = (string) ($input['page_template'] ?? CampaignTemplates::DEFAULT_ID);
        if (! CampaignTemplates::exists($pageTemplate, (string) $campaign->campaign_type)) {
            $pageTemplate = CampaignTemplates::DEFAULT_ID;
        }

        // Campaign row, default form, and page commit together. wp_insert_post()
        // writes through the same connection, so the page row rolls back with
        // the rest; what does not is anything a save_post listener does outside
        // the database, and the object cache entries for the discarded page.
        DB::transaction(function () use ($campaign, $skipTemplate, $pageTemplate) {
            $campaign->save();
            $campaign->default_form_id = $this->createDefaultFormFor($campaign, $skipTemplate, $pageTemplate);
            $campaign->page_id         = $this->createPageFor($campaign, $skipTemplate, $pageTemplate);
            $campaign->save();
        });

        do_action('giveflow.campaign.created', $campaign);
        return $campaign;
    }

    /**
     * @param array<string,mixed> $input
     *
     * @since 1.0.0
     */
    public function update(Campaign $campaign, array $input): Campaign
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        // Snapshot fields that drive the linked WP page to detect changes.
        $prevTitle  = (string) $campaign->title;
        $prevSlug   = (string) $campaign->slug;
        $prevStatus = (string) $campaign->status;

        if (array_key_exists('title', $input)) {
            $title = trim((string) $input['title']);
            if ($title !== '') $campaign->title = $title;
        }

        if (array_key_exists('slug', $input)) {
            $raw = trim((string) $input['slug']);
            if ($raw !== '') {
                $next = sanitize_title($raw);
                if ($next === '') {
                    throw new InvalidArgumentException(esc_html__('Invalid slug.', 'giveflow-fundraising-campaigns'));
                }
                if ($next !== $campaign->slug && $this->campaigns->slugExists($next, $campaign->id)) {
                    throw new InvalidArgumentException(esc_html__('Slug is already in use.', 'giveflow-fundraising-campaigns'));
                }
                $campaign->slug = $next;
            }
        }

        // null and '' both mean "clear it". Testing only against '' let a null
        // through to (string) null, which is '', and a DATETIME column stores
        // that as 0000-00-00: a campaign whose dates could be set once and
        // never removed.
        foreach (['description', 'starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $value = $input[$field];
                $campaign->$field = ($value === null || $value === '') ? null : (string) $value;
            }
        }

        // A campaign whose end precedes its start refuses every donation and
        // says only "has not started yet" or "has ended", never that the two
        // dates contradict each other. The timeline's drag handles already
        // refuse the crossing; the date pickers wrote it straight through.
        if ($campaign->starts_at !== null && $campaign->ends_at !== null) {
            $start = strtotime((string) $campaign->starts_at);
            $end   = strtotime((string) $campaign->ends_at);

            if ($start !== false && $end !== false && $end < $start) {
                throw new InvalidArgumentException(
                    esc_html__('The campaign end date cannot be before its start date.', 'giveflow-fundraising-campaigns')
                );
            }
        }

        // Currency is not editable: campaigns always report in the org currency.

        if (array_key_exists('goal_type', $input)) {
            $campaign->goal_type = $this->coerceGoalType((string) $input['goal_type']);
        }

        if (array_key_exists('goal_cents', $input)) {
            $campaign->goal_cents = $input['goal_cents'] === null || $input['goal_cents'] === ''
                ? null
                : (int) $input['goal_cents'];
        }

        if (array_key_exists('goal_count', $input)) {
            $campaign->goal_count = $input['goal_count'] === null || $input['goal_count'] === ''
                ? null
                : (int) $input['goal_count'];
        }

        // The target the goal type does not use is cleared, never carried. The
        // goal panel renders only the active type's input, so a kept one is off
        // screen with no way to review or correct it.
        $this->clearUnusedGoalTarget($campaign);

        $prevType = $campaign->campaign_type;
        if (array_key_exists('campaign_type', $input)) {
            // One-way conversion: only a 'standard' campaign may be converted, and
            // only to a registered non-standard type. Existing non-standard
            // campaigns keep their type, so a save never silently strands the
            // fundraisers/attribution a peer_to_peer campaign accumulated.
            $next    = sanitize_key((string) $input['campaign_type']);
            $allowed = array_keys((array) apply_filters('giveflow.campaign.types', ['standard' => '']));
            if ($campaign->campaign_type === 'standard' && $next !== 'standard' && in_array($next, $allowed, true)) {
                $campaign->campaign_type = $next;
            }
        }

        if (array_key_exists('default_fund_id', $input)) {
            $campaign->default_fund_id = $input['default_fund_id'] === null || $input['default_fund_id'] === ''
                ? null
                : (int) $input['default_fund_id'];
        }

        if (array_key_exists('image_attachment_id', $input)) {
            $campaign->image_attachment_id = $this->validateImageAttachment($input['image_attachment_id']);
        }

        if (array_key_exists('default_form_id', $input)) {
            $value = $input['default_form_id'];
            if ($value === null || $value === '') {
                $campaign->default_form_id = null;
            } else {
                $formId = (int) $value;
                $form = Form::query()->find('id', $formId);
                if (! $form || $form->campaign_id !== $campaign->id) {
                    throw new InvalidArgumentException(esc_html__('Selected form is not part of this campaign.', 'giveflow-fundraising-campaigns'));
                }
                $campaign->default_form_id = $formId;
            }
        }

        if (array_key_exists('style', $input)) {
            $campaign->style = $this->sanitiseStyle($input['style']);
        }

        foreach (['hide_header', 'hide_footer'] as $flag) {
            if (array_key_exists($flag, $input)) {
                $campaign->$flag = (bool) $input[$flag];
            }
        }

        if (array_key_exists('status', $input)) {
            $campaign->status = $this->coerceStatus((string) $input['status']);
        }

        $campaign->updated_at = $now;
        $campaign->save();

        $this->syncPage($campaign, [
            'title'  => $prevTitle  !== $campaign->title,
            'slug'   => $prevSlug   !== $campaign->slug,
            'status' => $prevStatus !== $campaign->status,
        ]);

        do_action('giveflow.campaign.updated', $campaign);
        if ($campaign->campaign_type !== $prevType) {
            // A one-way type conversion just happened. `updated` alone can't
            // distinguish it from an ordinary edit, so fire a dedicated event
            // add-ons can hook to seed the new type's sidecar and re-lay-out the
            // page (which still carries the standard starter blocks).
            do_action('giveflow.campaign.converted', $campaign, $prevType);
        }
        return $campaign;
    }

    /**
     * Why this campaign cannot be hard-deleted, or null when it can be.
     *
     * A campaign with donations (or recurring plans) is never hard-deleted: its
     * donation rows would be orphaned against a missing campaign_id, losing that
     * campaign's reporting. Archive keeps the records instead. Mirrors
     * FundService::delete's reference guard.
     *
     * Public because the screen has to ask before it offers the action. Every
     * row counts, whatever its status, kind or mode, so this cannot be answered
     * from campaign->donations_count: that counter is synced over paid, live,
     * non-ticket donations only, and a campaign whose single donation is
     * pending, failed, test-mode or a ticket order reads zero there while this
     * still refuses.
     *
     * @since 1.0.0
     */
    public function deleteBlockedReason(Campaign $campaign): ?string
    {
        $donations = (int) Donation::query()->where('campaign_id', $campaign->id)->count();
        $plans     = (int) RecurringPlan::query()->where('campaign_id', $campaign->id)->count();

        if ($donations > 0 || $plans > 0) {
            return __('This campaign has donations and cannot be deleted. Archive it instead to keep its records.', 'giveflow-fundraising-campaigns');
        }

        return null;
    }

    /** @since 1.0.0 */
    public function delete(Campaign $campaign): void
    {
        $blocked = $this->deleteBlockedReason($campaign);
        if ($blocked !== null) {
            throw new RuntimeException(esc_html($blocked));
        }

        // Form delete and campaign delete must commit together. Forms live
        // under a campaign; there is no orphan state.
        DB::transaction(function () use ($campaign) {
            // Fire giveflow.form.deleted per form: the bulk delete below bypasses
            // FormService::delete's hook, so add-on cleanup (sidecars, stats,
            // event log) would otherwise never run and leave latent orphans.
            foreach (Form::query()->where('campaign_id', $campaign->id)->getAll() as $form) {
                do_action('giveflow.form.deleted', $form);
            }
            Form::query()->where('campaign_id', $campaign->id)->delete();
            Campaign::query()->where('id', $campaign->id)->delete();
        });

        // Force-deleting the page bypasses the trash and takes its content with
        // it, so it waits until the campaign it belongs to is actually gone. A
        // failure inside the block above would otherwise leave a live campaign
        // serving a page that no longer exists.
        if ($campaign->page_id) {
            $pageId = (int) $campaign->page_id;
            // Suppress onPageDeleted's page_lost: this is a full delete, not a
            // page going missing under a surviving campaign.
            $this->deletingPageIds[$pageId] = true;
            try {
                wp_delete_post($pageId, true);
            } finally {
                unset($this->deletingPageIds[$pageId]);
            }
        }

        do_action('giveflow.campaign.deleted', $campaign);
    }

    /**
     * Resolve an image_attachment_id input to a validated id or null. A
     * non-empty value must point at an actual image (create() and update()
     * share this so they can't diverge).
     *
     * @since 1.0.0
     */
    private function validateImageAttachment(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $attachmentId = (int) $value;
        if (! wp_attachment_is_image($attachmentId)) {
            throw new InvalidArgumentException(esc_html__('Selected file is not an image.', 'giveflow-fundraising-campaigns'));
        }
        return $attachmentId;
    }

    /**
     * Normalize the incoming campaign.style payload.
     * Shape: ['preset_id' => '<id>'?, 'tokens' => [...]?]
     *
     * @since 1.0.0
     */
    private function sanitiseStyle(mixed $style): ?array
    {
        if (! is_array($style)) return null;

        $out = [];
        if (isset($style['preset_id']) && is_string($style['preset_id']) && $style['preset_id'] !== '') {
            $out['preset_id'] = $style['preset_id'];
        }
        if (array_key_exists('tokens', $style) && is_array($style['tokens'])) {
            // Preserve empty tokens key so the editor's "Customize tokens" toggle
            // round-trips correctly and stays expanded.
            $out['tokens'] = \GiveFlow\Campaigns\Styling\Tokens::sanitize($style['tokens']);
        }
        return $out === [] ? null : $out;
    }

    /**
     * Clone the campaign: copy editable fields, reset metrics, draft status,
     * fresh page + default form. Donations are not carried over.
     *
     * @since 1.0.0
     */
    public function duplicate(Campaign $source): Campaign
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        /* translators: %s: original campaign title */
        $newTitle = sprintf(__('Copy of %s', 'giveflow-fundraising-campaigns'), $source->title);

        $copy = Campaign::make();
        $copy->title       = $newTitle;
        $copy->slug        = $this->uniqueSlug($newTitle);
        $copy->status      = 'draft';
        $copy->campaign_type = $source->campaign_type;
        $copy->description = $source->description;
        // Campaigns always report in the org currency; never copy a source's
        // (possibly stale) currency forward.
        $copy->currency    = Money::defaultCurrency();
        $copy->goal_type   = $source->goal_type;
        $copy->goal_cents  = $source->goal_cents;
        $copy->goal_count  = $source->goal_count;
        $copy->default_fund_id = $source->default_fund_id;
        $copy->hide_header = $source->hide_header;
        $copy->hide_footer = $source->hide_footer;
        $copy->starts_at   = null;
        $copy->ends_at     = null;
        $copy->style               = is_array($source->style) ? $source->style : null;
        $copy->image_attachment_id = $source->image_attachment_id;
        $copy->raised_cents     = 0;
        $copy->donations_count  = 0;
        $copy->donors_count     = 0;
        $copy->created_at = $now;
        $copy->updated_at = $now;

        // Copy row, page, and default form commit together, on the same terms
        // as create(): the page row rolls back, non-database work done by a
        // save_post listener does not.
        DB::transaction(function () use ($copy) {
            $copy->save();
            $copy->page_id         = $this->createPageFor($copy);
            $copy->default_form_id = $this->createDefaultFormFor($copy);
            $copy->save();
        });

        do_action('giveflow.campaign.duplicated', $copy, $source);
        return $copy;
    }

    /**
     * WP listener for `wp_trash_post` + `before_delete_post`. When the
     * linked campaign page is trashed or permanently deleted, drop the
     * campaign back to draft and clear `page_id` so the admin can see the
     * page is gone and choose to recreate it. Donations + the form record
     * stay untouched.
     *
     * @since 1.0.0
     */
    public function onPageDeleted(int $postId): void
    {
        if ($postId <= 0) return;

        // The owning campaign is being deleted wholesale; giveflow.campaign.deleted
        // already covers it, so don't fire page_lost ("page gone, recreate it").
        if (isset($this->deletingPageIds[$postId])) return;

        $campaignId = (int) get_post_meta($postId, '_giveflow_campaign_id', true);
        if ($campaignId <= 0) return;

        $campaign = $this->campaigns->findById($campaignId);
        if (! $campaign) return;
        // Idempotent: if page_id was already cleared the follow-up before_delete_post no-ops.
        if ((int) $campaign->page_id !== $postId) return;

        $campaign->status   = 'draft';
        $campaign->page_id  = null;
        $campaign->save();

        do_action('giveflow.campaign.page_lost', $campaign);
    }

    /**
     * WP listener for `untrashed_post`. Restoring a trashed campaign page must
     * re-link it to its campaign (onPageDeleted cleared page_id on trash), so the
     * campaign isn't left orphaned with the admin offering to recreate a
     * duplicate page. If the page came back published, bring the campaign live
     * too, mirroring onPagePublished.
     *
     * @since 1.0.0
     */
    public function onPageRestored(int $postId): void
    {
        if ($postId <= 0) return;

        $campaignId = (int) get_post_meta($postId, '_giveflow_campaign_id', true);
        if ($campaignId <= 0) return;

        $campaign = $this->campaigns->findById($campaignId);
        if (! $campaign) return;
        // Only re-link when the campaign actually lost its page (avoid hijacking a
        // campaign that already points at a different, live page).
        if ((int) $campaign->page_id !== 0) return;

        $campaign->page_id = $postId;
        $campaign->save();
        do_action('giveflow.campaign.page_restored', $campaign);

        if (get_post_status($postId) === 'publish' && (string) $campaign->status !== 'published') {
            $this->update($campaign, ['status' => 'published']);
        }
    }

    /**
     * WP listener for `transition_post_status`. When a campaign's linked page is
     * published, publish the campaign too, so hitting "Publish" on the campaign
     * page doesn't leave the campaign (and the donation form, which is gated on a
     * published campaign) in draft. The campaign->page sync that update() runs
     * would re-enter here, so we bail once the campaign is already published.
     *
     * @since 1.0.0
     */
    public function onPagePublished(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus !== 'publish' || $newStatus === $oldStatus) return;
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;

        $campaignId = (int) get_post_meta($post->ID, '_giveflow_campaign_id', true);
        if ($campaignId <= 0) return;

        $campaign = $this->campaigns->findById($campaignId);
        if (! $campaign) return;
        // Only the campaign's canonical page, and only when it isn't already live
        // (the latter also breaks the update()->syncPage->transition loop).
        if ((int) $campaign->page_id !== $post->ID) return;
        if ((string) $campaign->status === 'published') return;

        $this->update($campaign, ['status' => 'published']);
    }

    /**
     * WP action listener for `giveflow.form.updated`. When a campaign's default
     * form changes status, re-sync the campaign page so its visibility
     * always tracks the combined campaign + form state. Public only when
     * both are published.
     *
     * @since 1.0.0
     */
    public function onFormUpdated(Form $form): void
    {
        if (! $form->campaign_id) return;

        $campaign = $this->campaigns->findById((int) $form->campaign_id);
        if (! $campaign) return;
        if ((int) $campaign->default_form_id !== (int) $form->id) return;
        if (! $campaign->page_id) return;

        $desired = $this->desiredPageStatus($campaign);

        if ( (string) get_post_status((int) $campaign->page_id) === $desired ) return;

        wp_update_post([
            'ID'          => (int) $campaign->page_id,
            'post_status' => $desired,
        ]);
    }

    /**
     * The WP page backing a campaign may go public only when the campaign is
     * published AND its default donation form (if any) is published too -
     * otherwise the public page would render a form that rejects donations
     * with a 403. Single source of truth for both the campaign-publish path
     * (syncPage) and the form-publish path (onFormUpdated).
     *
     * @since 1.0.0
     */
    private function desiredPageStatus(Campaign $campaign): string
    {
        if ((string) $campaign->status !== 'published') {
            return 'draft';
        }
        $formId = (int) ($campaign->default_form_id ?? 0);
        if ($formId > 0) {
            $form = Form::query()->where('id', $formId)->get();
            if ($form && (string) $form->status !== 'published') {
                return 'draft';
            }
        }
        return 'publish';
    }

    /** @since 1.0.0 */
    private function createPageFor(Campaign $campaign, bool $formIsDraft = false, string $template = CampaignTemplates::DEFAULT_ID): int
    {
        // Page is public only when both the campaign and default form are published.
        $postStatus = ( $campaign->status === 'published' && ! $formIsDraft ) ? 'publish' : 'draft';

        $pageId = wp_insert_post([
            'post_title'   => $campaign->title,
            'post_name'    => $campaign->slug,
            'post_content' => $this->pageStarterBlocks($campaign, $template),
            'post_status'  => $postStatus,
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ?: 1,
            'meta_input'   => ['_giveflow_campaign_id' => $campaign->id],
        ], true);

        if (is_wp_error($pageId)) {
            throw new RuntimeException(esc_html($pageId->get_error_message()));
        }
        return (int) $pageId;
    }

    /**
     * Headings are core Heading blocks rather than markup inside a render
     * callback, so the words belong to whoever owns the page. That relies on
     * every block below a heading rendering something: one returning an empty
     * string would leave its heading captioning whatever came next.
     *
     * dp- class names come from assets/campaign-page/page.css.
     *
     * @since 1.0.0
     */
    /**
     * The blocks a layout produces for this campaign, without writing anything.
     *
     * The editor needs the markup rather than the template's name: it is going
     * to hand these blocks to the block editor, and the campaign id has to be
     * interpolated into them first or every block renders for no campaign.
     *
     * @since 1.0.0
     */
    public function layoutBlocksFor(Campaign $campaign, string $template): string
    {
        if (! CampaignTemplates::exists($template, (string) $campaign->campaign_type)) {
            $template = CampaignTemplates::DEFAULT_ID;
        }

        return $this->pageStarterBlocks($campaign, $template);
    }

    private function pageStarterBlocks(Campaign $campaign, string $template = CampaignTemplates::DEFAULT_ID): string
    {
        $id = (int) $campaign->id;
        // Both serializers escape the double hyphen inside an attribute value,
        // so the editor rewrites dp-band--tight on its first save and the
        // revision shows a change nobody made. Cosmetic, and P2P's LayoutBlocks
        // writes it the same way.
        $t0 = __('Campaign name', 'giveflow-fundraising-campaigns');
        // Bound, so this is only what an organizer who has written no
        // description sees in the editor. Nothing else is seeded as prose:
        // seeded words read to a donor as the campaign's own.
        $t2 = __('What this campaign is raising for.', 'giveflow-fundraising-campaigns');
        $t5 = __('Recent donations', 'giveflow-fundraising-campaigns');
        $t6 = __('Top donors', 'giveflow-fundraising-campaigns');
        $t7 = __('Our supporters', 'giveflow-fundraising-campaigns');
        // Section headings, so a starter page reads as a page rather than a
        // stack of blocks. Above the prose and above the form, which are the
        // two things no block titles for itself.
        $t8 = __('About this campaign', 'giveflow-fundraising-campaigns');
        $t9  = __('Donate', 'giveflow-fundraising-campaigns');
        $t10 = __('Other campaigns', 'giveflow-fundraising-campaigns');

        // These two sections are titled by the block itself rather than a
        // Heading above it, which would render the words twice. json_encode so
        // a translated title carrying a quote cannot break the block comment.
        $t5j = json_encode($t5, JSON_UNESCAPED_UNICODE);
        $t6j = json_encode($t6, JSON_UNESCAPED_UNICODE);
        $t7j = json_encode($t7, JSON_UNESCAPED_UNICODE);

        $blocks = CampaignTemplates::layout($template);

        $default = strtr($blocks, [
            '%%CAMPAIGN_ID%%'  => (string) $id,
            '%%TITLE%%'        => $t0,
            '%%DESCRIPTION%%'  => $t2,
            '%%RECENT_TITLE%%' => (string) $t5j,
            '%%TOP_TITLE%%'    => (string) $t6j,
            '%%WALL_TITLE%%'   => (string) $t7j,
            '%%ABOUT_TITLE%%'  => $t8,
            '%%DONATE_TITLE%%' => $t9,
            '%%MORE_TITLE%%'   => $t10,
        ]);

        // Add-ons can seed a richer starter layout per campaign type (e.g. the
        // peer-to-peer add-on lays out its thermometer, leaderboard and grids).
        // The chosen template goes with it: an add-on that replaces the layout
        // wholesale still has to honour which one the organiser picked, and
        // the campaign row does not record it.
        return (string) apply_filters('giveflow.campaign.starter_blocks', $default, $campaign, $template);
    }

    /** @since 1.0.0 */
    private function createDefaultFormFor(Campaign $campaign, bool $skipTemplate = false, string $pageTemplate = ''): int
    {
        $starter = $skipTemplate
            ? ['blocks' => '', 'settings' => null]
            : $this->starterForm($campaign, $pageTemplate);

        $form = $this->forms->create([
            /* translators: %s: campaign title */
            'title'       => sprintf(__('%s donation form', 'giveflow-fundraising-campaigns'), $campaign->title),
            // Without a template the form lacks Name + Email and fails publish
            // readiness checks; keep it as draft until the user picks a template.
            'status'      => $skipTemplate ? 'draft' : 'published',
            'campaign_id' => $campaign->id,
            'blocks'      => $starter['blocks'],
            'settings'    => $starter['settings'],
        ]);
        return $form->id;
    }

    /**
     * The form the chosen page template asks for.
     *
     * Every campaign used to arrive with the same six fields whichever template
     * built its page, which made the choice of template a choice of decoration.
     * A page that leads with the ask wants the shortest form there is, and a
     * page built to be read can carry one split across steps.
     *
     * @return array{blocks: string, settings: array<string, mixed>|null}
     *
     * @since 1.0.0
     */
    private function starterForm(Campaign $campaign, string $pageTemplate): array
    {
        $id = CampaignTemplates::formTemplate($pageTemplate, (string) $campaign->campaign_type);

        // An add-on that replaces the page templates wholesale names forms for
        // its own, and core has never heard of either id.
        $id = (string) apply_filters('giveflow.campaign.starter_form_template', $id, $campaign, $pageTemplate);

        $template = FormTemplates::find($id);
        $blocks   = is_array($template) ? trim((string) ($template['blocks'] ?? '')) : '';
        if ($blocks === '') {
            return ['blocks' => $this->starterBlocks($campaign->currency), 'settings' => null];
        }

        $settings = is_array($template['settings'] ?? null) ? $template['settings'] : null;

        return ['blocks' => $blocks, 'settings' => $settings];
    }

    /** @since 1.0.0 */
    private function starterBlocks(string $currency): string
    {
        $currency = esc_attr(strtoupper($currency));
        $blocks = <<<'BLOCKS'
<!-- wp:giveflow/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"%%CURRENCY%%"} /-->

<!-- wp:giveflow/name {"requireFirst":true,"requireLast":true} /-->

<!-- wp:giveflow/email {"required":true} /-->

<!-- wp:giveflow/payment-gateways {"style":"cards"} /-->

<!-- wp:giveflow/donation-summary /-->

<!-- wp:giveflow/submit-button {"label":"Donate","align":"left"} /-->
BLOCKS;

        return strtr($blocks, ['%%CURRENCY%%' => $currency]);
    }

    /**
     * Sync the linked WP page for fields that actually changed. Only patches
     * the fields that moved to avoid unnecessary wp_update_post calls.
     *
     * @param array{title?:bool,slug?:bool,status?:bool} $changed
     *
     * @since 1.0.0
     */
    private function syncPage(Campaign $campaign, array $changed): void
    {
        if (! $campaign->page_id) return;
        if (empty($changed['title']) && empty($changed['slug']) && empty($changed['status'])) {
            return;
        }

        $patch = ['ID' => (int) $campaign->page_id];

        if (! empty($changed['title'])) {
            $patch['post_title'] = $campaign->title;
        }
        if (! empty($changed['slug'])) {
            $patch['post_name'] = $campaign->slug;
        }
        if (! empty($changed['status'])) {
            // Respect the default form's status too: publishing a campaign whose
            // form is still draft must not expose a public page with a form that
            // rejects donations.
            $patch['post_status'] = $this->desiredPageStatus($campaign);
        }

        wp_update_post($patch);
    }

    /** @since 1.0.0 */
    private function coerceStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    /** @since 1.0.0 */
    private function clearUnusedGoalTarget(Campaign $campaign): void
    {
        if ($campaign->goal_type === 'amount') {
            $campaign->goal_count = null;
            return;
        }

        $campaign->goal_cents = null;
    }

    /** @since 1.0.0 */
    private function coerceGoalType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['amount', 'donations', 'donors'], true) ? $type : 'amount';
    }

    /** @since 1.0.0 */
    private function uniqueSlug(string $source): string
    {
        $base = sanitize_title($source) ?: 'campaign';
        $slug = $base;
        $i = 2;
        while ($this->campaigns->slugExists($slug)) {
            $slug = $base . '-' . $i++;
            if ($i > 1000) {
                $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                break;
            }
        }
        return $slug;
    }
}
