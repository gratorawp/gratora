<?php

declare(strict_types=1);

namespace Dono\Donors\Portal;

use WP_Post;

/**
 * Guarantees the donor-portal WP page (hosts [dono_donor_portal]) exists and is
 * published: magic-link emails point at its URL, so a missing page silently breaks
 * donor self-service. url() is the single source of truth unless dono.portal.url is set.
 *
 * @since 1.0.0
 */
final class PortalPage
{
    public const OPTION_PAGE_ID = 'dono_portal_page_id';
    public const OPTION_VERSION = 'dono_portal_page_version';
    public const SLUG           = 'donor-portal';
    public const META_MANAGED   = '_dono_managed_portal';
    public const SHORTCODE      = '[dono_donor_portal]';

    /**
     * Idempotent: keeps a stored id that still resolves to a published page, else
     * adopts an existing page at the canonical slug, else inserts a fresh one.
     *
     * @since 1.0.0
     */
    public function ensure(): int
    {
        $existing = $this->resolve();
        if ($existing > 0) {
            return $existing;
        }

        $bySlug = get_page_by_path(self::SLUG, OBJECT, 'page');
        if ($bySlug instanceof WP_Post && $bySlug->post_status === 'publish') {
            update_option(self::OPTION_PAGE_ID, (int) $bySlug->ID, false);
            return (int) $bySlug->ID;
        }

        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_title'   => __('Donor portal', 'dono-fundraising-platform'),
            'post_name'    => self::SLUG,
            'post_status'  => 'publish',
            'post_content' => self::SHORTCODE,
            'comment_status' => 'closed',
            'ping_status'    => 'closed',
        ], true);

        if (is_wp_error($id) || (int) $id <= 0) {
            return 0;
        }

        update_post_meta((int) $id, self::META_MANAGED, '1');
        update_option(self::OPTION_PAGE_ID, (int) $id, false);
        return (int) $id;
    }

    /**
     * Returns the published portal-page id, or 0 if the stored id is missing,
     * trashed, draft, or a non-page post type.
     *
     * @since 1.0.0
     */
    public function resolve(): int
    {
        $id = (int) get_option(self::OPTION_PAGE_ID, 0);
        if ($id <= 0) {
            return 0;
        }
        $post = get_post($id);
        if (! $post instanceof WP_Post) {
            return 0;
        }
        if ($post->post_type !== 'page' || $post->post_status !== 'publish') {
            return 0;
        }
        return $id;
    }

    /**
     * Canonical portal URL. The `dono.portal.url` filter overrides; otherwise
     * the URL is the permalink of the stored page, or the slug-based
     * home_url() fallback while the page is being provisioned.
     *
     * @since 1.0.0
     */
    public function url(): string
    {
        $filtered = (string) apply_filters('dono.portal.url', '');
        if ($filtered !== '') {
            return $filtered;
        }

        $id = $this->resolve();
        if ($id > 0) {
            $url = (string) get_permalink($id);
            if ($url !== '') {
                return $url;
            }
        }

        return home_url('/' . self::SLUG . '/');
    }

    /**
     * Heal pass for plugin updates: register_activation_hook does not fire
     * on updates, so we re-run ensure() once per DONO_VERSION bump. Steady
     * state is a single option read.
     *
     * @since 1.0.0
     */
    public function maybeHeal(): void
    {
        if (get_option(self::OPTION_VERSION) === DONO_VERSION) {
            return;
        }
        $this->ensure();
        update_option(self::OPTION_VERSION, DONO_VERSION, false);
    }
}
