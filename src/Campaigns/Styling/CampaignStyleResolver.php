<?php

declare(strict_types=1);

namespace FundKit\Campaigns\Styling;

use FundKit\Campaigns\Campaign;
use FundKit\Forms\Form;

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

        $presetTokens = StylePresets::tokensFor($presetId);
        $tokens = array_merge($tokens, $presetTokens);

        if ($formPresetId === '' && ! empty($campaignInline)) {
            $tokens = array_merge($tokens, $campaignInline);
        }

        $tokens = (array) apply_filters('fundkit.form_style.tokens', $tokens, $form, $campaign);

        $explicitSoft = isset($presetTokens['fundkit-accent-soft'])
            || ($formPresetId === '' && isset($campaignInline['fundkit-accent-soft']));
        $tokens = $this->dropUnpairedSoft($tokens, $explicitSoft);

        $inline  = $formPresetId === '' ? $campaignInline : [];
        $tokens  = $this->inkFollowsGround($tokens, $presetTokens, $inline);

        return [
            'tokens'        => $tokens,
            'accent'        => (string) ($tokens['fundkit-accent'] ?? '#211d3f'),
            'preset_id'     => $presetId,
            'preset_seeded' => null,
        ];
    }

    /** @since 1.0.0 */
    public function accentFor(?Campaign $campaign): string
    {
        $tokens = $this->resolveForCampaign($campaign);
        return (string) ($tokens['fundkit-accent'] ?? '#211d3f');
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
        $presetTokens = StylePresets::tokensFor($presetId);
        $tokens       = array_merge($tokens, $presetTokens);

        if (! empty($campaignInline)) {
            $tokens = array_merge($tokens, $campaignInline);
        }

        $tokens = (array) apply_filters('fundkit.campaign_style.tokens', $tokens, $campaign);

        $explicitSoft = isset($presetTokens['fundkit-accent-soft'])
            || isset($campaignInline['fundkit-accent-soft']);

        return $this->inkFollowsGround(
            $this->dropUnpairedSoft($tokens, $explicitSoft),
            $presetTokens,
            $campaignInline
        );
    }

    /**
     * Accent-soft (selected/hover tint) must track the accent. When nothing
     * deliberately pairs one with the accent and it is still the catalogue
     * default, drop it so the stylesheet derives it from the resolved
     * --fundkit-accent via color-mix. Otherwise a campaign that picks a purple
     * accent keeps the green default soft and washes its own page in green.
     * Presets that pair their own soft (Bold, Quiet) keep theirs.
     *
     * @param array<string,string> $tokens
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function dropUnpairedSoft(array $tokens, bool $explicitSoft): array
    {
        $defaults = Tokens::defaults();
        if (! $explicitSoft
            && ($tokens['fundkit-accent-soft'] ?? null) === ($defaults['fundkit-accent-soft'] ?? null)
        ) {
            unset($tokens['fundkit-accent-soft']);
        }
        return $tokens;
    }

    /**
     * Body and muted ink track the background the same way accent-soft tracks
     * the accent: the shipped values are chosen against a white page, so an org
     * that colours the ground and says nothing about the ink gets #111827 on
     * whatever it picked. Measured here rather than in CSS, which cannot read
     * a colour's luminance, and only when no layer chose ink of its own.
     *
     * @param array<string,string> $tokens
     * @param array<string,string> $presetTokens
     * @param array<string,string> $inline
     * @return array<string,string>
     *
     * @since 1.0.0
     */
    private function inkFollowsGround(array $tokens, array $presetTokens, array $inline): array
    {
        $defaults = Tokens::defaults();
        $ground   = (string) ($tokens['fundkit-bg'] ?? '');

        if ($ground === '' || $ground === ($defaults['fundkit-bg'] ?? null)) {
            return $tokens;
        }

        $on = Ink::on($ground);
        if ($on === null) {
            return $tokens;
        }

        foreach (['fundkit-text' => 0, 'fundkit-text-muted' => 1] as $key => $slot) {
            $chosen = isset($presetTokens[$key]) || isset($inline[$key]);
            if (! $chosen && ($tokens[$key] ?? null) === ($defaults[$key] ?? null)) {
                $tokens[$key] = $on[$slot];
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
