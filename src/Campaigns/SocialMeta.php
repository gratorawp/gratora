<?php

declare(strict_types=1);

namespace Dono\Campaigns;

use Dono\Foundation\Hooks\HookProvider;

/**
 * Prints Open Graph + Twitter card meta on a campaign's main page so shared
 * links unfurl with the campaign title, summary, and image. Add-ons reshape
 * the tag set per route via the dono.social_meta filter (P2P swaps in the
 * fundraiser's or team's own meta). A detected SEO plugin owns social meta,
 * so we stand down unless dono.social_meta.enabled opts back in.
 *
 * @version 1.0.0
 */
final class SocialMeta extends HookProvider
{
    public function __construct(private CampaignRepository $campaigns)
    {
    }

    protected function actions(): array
    {
        return [
            'wp_head' => ['printTags', 5],
        ];
    }

    public function printTags(): void
    {
        if (! $this->enabled()) {
            return;
        }
        $campaign = $this->currentCampaign();
        if ($campaign === null) {
            return;
        }

        $pageId = get_queried_object_id();
        $tags   = apply_filters('dono.social_meta', $this->tagsFor($campaign, $pageId), [
            'campaign' => $campaign,
            'page_id'  => $pageId,
        ]);
        if (! is_array($tags) || $tags === []) {
            return;
        }

        foreach ($tags as $key => $value) {
            $value = trim((string) $value);
            if ($value === '') {
                continue;
            }
            $content = in_array($key, ['og:url', 'og:image'], true) ? esc_url($value) : esc_attr($value);
            $attr    = str_starts_with((string) $key, 'og:') ? 'property' : 'name';
            echo '<meta ' . $attr . '="' . esc_attr((string) $key) . '" content="' . $content . '">' . "\n";
        }
    }

    /** @return array<string,string> */
    private function tagsFor(Campaign $campaign, int $pageId): array
    {
        $description = self::excerptText((string) ($campaign->description ?? ''));
        if ($description === '') {
            $page = get_post($pageId);
            if ($page instanceof \WP_Post) {
                $description = self::excerptText($page->post_excerpt !== '' ? $page->post_excerpt : $page->post_content);
            }
        }

        $image = '';
        if ($campaign->image_attachment_id) {
            $image = (string) (wp_get_attachment_image_url((int) $campaign->image_attachment_id, 'large') ?: '');
        }
        if ($image === '') {
            $image = (string) (get_the_post_thumbnail_url($pageId, 'large') ?: '');
        }

        $tags = [
            'og:type'      => 'website',
            'og:url'       => (string) (get_permalink($pageId) ?: ''),
            'og:title'     => $campaign->title,
            // Decoded first: WordPress stores blogname esc_html'd, and every
            // value here is escaped again on output, so an ampersand in the
            // site name shipped as &amp;amp; in the tag.
            'og:site_name' => wp_specialchars_decode((string) get_bloginfo('name'), ENT_QUOTES),
        ];
        if ($description !== '') {
            $tags['og:description'] = $description;
        }
        if ($image !== '') {
            $tags['og:image'] = $image;
        }
        $tags['twitter:card']  = $image !== '' ? 'summary_large_image' : 'summary';
        $tags['twitter:title'] = $campaign->title;
        if ($description !== '') {
            $tags['twitter:description'] = $description;
        }
        return $tags;
    }

    /**
     * The campaign whose MAIN page is being viewed. Layout subpages (P2P
     * fundraiser/team/start layouts) carry _dono_campaign_id too, so the page
     * id must also equal the campaign's own page_id. Virtual add-on routes
     * (fundraiser/team) resolve to the campaign page and pass this gate; the
     * dono.social_meta filter then reshapes the tags for them.
     */
    private function currentCampaign(): ?Campaign
    {
        if (! is_page()) {
            return null;
        }
        $pageId = get_queried_object_id();
        if ($pageId <= 0) {
            return null;
        }
        $campaignId = (int) get_post_meta($pageId, '_dono_campaign_id', true);
        if ($campaignId <= 0) {
            return null;
        }
        $campaign = $this->campaigns->findRenderable($campaignId);
        if ($campaign === null || (int) $campaign->page_id !== $pageId) {
            return null;
        }
        return $campaign;
    }

    private function enabled(): bool
    {
        $seoActive = defined('WPSEO_VERSION')
            || class_exists('RankMath')
            || defined('AIOSEO_VERSION')
            || defined('SEOPRESS_VERSION');
        return (bool) apply_filters('dono.social_meta.enabled', ! $seoActive);
    }

    /** Plain-text share description: shortcodes and tags stripped, whitespace collapsed, capped near 200 chars. */
    public static function excerptText(string $text): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags(strip_shortcodes($text))));
        if (mb_strlen($text) <= 200) {
            return $text;
        }
        $cut   = mb_substr($text, 0, 200);
        $space = mb_strrpos($cut, ' ');
        if ($space !== false && $space > 120) {
            $cut = mb_substr($cut, 0, $space);
        }
        return rtrim($cut, ' .,;:') . '...';
    }
}
