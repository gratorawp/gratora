<?php

declare(strict_types=1);

namespace Gratora\Forms;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Donations\Donation;
use Gratora\Foundation\Time\Clock;
use Gratora\Recurring\RecurringPlan;
use InvalidArgumentException;
use RuntimeException;

/** @since 1.0.0 */
final class FormService
{
    /**
     * Blocks a published form must contain.
     *
     * @var list<array{block:string,label:string}>
     */
    private const REQUIRED_BLOCKS = [
        ['block' => 'gratora/donation-amount', 'label' => 'Amount'],
        ['block' => 'gratora/name',            'label' => 'Name'],
        ['block' => 'gratora/email',           'label' => 'Email'],
    ];

    /**
     * @internal
     *
     * @since 1.0.0
     */
    public function __construct(
        private FormRepository $forms,
        private CampaignRepository $campaigns,
        private Clock $clock,
    ) {
    }

    /**
     * @return list<array{block:string,label:string}>
     *
     * @since 1.0.0
     */
    public static function requiredBlocks(): array
    {
        return array_map(
            static fn (array $r): array => ['block' => $r['block'], 'label' => self::requiredLabel($r['label'])],
            self::REQUIRED_BLOCKS
        );
    }

    /** @since 1.0.0 */
    private static function requiredLabel(string $label): string
    {
        return match ($label) {
            'Amount' => __('Amount', 'gratora-donation-platform'),
            'Name'   => __('Name', 'gratora-donation-platform'),
            'Email'  => __('Email', 'gratora-donation-platform'),
            default  => $label,
        };
    }

    /**
     * @return list<array{block:string,label:string}>
     *
     * @since 1.0.0
     */
    public static function missingRequiredBlocks(string $blocksMarkup): array
    {
        $missing = [];
        foreach (self::REQUIRED_BLOCKS as $req) {
            $needle = 'wp:' . $req['block'];
            $pattern = '/<!--\s*' . preg_quote($needle, '/') . '(\s|\/?-->)/';
            if (! preg_match($pattern, $blocksMarkup)) {
                $missing[] = ['block' => $req['block'], 'label' => self::requiredLabel($req['label'])];
            }
        }
        return $missing;
    }

    /**
     * Create a form, publishing it when the input asks for it.
     *
     * @param array{title?:string, slug?:string, status?:string, campaign_id?:int, blocks?:string, settings?:array} $input
     *
     * @since 1.0.0
     */
    public function create(array $input): Form
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = __('Untitled donation form', 'gratora-donation-platform');
        }

        $campaign = $this->resolveCampaign($input['campaign_id'] ?? null);

        $form = Form::make();
        $form->title       = $title;
        $form->slug        = $this->uniqueSlug($input['slug'] ?? $title);
        $form->status      = $this->coerceStatus($input['status'] ?? 'draft');
        $form->blocks      = $this->sanitizeBlocks((string) ($input['blocks'] ?? ''));
        $form->settings    = $this->normaliseSettings(is_array($input['settings'] ?? null) ? $input['settings'] : null);
        $form->spec_version = 1;
        $form->campaign_id = $campaign->id;
        $form->default_fund_id = isset($input['default_fund_id']) && (int) $input['default_fund_id'] > 0
            ? (int) $input['default_fund_id']
            : null;
        $form->author_id   = get_current_user_id() ?: null;
        $form->created_at  = $now;
        $form->updated_at  = $now;

        if ($form->status === 'published') {
            $this->assertPublishable($form);
            $form->published_at = $now;
        }

        $this->syncGatewayAllowed($form);
        $form->save();

        do_action('gratora.form.created', $form);
        return $form;
    }

    /**
     * Keep settings.goal well-formed: a typed goal the Goal block displays.
     *
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>|null
     *
     * @since 1.0.0
     */
    private function normaliseSettings(?array $settings): ?array
    {
        if ($settings === null) {
            return null;
        }
        $goal = is_array($settings['goal'] ?? null) ? $settings['goal'] : [];
        $type = (string) ($goal['type'] ?? 'none');
        if (! in_array($type, ['amount', 'donations', 'donors', 'none'], true)) {
            $type = 'none';
        }
        $settings['goal'] = [
            'type'         => $type,
            'amount_cents' => max(0, (int) ($goal['amount_cents'] ?? 0)),
            'count'        => max(0, (int) ($goal['count'] ?? 0)),
        ];
        return $settings;
    }

    /**
     * Only the gateway block writes settings.gateways.allowed; absence preserves it.
     *
     * @since 1.0.0
     */
    private function syncGatewayAllowed(Form $form): void
    {
        $form->settings = $this->settingsWithGatewayAllowed(
            (string) $form->blocks,
            is_array($form->settings) ? $form->settings : null
        );
    }

    /**
     * The same rule, applied to unsaved markup: the preview and the readiness
     * checks read blocks the author has not saved yet, and judging their
     * gateways from the last-saved setting describes a different form.
     *
     * @param  array<string,mixed>|null $settings
     * @return array<string,mixed>|null
     *
     * @since 1.0.0
     */
    public function settingsWithGatewayAllowed(string $blocksMarkup, ?array $settings): ?array
    {
        $allowed = $this->findGatewayAllowed(parse_blocks($blocksMarkup));
        if ($allowed === null) {
            return $settings;
        }

        $out      = is_array($settings) ? $settings : [];
        $gateways = is_array($out['gateways'] ?? null) ? $out['gateways'] : [];

        $gateways['allowed'] = $allowed;
        $out['gateways']     = $gateways;

        return $out;
    }

    /**
     * Allowed list from the payment-gateways block, or null when absent.
     *
     * @param  array<int,array<string,mixed>> $blocks
     * @return list<string>|null
     *
     * @since 1.0.0
     */
    private function findGatewayAllowed(array $blocks): ?array
    {
        foreach ($blocks as $b) {
            if (($b['blockName'] ?? '') === 'gratora/payment-gateways') {
                $a = $b['attrs']['allowed'] ?? [];
                return is_array($a)
                    ? array_values(array_filter(array_map('strval', $a), static fn ($s) => $s !== ''))
                    : [];
            }
            $inner = $b['innerBlocks'] ?? [];
            if (is_array($inner) && $inner !== []) {
                $found = $this->findGatewayAllowed($inner);
                if ($found !== null) {
                    return $found;
                }
            }
        }
        return null;
    }

    /**
     * Partial update: only the fields present in $input are touched.
     *
     * @param array<string,mixed> $input
     *
     * @since 1.0.0
     */
    public function update(Form $form, array $input): Form
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        if (array_key_exists('title', $input)) {
            $title = trim((string) $input['title']);
            if ($title !== '') {
                $form->title = $title;
            }
        }

        if (array_key_exists('slug', $input)) {
            $raw = trim((string) $input['slug']);
            if ($raw !== '') {
                $next = sanitize_title($raw);
                if ($next === '') {
                    throw new InvalidArgumentException(esc_html__('Invalid slug.', 'gratora-donation-platform'));
                }
                if ($next !== $form->slug && $this->forms->slugExists($next, $form->id)) {
                    throw new InvalidArgumentException(esc_html__('Slug is already in use.', 'gratora-donation-platform'));
                }
                $form->slug = $next;
            }
        }

        if (array_key_exists('campaign_id', $input)) {
            $campaign = $this->resolveCampaign($input['campaign_id']);
            if ((int) $campaign->id !== (int) $form->campaign_id) {
                $this->guardDefaultFormStays($form);
                $form->campaign_id = $campaign->id;
            }
        }

        if (array_key_exists('default_fund_id', $input)) {
            $form->default_fund_id = $input['default_fund_id'] === null
                || $input['default_fund_id'] === 0
                || $input['default_fund_id'] === ''
                ? null
                : (int) $input['default_fund_id'];
        }

        if (array_key_exists('blocks', $input)) {
            $form->blocks = $this->sanitizeBlocks((string) $input['blocks']);
        }

        if (array_key_exists('settings', $input)) {
            $form->settings = $this->normaliseSettings(is_array($input['settings']) ? $input['settings'] : null);
        }

        if (array_key_exists('status', $input)) {
            $next = $this->coerceStatus((string) $input['status']);
            $wasPublished = $form->status === 'published';
            if ($next === 'published') {
                $this->assertPublishable($form);
            }
            $form->status = $next;
            if ($next === 'published' && ! $wasPublished) {
                $form->published_at = $now;
            }
            if ($next === 'archived') {
                $form->archived_at = $now;
            }
        } elseif ($form->status === 'published') {
            $this->assertStaysPublishable($form);
        }

        $form->updated_at = $now;
        $this->syncGatewayAllowed($form);
        $form->save();

        do_action('gratora.form.updated', $form);
        return $form;
    }

    /**
     * Delete a form. Refuses when the form is the campaign default - admin
     * has to pick a different default first so the campaign isn't left in
     * a half-broken state.
     *
     * @since 1.0.0
     */
    public function delete(Form $form): void
    {
        $isDefault = $this->campaignDefaultReason($form);
        if ($isDefault !== null) {
            throw new InvalidArgumentException(esc_html($isDefault));
        }

        $blocked = $this->deleteBlockedReason($form);
        if ($blocked !== null) {
            throw new RuntimeException(esc_html($blocked));
        }

        Form::query()->where('id', $form->id)->delete();

        do_action('gratora.form.deleted', $form);
    }

    /**
     * Why this form cannot be hard-deleted, or null when it can be.
     *
     * Mirrors CampaignService::deleteBlockedReason. A form's donation rows keep
     * pointing at form_id after the row goes: the per-form breakdown can no
     * longer name them, the donations list shows a blank form for each, and the
     * stats row holding that form's raised total stays in the table with no
     * form to belong to. Setting it back to draft takes it off the site and
     * keeps the records.
     *
     * Every row counts, whatever its status or mode: a form whose only donation
     * is pending, failed or test-mode still owns records that would be orphaned.
     *
     * @since 1.0.0
     */
    /**
     * Why this form cannot be deleted at all, or null when it can.
     *
     * The one answer the screen asks before offering the action, so a form
     * whose records hold it in place is not offered a delete that fails after
     * the confirmation with the reason arriving as an error.
     *
     * @since 1.0.0
     */
    public function deleteRefusal(Form $form): ?string
    {
        return $this->campaignDefaultReason($form) ?? $this->deleteBlockedReason($form);
    }

    /** Why this form is held in place by being its campaign's default. */
    private function campaignDefaultReason(Form $form): ?string
    {
        $campaignId = (int) $form->campaign_id;
        if ($campaignId <= 0) {
            return null;
        }

        $defaultFormId = (int) (Campaign::query()->find('id', $campaignId)?->default_form_id ?? 0);
        if ($defaultFormId !== (int) $form->id) {
            return null;
        }

        return __('This form is the campaign default. Pick a different default form before deleting it.', 'gratora-donation-platform');
    }

    public function deleteBlockedReason(Form $form): ?string
    {
        $donations = (int) Donation::query()->where('form_id', $form->id)->count();
        $plans     = (int) RecurringPlan::query()->where('form_id', $form->id)->count();

        if ($donations > 0 || $plans > 0) {
            return __('This form has donations and cannot be deleted. Its records would be left pointing at nothing. Set it back to draft instead.', 'gratora-donation-platform');
        }

        return null;
    }

    /** @since 1.0.0 */
    public function duplicate(Form $source): Form
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        /* translators: %s: original form title */
        $title = sprintf(__('%s (copy)', 'gratora-donation-platform'), $source->title);

        $copy = Form::make();
        $copy->title        = $title;
        $copy->slug         = $this->uniqueSlug($source->slug . '-copy');
        $copy->status       = 'draft';
        $copy->form_type    = $source->form_type;
        $copy->blocks       = (string) $source->blocks;
        $copy->settings     = is_array($source->settings) ? $source->settings : null;
        $copy->spec_version = 1;
        $copy->campaign_id  = $source->campaign_id;
        $copy->default_fund_id = $source->default_fund_id;
        $copy->author_id    = get_current_user_id() ?: null;
        $copy->created_at   = $now;
        $copy->updated_at   = $now;

        $copy->save();

        do_action('gratora.form.duplicated', $copy, $source);
        return $copy;
    }

    /** @since 1.0.0 */
    private function coerceStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    /**
     * Reject edits that make a published form unpublishable.
     *
     * @since 1.0.0
     */
    private function assertStaysPublishable(Form $form): void
    {
        $missing = self::missingRequiredBlocks((string) $form->blocks);
        if (! $missing) return;

        $labels = array_map(fn (array $m): string => $m['label'], $missing);
        throw new InvalidArgumentException(
            esc_html(sprintf(
                /* translators: %s: comma-separated list of missing block labels (Amount, Name, Email). */
                __('This form is published and cannot be saved without these blocks: %s. Add them back, or move the form to draft to keep editing.', 'gratora-donation-platform'),
                implode(', ', $labels)
            ))
        );
    }

    /**
     * Throw when the form is missing blocks required to publish.
     *
     * @since 1.0.0
     */
    private function assertPublishable(Form $form): void
    {
        $missing = self::missingRequiredBlocks((string) $form->blocks);
        if (! $missing) return;

        $labels = array_map(fn (array $m): string => $m['label'], $missing);
        throw new InvalidArgumentException(
            esc_html(sprintf(
                /* translators: %s: comma-separated list of missing block labels (Amount, Name, Email). */
                __('A published donation form needs these blocks: %s.', 'gratora-donation-platform'),
                implode(', ', $labels)
            ))
        );
    }

    /** @since 1.0.0 */
    private function resolveCampaign(mixed $idOrNull): Campaign
    {
        $id = (int) ($idOrNull ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException(esc_html__('A campaign is required.', 'gratora-donation-platform'));
        }
        $campaign = $this->campaigns->findById($id);
        if (! $campaign) {
            throw new InvalidArgumentException(esc_html__('Campaign not found.', 'gratora-donation-platform'));
        }
        return $campaign;
    }

    /** @since 1.0.0 */
    private function uniqueSlug(string $source): string
    {
        $base = sanitize_title($source) ?: 'form';
        $slug = $base;
        $i = 2;
        while ($this->forms->slugExists($slug)) {
            $slug = $base . '-' . $i++;
            if ($i > 1000) {
                $slug = $base . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
                break;
            }
        }
        return $slug;
    }

    /**
     * Strip script-bearing raw HTML from block markup for authors lacking
     * `unfiltered_html` (mirrors WP core's post-content rule). Parse + kses the
     * literal HTML chunks + re-serialize so block-delimiter JSON survives intact
     * (blanket wp_kses_post would mangle it). Without this, a scoped form manager
     * could plant a script that runs on the public donation page via do_blocks.
     *
     * @since 1.0.0
     */
    public function sanitizeBlocks(string $markup): string
    {
        if ($markup === '' || current_user_can('unfiltered_html')) {
            return $markup;
        }
        return serialize_blocks($this->ksesBlockList(parse_blocks($markup)));
    }

    /** @since 1.0.0 */
    private function ksesBlockList(array $blocks): array
    {
        foreach ($blocks as &$block) {
            if (is_array($block['innerContent'] ?? null)) {
                $block['innerContent'] = array_map(
                    static fn ($chunk) => is_string($chunk) ? wp_kses_post($chunk) : $chunk,
                    $block['innerContent']
                );
            }
            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $block['innerBlocks'] = $this->ksesBlockList($block['innerBlocks']);
            }
        }
        unset($block);
        return $blocks;
    }

    /**
     * A campaign points at its default form by id, and nothing reassigns that
     * pointer when a form moves. Letting the default leave would leave the old
     * campaign holding the id of a form another campaign now owns: its page
     * would render that form, readiness would still call it ready, and the
     * dashboard's missing-form check only looks for a null id, so nothing
     * would report it. Refuse the move and let the organiser choose a
     * different default first.
     *
     * @since 1.0.0
     */
    private function guardDefaultFormStays(Form $form): void
    {
        $campaign = Campaign::query()->find('id', (int) $form->campaign_id);
        if ($campaign === null || (int) ($campaign->default_form_id ?? 0) !== (int) $form->id) {
            return;
        }

        throw new InvalidArgumentException(esc_html(sprintf(
            /* translators: %s: campaign title. */
            __('This is the default donation form for %s, so it cannot be moved. Make another form the default first.', 'gratora-donation-platform'),
            $campaign->title
        )));
    }
}
