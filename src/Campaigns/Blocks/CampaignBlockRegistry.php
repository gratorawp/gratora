<?php

declare(strict_types=1);

namespace Gratora\Campaigns\Blocks;

/** @since 1.1.0 */
final class CampaignBlockRegistry
{
    /** @var array<string, CampaignBlock|CampaignFormBlock> */
    private array $blocks = [];

    /** @since 1.1.0 */
    public function add(CampaignBlock|CampaignFormBlock $block): void
    {
        $this->blocks[$block->name()] = $block;
    }

    /** @since 1.1.0 */
    public function register(): void
    {
        foreach ($this->blocks as $name => $block) {
            register_block_type($name, [
                'attributes'      => $block->attributes() + ['condition' => ['type' => 'object', 'default' => null]],
                'supports'        => $block->supports(),
                'render_callback' => $block instanceof CampaignFormBlock
                    ? static function (array $attrs) use ($block): string {
                        $embed = $block->render($attrs);

                        // esc_attr leaves an existing entity alone, and the
                        // browser decodes the attribute before parsing the
                        // frame, so text the document escaped would become
                        // markup. Encode every ampersand first.

                        return wp_kses($embed->before, CampaignMarkup::allowedHtml())
                            . ($embed->formSlug === ''
                                ? ''
                                : do_shortcode('[gratora_donation_form slug="' . esc_attr($embed->formSlug) . '"]'))
                            . ($embed->previewDocument === ''
                                ? ''
                                : '<iframe class="gratora-donation-form__editor-preview" title="' . esc_attr($embed->previewTitle) . '" loading="lazy" style="width:100%;border:0;display:block;min-height:520px" srcdoc="' . esc_attr(str_replace('&', '&amp;', $embed->previewDocument)) . '"></iframe>')
                            . wp_kses($embed->after, CampaignMarkup::allowedHtml());
                    }
                    : static fn (array $attrs, string $content): string => wp_kses($block->render($attrs, $content), CampaignMarkup::allowedHtml()),
            ]);
        }
    }
}
