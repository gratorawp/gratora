<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Donations\Donation;
use Dono\Forms\Form;
use Dono\Forms\FormService;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Time\Clock;
use Dono\Recurring\RecurringPlan;
use InvalidArgumentException;
use Dono\Vendor\Queryable\DB;
use RuntimeException;

/**
 * Creates, updates, duplicates, and deletes campaigns and their linked WP pages.
 *
 * @version 1.0.0
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

    public function __construct(
        private CampaignRepository $campaigns,
        private FormService $forms,
        private Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     */
    public function create(array $input): Campaign
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = __('Untitled campaign', 'dono');
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
        $type = sanitize_key((string) ($input['campaign_type'] ?? 'standard'));
        $allowedTypes = array_keys((array) apply_filters('dono.campaign.types', ['standard' => '']));
        $campaign->campaign_type = in_array($type, $allowedTypes, true) ? $type : 'standard';
        $campaign->default_fund_id     = isset($input['default_fund_id']) && $input['default_fund_id'] !== '' && $input['default_fund_id'] !== null
            ? (int) $input['default_fund_id'] : null;
        $campaign->image_attachment_id = $this->validateImageAttachment($input['image_attachment_id'] ?? null);
        $campaign->starts_at   = $input['starts_at'] ?? null;
        $campaign->ends_at     = $input['ends_at']   ?? null;
        $campaign->created_at  = $now;
        $campaign->updated_at  = $now;

        $skipTemplate = ! empty($input['skip_template']);

        // Campaign row, default form, and page commit together. wp_delete_post()
        // is not transactional, so a thrown error mid-create may leave an orphan
        // page recoverable from the admin pages list.
        DB::transaction(function () use ($campaign, $skipTemplate) {
            $campaign->save();
            $campaign->default_form_id = $this->createDefaultFormFor($campaign, $skipTemplate);
            $campaign->page_id         = $this->createPageFor($campaign, $skipTemplate);
            $campaign->save();
        });

        do_action('dono.campaign.created', $campaign);
        return $campaign;
    }

    /** @param array<string,mixed> $input */
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
                    throw new InvalidArgumentException(__('Invalid slug.', 'dono'));
                }
                if ($next !== $campaign->slug && $this->campaigns->slugExists($next, $campaign->id)) {
                    throw new InvalidArgumentException(__('Slug is already in use.', 'dono'));
                }
                $campaign->slug = $next;
            }
        }

        foreach (['description', 'starts_at', 'ends_at'] as $field) {
            if (array_key_exists($field, $input)) {
                $campaign->$field = $input[$field] !== '' ? (string) $input[$field] : null;
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

        $prevType = $campaign->campaign_type;
        if (array_key_exists('campaign_type', $input)) {
            // One-way conversion: only a 'standard' campaign may be converted, and
            // only to a registered non-standard type. Existing non-standard
            // campaigns keep their type, so a save never silently strands the
            // fundraisers/attribution a peer_to_peer campaign accumulated.
            $next    = sanitize_key((string) $input['campaign_type']);
            $allowed = array_keys((array) apply_filters('dono.campaign.types', ['standard' => '']));
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
                    throw new InvalidArgumentException(__('Selected form is not part of this campaign.', 'dono'));
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

        // Keep the linked WP page in sync with the campaign record.
        $this->syncPage($campaign, [
            'title'  => $prevTitle  !== $campaign->title,
            'slug'   => $prevSlug   !== $campaign->slug,
            'status' => $prevStatus !== $campaign->status,
        ]);

        do_action('dono.campaign.updated', $campaign);
        if ($campaign->campaign_type !== $prevType) {
            // A one-way type conversion just happened. `updated` alone can't
            // distinguish it from an ordinary edit, so fire a dedicated event
            // add-ons can hook to seed the new type's sidecar and re-lay-out the
            // page (which still carries the standard starter blocks).
            do_action('dono.campaign.converted', $campaign, $prevType);
        }
        return $campaign;
    }

    public function delete(Campaign $campaign): void
    {
        // A campaign with donations (or recurring plans) is never hard-deleted:
        // its donation rows would be orphaned against a missing campaign_id,
        // losing that campaign's reporting. Archive keeps the records instead.
        // Mirrors FundService::delete's reference guard.
        $donations = (int) Donation::query()->where('campaign_id', $campaign->id)->count();
        $plans     = (int) RecurringPlan::query()->where('campaign_id', $campaign->id)->count();
        if ($donations > 0 || $plans > 0) {
            throw new RuntimeException(
                __('This campaign has donations and cannot be deleted. Archive it instead to keep its records.', 'dono')
            );
        }

        // WP post deletion is not transactional; done first so the
        // `before_delete_post` hook can re-sync if the campaign-row delete fails.
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

        // Form delete and campaign delete must commit together. Forms live
        // under a campaign; there is no orphan state.
        DB::transaction(function () use ($campaign) {
            // Fire dono.form.deleted per form: the bulk delete below bypasses
            // FormService::delete's hook, so add-on cleanup (sidecars, stats,
            // event log) would otherwise never run and leave latent orphans.
            foreach (Form::query()->where('campaign_id', $campaign->id)->getAll() as $form) {
                do_action('dono.form.deleted', $form);
            }
            Form::query()->where('campaign_id', $campaign->id)->delete();
            Campaign::query()->where('id', $campaign->id)->delete();
        });

        do_action('dono.campaign.deleted', $campaign);
    }

    /**
     * Resolve an image_attachment_id input to a validated id or null. A
     * non-empty value must point at an actual image (create() and update()
     * share this so they can't diverge).
     */
    private function validateImageAttachment(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }
        $attachmentId = (int) $value;
        if (! wp_attachment_is_image($attachmentId)) {
            throw new InvalidArgumentException(__('Selected file is not an image.', 'dono'));
        }
        return $attachmentId;
    }

    /**
     * Normalise the incoming campaign.style payload.
     * Shape: ['preset_id' => '<id>'?, 'tokens' => [...]?]
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
            $out['tokens'] = \Dono\Campaigns\Styling\Tokens::sanitize($style['tokens']);
        }
        return $out === [] ? null : $out;
    }

    /**
     * Clone the campaign: copy editable fields, reset metrics, draft status,
     * fresh page + default form. Donations are not carried over.
     */
    public function duplicate(Campaign $source): Campaign
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        /* translators: %s: original campaign title */
        $newTitle = sprintf(__('Copy of %s', 'dono'), $source->title);

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

        // wp_delete_post() isn't transactional; an error after the page is
        // inserted may leave an orphan page recoverable from the admin pages list.
        DB::transaction(function () use ($copy) {
            $copy->save();
            $copy->page_id         = $this->createPageFor($copy);
            $copy->default_form_id = $this->createDefaultFormFor($copy);
            $copy->save();
        });

        do_action('dono.campaign.duplicated', $copy, $source);
        return $copy;
    }

    /**
     * WP listener for `wp_trash_post` + `before_delete_post`. When the
     * linked campaign page is trashed or permanently deleted, drop the
     * campaign back to draft and clear `page_id` so the admin can see the
     * page is gone and choose to recreate it. Donations + the form record
     * stay untouched.
     */
    public function onPageDeleted(int $postId): void
    {
        if ($postId <= 0) return;

        // The owning campaign is being deleted wholesale; dono.campaign.deleted
        // already covers it, so don't fire page_lost ("page gone, recreate it").
        if (isset($this->deletingPageIds[$postId])) return;

        $campaignId = (int) get_post_meta($postId, '_dono_campaign_id', true);
        if ($campaignId <= 0) return;

        $campaign = $this->campaigns->findById($campaignId);
        if (! $campaign) return;
        // Idempotent: if page_id was already cleared the follow-up before_delete_post no-ops.
        if ((int) $campaign->page_id !== $postId) return;

        $campaign->status   = 'draft';
        $campaign->page_id  = null;
        $campaign->save();

        do_action('dono.campaign.page_lost', $campaign);
    }

    /**
     * WP listener for `untrashed_post`. Restoring a trashed campaign page must
     * re-link it to its campaign (onPageDeleted cleared page_id on trash), so the
     * campaign isn't left orphaned with the admin offering to recreate a
     * duplicate page. If the page came back published, bring the campaign live
     * too, mirroring onPagePublished.
     */
    public function onPageRestored(int $postId): void
    {
        if ($postId <= 0) return;

        $campaignId = (int) get_post_meta($postId, '_dono_campaign_id', true);
        if ($campaignId <= 0) return;

        $campaign = $this->campaigns->findById($campaignId);
        if (! $campaign) return;
        // Only re-link when the campaign actually lost its page (avoid hijacking a
        // campaign that already points at a different, live page).
        if ((int) $campaign->page_id !== 0) return;

        $campaign->page_id = $postId;
        $campaign->save();
        do_action('dono.campaign.page_restored', $campaign);

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
     */
    public function onPagePublished(string $newStatus, string $oldStatus, \WP_Post $post): void
    {
        if ($newStatus !== 'publish' || $newStatus === $oldStatus) return;
        if (wp_is_post_revision($post) || wp_is_post_autosave($post)) return;

        $campaignId = (int) get_post_meta($post->ID, '_dono_campaign_id', true);
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
     * WP action listener for `dono.form.updated`. When a campaign's default
     * form changes status, re-sync the campaign page so its visibility
     * always tracks the combined campaign + form state. Public only when
     * both are published.
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

    private function createPageFor(Campaign $campaign, bool $formIsDraft = false): int
    {
        // Page is public only when both the campaign and default form are published.
        $postStatus = ( $campaign->status === 'published' && ! $formIsDraft ) ? 'publish' : 'draft';

        $pageId = wp_insert_post([
            'post_title'   => $campaign->title,
            'post_name'    => $campaign->slug,
            'post_content' => $this->pageStarterBlocks($campaign),
            'post_status'  => $postStatus,
            'post_type'    => 'page',
            'post_author'  => get_current_user_id() ?: 1,
            'meta_input'   => ['_dono_campaign_id' => $campaign->id],
        ], true);

        if (is_wp_error($pageId)) {
            throw new RuntimeException($pageId->get_error_message());
        }
        return (int) $pageId;
    }

    /**
     * The layout a new campaign page starts from.
     *
     * Seven bare dynamic blocks became a page an organiser can actually edit.
     * Every heading here is a core Heading block rather than markup baked into
     * a render callback, so the words belong to whoever owns the page. That is
     * only safe because each block below a heading now always renders
     * something: a block that returned an empty string would leave its heading
     * captioning whatever came next.
     *
     * campaign-stats and campaign-progress leave the seed. Both stay
     * registered for anyone who wants them, but the hero already states the
     * money, and repeating it three times above the fold said nothing new.
     *
     * The class names come from the shared campaign page foundation
     * (assets/campaign-page/page.css), which is why they read dp-.
     */
    private function pageStarterBlocks(Campaign $campaign): string
    {
        $id = (int) $campaign->id;
        // Known nit: both serializers escape the double hyphen in a class name
        // inside an attribute value, so the editor rewrites dp-band--tight on
        // its first save and the revision shows a change nobody made. Cosmetic,
        // and LayoutBlocks in the P2P add-on writes it the same way, so this
        // stays readable until the two are fixed together.
        // Seeded content is written in the admin's language at creation, the
        // same as a campaign's own title. Hardcoding English put it on the
        // page of every site that does not run in it.
        $t0 = __('Campaign name', 'dono');
        // The description is bound, so this is only what an organiser who has
        // written none sees in the editor. Nothing else is seeded as prose: the
        // old story slot shipped its own instructions to donors on every page
        // nobody edited, which read as the campaign's own words.
        $t2 = __('What this campaign is raising for.', 'dono');
        $t5 = __('Recent donations', 'dono');
        $t6 = __('Top donors', 'dono');

        // These two sections are titled by the block itself rather than a
        // Heading above it, which rendered the words twice. json_encode so a
        // translated title carrying a quote cannot break the block comment.
        $t5j = json_encode($t5, JSON_UNESCAPED_UNICODE);
        $t6j = json_encode($t6, JSON_UNESCAPED_UNICODE);

        $default = <<<BLOCKS
<!-- wp:heading {"level":1,"metadata":{"bindings":{"content":{"source":"dono/campaign","args":{"key":"title","campaign_id":{$id}}}}},"className":"dp-display dp-rail dp-top"} -->
<h1 class="wp-block-heading dp-display dp-rail dp-top">{$t0}</h1>
<!-- /wp:heading -->

<!-- wp:columns {"className":"dp-layout"} -->
<div class="wp-block-columns dp-layout">
<!-- wp:column {"width":"62%","className":"dp-layout__main"} -->
<div class="wp-block-column dp-layout__main" style="flex-basis:62%">
<!-- wp:dono/campaign-hero {"campaignId":{$id},"showTitle":false,"showDonate":false} /-->

<!-- wp:group {"className":"dp-band dp-band--tight"} -->
<div class="wp-block-group dp-band dp-band--tight">
<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"dono/campaign","args":{"key":"description","campaign_id":{$id}}}}},"className":"dp-body"} -->
<p class="dp-body">{$t2}</p>
<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->

<!-- wp:dono/recent-donations {"campaignId":{$id},"title":{$t5j},"limit":5} /-->

<!-- wp:dono/top-donors {"campaignId":{$id},"title":{$t6j},"limit":5,"layout":"list"} /-->
</div>
<!-- /wp:column -->

<!-- wp:column {"width":"38%","className":"dp-layout__side"} -->
<div class="wp-block-column dp-layout__side" style="flex-basis:38%">
<!-- wp:dono/donation-form {"campaignId":{$id}} /-->
</div>
<!-- /wp:column -->
</div>
<!-- /wp:columns -->

BLOCKS;

        // Add-ons can seed a richer starter layout per campaign type (e.g. the
        // peer-to-peer add-on lays out its thermometer, leaderboard and grids).
        return (string) apply_filters('dono.campaign.starter_blocks', $default, $campaign);
    }

    private function createDefaultFormFor(Campaign $campaign, bool $skipTemplate = false): int
    {
        $form = $this->forms->create([
            /* translators: %s: campaign title */
            'title'       => sprintf(__('%s donation form', 'dono'), $campaign->title),
            // Without a template the form lacks Name + Email and fails publish
            // readiness checks; keep it as draft until the user picks a template.
            'status'      => $skipTemplate ? 'draft' : 'published',
            'campaign_id' => $campaign->id,
            'blocks'      => $skipTemplate ? '' : $this->starterBlocks($campaign->currency),
        ]);
        return $form->id;
    }

    private function starterBlocks(string $currency): string
    {
        $currency = esc_attr(strtoupper($currency));
        return <<<BLOCKS
<!-- wp:dono/donation-amount {"presets":[1000,2500,5000,10000],"allowCustom":true,"currency":"{$currency}"} /-->

<!-- wp:dono/name {"requireFirst":true,"requireLast":true} /-->

<!-- wp:dono/email {"required":true} /-->

<!-- wp:dono/payment-gateways {"style":"cards"} /-->

<!-- wp:dono/donation-summary /-->

<!-- wp:dono/submit-button {"label":"Donate","align":"left"} /-->
BLOCKS;
    }

    /**
     * Sync the linked WP page for fields that actually changed. Only patches
     * the fields that moved to avoid unnecessary wp_update_post calls.
     *
     * @param array{title?:bool,slug?:bool,status?:bool} $changed
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

    private function coerceStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    private function coerceGoalType(string $type): string
    {
        $type = strtolower(trim($type));
        return in_array($type, ['amount', 'donations', 'donors'], true) ? $type : 'amount';
    }

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
