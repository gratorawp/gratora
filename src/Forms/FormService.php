<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use Dono\Foundation\Time\Clock;
use InvalidArgumentException;

/**
 * Form CRUD. Lifecycle: draft, published, archived.
 *
 * @version 1.0.0
 */
final class FormService
{
    /**
     * Blocks a published form must contain.
     *
     * @var list<array{block:string,label:string}>
     */
    private const REQUIRED_BLOCKS = [
        ['block' => 'dono/donation-amount', 'label' => 'Amount'],
        ['block' => 'dono/name',            'label' => 'Name'],
        ['block' => 'dono/email',           'label' => 'Email'],
    ];

    /** @internal */
    public function __construct(
        private FormRepository $forms,
        private CampaignRepository $campaigns,
        private Clock $clock,
    ) {
    }

    /**
     * Blocks every published form must contain.
     *
     * @return list<array{block:string,label:string}>
     */
    public static function requiredBlocks(): array
    {
        return array_map(
            static fn (array $r): array => ['block' => $r['block'], 'label' => self::requiredLabel($r['label'])],
            self::REQUIRED_BLOCKS
        );
    }

    /** Translate the fixed required-block labels at read time (not const-time). */
    private static function requiredLabel(string $label): string
    {
        return match ($label) {
            'Amount' => __('Amount', 'dono'),
            'Name'   => __('Name', 'dono'),
            'Email'  => __('Email', 'dono'),
            default  => $label,
        };
    }

    /**
     * Required blocks absent from the given markup.
     *
     * @return list<array{block:string,label:string}>
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
     */
    public function create(array $input): Form
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $title = trim((string) ($input['title'] ?? ''));
        if ($title === '') {
            $title = __('Untitled donation form', 'dono');
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

        do_action('dono.form.created', $form);
        return $form;
    }

    /**
     * Keep settings.goal well-formed: a typed goal the Goal block displays.
     *
     * @param array<string,mixed>|null $settings
     * @return array<string,mixed>|null
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
     * The payment-gateways block is the single writer of
     * settings.gateways.allowed. No block leaves the existing value alone.
     */
    private function syncGatewayAllowed(Form $form): void
    {
        $allowed = $this->findGatewayAllowed(parse_blocks((string) $form->blocks));
        if ($allowed === null) {
            return;
        }

        $settings = is_array($form->settings) ? $form->settings : [];
        $gateways = is_array($settings['gateways'] ?? null) ? $settings['gateways'] : [];

        $gateways['allowed']  = $allowed;
        $settings['gateways'] = $gateways;
        $form->settings = $settings;
    }

    /**
     * Allowed list from the payment-gateways block, or null when absent.
     *
     * @param  array<int,array<string,mixed>> $blocks
     * @return list<string>|null
     */
    private function findGatewayAllowed(array $blocks): ?array
    {
        foreach ($blocks as $b) {
            if (($b['blockName'] ?? '') === 'dono/payment-gateways') {
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
                    throw new InvalidArgumentException(__('Invalid slug.', 'dono'));
                }
                if ($next !== $form->slug && $this->forms->slugExists($next, $form->id)) {
                    throw new InvalidArgumentException(__('Slug is already in use.', 'dono'));
                }
                $form->slug = $next;
            }
        }

        if (array_key_exists('campaign_id', $input)) {
            $campaign = $this->resolveCampaign($input['campaign_id']);
            $form->campaign_id = $campaign->id;
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
            $this->assertPublishable($form);
        }

        $form->updated_at = $now;
        $this->syncGatewayAllowed($form);
        $form->save();

        do_action('dono.form.updated', $form);
        return $form;
    }

    /**
     * Delete a form. Refuses when the form is the campaign default - admin
     * has to pick a different default first so the campaign isn't left in
     * a half-broken state.
     */
    public function delete(Form $form): void
    {
        $campaignId    = (int) $form->campaign_id;
        $defaultFormId = $campaignId > 0
            ? (int) (Campaign::query()->find('id', $campaignId)?->default_form_id ?? 0)
            : 0;

        if ($defaultFormId === (int) $form->id) {
            throw new InvalidArgumentException(
                __('This form is the campaign default. Pick a different default form before deleting it.', 'dono')
            );
        }

        Form::query()->where('id', $form->id)->delete();

        do_action('dono.form.deleted', $form);
    }

    /** Copy a form as a new draft. */
    public function duplicate(Form $source): Form
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        /* translators: %s: original form title */
        $title = sprintf(__('%s (copy)', 'dono'), $source->title);

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

        do_action('dono.form.duplicated', $copy, $source);
        return $copy;
    }

    /** Clamp an arbitrary status string to a known lifecycle state. */
    private function coerceStatus(string $status): string
    {
        $status = strtolower(trim($status));
        return in_array($status, ['draft', 'published', 'archived'], true) ? $status : 'draft';
    }

    /** Throw when the form is missing blocks required to publish. */
    private function assertPublishable(Form $form): void
    {
        $missing = self::missingRequiredBlocks((string) $form->blocks);
        if (! $missing) return;

        $labels = array_map(fn (array $m): string => $m['label'], $missing);
        throw new InvalidArgumentException(
            sprintf(
                /* translators: %s: comma-separated list of missing block labels (Amount, Name, Email). */
                __('A published donation form needs these blocks: %s.', 'dono'),
                implode(', ', $labels)
            )
        );
    }

    /** Resolve a required campaign id to a Campaign, or throw. */
    private function resolveCampaign(mixed $idOrNull): Campaign
    {
        $id = (int) ($idOrNull ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException(__('A campaign is required.', 'dono'));
        }
        $campaign = $this->campaigns->findById($id);
        if (! $campaign) {
            throw new InvalidArgumentException(__('Campaign not found.', 'dono'));
        }
        return $campaign;
    }

    /** Slugify the source and suffix it until it is unique. */
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
     */
    public function sanitizeBlocks(string $markup): string
    {
        if ($markup === '' || current_user_can('unfiltered_html')) {
            return $markup;
        }
        return serialize_blocks($this->ksesBlockList(parse_blocks($markup)));
    }

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
}
