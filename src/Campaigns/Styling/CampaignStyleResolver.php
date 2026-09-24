<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Styling;

use Gratora\Campaigns\Campaign;
use Gratora\Forms\Form;

/**
 * Resolve the final token map for a form rendering.
 *
 * Cascade: defaults -> resolved preset.tokens -> campaign inline overrides
 * (campaign inline overrides are skipped when the form picked its own preset).
 *
 * @since 1.0.0
 */
final class CampaignStyleResolver
{
    /**
     * @return array{
     *   tokens: array<string,string>,
     *   accent: string,
     *   preset_id: string,
     *   preset_seeded: ?string
     * }
     *
     * @since 1.0.0
     */
    public function resolve(Form $form, ?Campaign $campaign = null): array
    {
        $defaults = Tokens::defaults();
        $tokens   = $defaults;

        // An id nothing answers to is a preset deleted while the form still
        // named it. Treated as a choice it kept gating out the campaign's own
        // overrides, so the page rendered the campaign's colour and the form
        // beside it the org default.
        $formPresetId    = $this->formPresetId($form);
        if ($formPresetId !== '' && StylePresets::find($formPresetId) === null) {
            $formPresetId = '';
        }
        $campaignStyle   = $campaign && is_array($campaign->style) ? $campaign->style : [];
        $campaignPreset  = (string) ($campaignStyle['preset_id'] ?? '');
        $campaignInline  = $this->inlineTokens($campaignStyle);

        $presetId = $formPresetId !== ''
            ? $formPresetId
            : ($campaignPreset !== '' ? $campaignPreset : StylePresets::defaultId());

        $presetLayers = StylePresets::tokenLayers($presetId);
        $presetTokens = StylePresets::tokensFor($presetId);
        $tokens = array_merge($tokens, $presetTokens);

        if ($formPresetId === '' && ! empty($campaignInline)) {
            $tokens = array_merge($tokens, $campaignInline);
        }

        $tokens = (array) apply_filters('gratora.form_style.tokens', $tokens, $form, $campaign);

        $inline = $formPresetId === '' ? $campaignInline : [];
        $tokens = $this->dropStalePairs($tokens, array_merge($presetLayers, [$inline]));

        return [
            'tokens'        => $tokens,
            'accent'        => (string) ($tokens['gratora-accent'] ?? '#211d3f'),
            'preset_id'     => $presetId,
            'preset_seeded' => null,
        ];
    }

    /** @since 1.0.0 */
    public function accentFor(?Campaign $campaign): string
    {
        // Sanitised, as CampaignStyleVars does for the whole map: the filter
        // runs after the allowlist, and this value is printed into style
        // attributes that safecss_filter_attr never sees.
        $tokens = Tokens::sanitize($this->resolveForCampaign($campaign));

        return (string) ($tokens['gratora-accent'] ?? '#211d3f');
    }

    /**
     * Resolve tokens for a campaign with no form context.
     *
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    public function resolveForCampaign(?Campaign $campaign): array
    {
        $tokens = Tokens::defaults();

        $style          = $campaign && is_array($campaign->style) ? $campaign->style : [];
        $campaignPreset = (string) ($style['preset_id'] ?? '');
        $campaignInline = $this->inlineTokens($style);

        $presetId     = $campaignPreset !== '' ? $campaignPreset : StylePresets::defaultId();
        $presetLayers = StylePresets::tokenLayers($presetId);
        $presetTokens = StylePresets::tokensFor($presetId);
        $tokens       = array_merge($tokens, $presetTokens);

        if (! empty($campaignInline)) {
            $tokens = array_merge($tokens, $campaignInline);
        }

        $tokens = (array) apply_filters('gratora.campaign_style.tokens', $tokens, $campaign);

        return $this->dropStalePairs($tokens, array_merge($presetLayers, [$campaignInline]));
    }

    /**
     * The selected/hover tint and the focus ring belong to the accent they were
     * chosen beside. A later layer that repaints the accent and says nothing
     * about them leaves them to the stylesheet, which derives both from the
     * resolved --gratora-accent: otherwise a campaign on Bold that picks a red
     * accent keeps Bold's navy tint on its selected tiles and a navy ring
     * around a red halo. One nothing chose goes the same way; one only a filter
     * left stands.
     *
     * @param array<string,string> $tokens
     * @param array<int,array<string,string>> $layers preset first, later wins
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function dropStalePairs(array $tokens, array $layers): array
    {
        $defaults = Tokens::defaults();
        $resolved = (string) ($tokens['gratora-accent'] ?? '');

        foreach (['gratora-accent-soft', 'gratora-focus-ring'] as $key) {
            $accent     = (string) ($defaults['gratora-accent'] ?? '');
            $pairedWith = null;
            foreach ($layers as $layer) {
                if (isset($layer['gratora-accent'])) {
                    $accent = (string) $layer['gratora-accent'];
                }
                if (isset($layer[$key])) {
                    $pairedWith = $accent;
                }
            }

            // Case-insensitive: the built-ins carry uppercase hex and the colour
            // control writes lowercase, so one colour arrives spelled two ways.
            $stale = $pairedWith !== null
                ? strcasecmp($pairedWith, $resolved) !== 0
                : ($tokens[$key] ?? null) === ($defaults[$key] ?? null);

            if ($stale) {
                unset($tokens[$key]);
            }
        }

        return $tokens;
    }

    /**
     * Pull a preset id from form settings, normalized to '' when unset.
     *
     * @since 1.0.0
     */
    private function formPresetId(Form $form): string
    {
        $style = is_array($form->settings['style'] ?? null) ? $form->settings['style'] : [];
        return (string) ($style['preset_id'] ?? '');
    }

    /**
     * Extract a sanitized inline token map from a campaign.style array.
     * Accepts both the canonical `{ preset_id, tokens: {...} }` shape and a
     * flat token map at root.
     *
     * @param array<string,mixed> $style
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function inlineTokens(array $style): array
    {
        if (is_array($style['tokens'] ?? null)) {
            return Tokens::sanitize($style['tokens']);
        }
        // Flat token map at root (no preset_id wrapper).
        if (! isset($style['preset_id'])) {
            return Tokens::sanitize($style);
        }
        return [];
    }
}
