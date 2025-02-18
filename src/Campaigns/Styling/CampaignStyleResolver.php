<?php

declare(strict_types=1);

namespace Dono\Campaigns\Styling;

use Dono\Campaigns\Campaign;
use Dono\Forms\Form;

/**
 * Resolve the final token map for a form rendering.
 *
 * Cascade: defaults -> resolved preset.tokens -> campaign inline overrides
 * (campaign inline overrides are skipped when the form picked its own preset).
 *
 * @version 1.0.0
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
     */
    public function resolve(Form $form, ?Campaign $campaign = null): array
    {
        $defaults = Tokens::defaults();
        $tokens   = $defaults;

        $formPresetId    = $this->formPresetId($form);
        $campaignStyle   = $campaign && is_array($campaign->style) ? $campaign->style : [];
        $campaignPreset  = (string) ($campaignStyle['preset_id'] ?? '');
        $campaignInline  = $this->inlineTokens($campaignStyle);

        $presetId = $formPresetId !== ''
            ? $formPresetId
            : ($campaignPreset !== '' ? $campaignPreset : StylePresets::defaultId());

        $presetTokens = StylePresets::tokensFor($presetId);
        $tokens = array_merge($tokens, $presetTokens);

        if ($formPresetId === '' && ! empty($campaignInline)) {
            $tokens = array_merge($tokens, $campaignInline);
        }

        $tokens = (array) apply_filters('dono.form_style.tokens', $tokens, $form, $campaign);

        // Accent-soft (selected/hover tint) must track the accent. When no
        // preset/inline override deliberately pairs one with the accent and
        // it is still the catalogue default, drop it so the runtime CSS
        // derives it from the resolved --dono-accent via color-mix. Presets
        // that pair their own soft (e.g. Bold/Quiet) keep theirs.
        $explicitSoft = isset($presetTokens['dono-accent-soft'])
            || ($formPresetId === '' && isset($campaignInline['dono-accent-soft']));
        if (! $explicitSoft
            && ($tokens['dono-accent-soft'] ?? null) === ($defaults['dono-accent-soft'] ?? null)
        ) {
            unset($tokens['dono-accent-soft']);
        }

        return [
            'tokens'        => $tokens,
            'accent'        => (string) ($tokens['dono-accent'] ?? '#1e8a4e'),
            'preset_id'     => $presetId,
            'preset_seeded' => null,
        ];
    }

    /** Accent color for a campaign, used by block renderers. */
    public function accentFor(?Campaign $campaign): string
    {
        $tokens = $this->resolveForCampaign($campaign);
        return (string) ($tokens['dono-accent'] ?? '#1e8a4e');
    }

    /**
     * Resolve tokens for a campaign with no form context.
     *
     * @return array<string,string>
     */
    public function resolveForCampaign(?Campaign $campaign): array
    {
        $tokens = Tokens::defaults();

        $style          = $campaign && is_array($campaign->style) ? $campaign->style : [];
        $campaignPreset = (string) ($style['preset_id'] ?? '');
        $campaignInline = $this->inlineTokens($style);

        $presetId = $campaignPreset !== '' ? $campaignPreset : StylePresets::defaultId();
        $tokens   = array_merge($tokens, StylePresets::tokensFor($presetId));

        if (! empty($campaignInline)) {
            $tokens = array_merge($tokens, $campaignInline);
        }

        return (array) apply_filters('dono.campaign_style.tokens', $tokens, $campaign);
    }

    /** Pull a preset id from form settings, normalised to '' when unset. */
    private function formPresetId(Form $form): string
    {
        $style = is_array($form->settings['style'] ?? null) ? $form->settings['style'] : [];
        return (string) ($style['preset_id'] ?? '');
    }

    /**
     * Extract a sanitised inline token map from a campaign.style array.
     * Accepts both the canonical `{ preset_id, tokens: {...} }` shape and the
     * legacy flat token-map-at-root shape.
     *
     * @param array<string,mixed> $style
     * @return array<string,string>
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
