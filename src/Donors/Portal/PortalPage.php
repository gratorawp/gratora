<?php

declare(strict_types=1);

namespace Gratora\Donors\Portal;

use WP_Post;

/**
 * Keeps the donor-portal WP page (hosts [gratora_donor_portal]) published: magic-link
 * emails point at its URL, so a missing page silently breaks donor self-service. A page
 * its owner unpublished or binned is left as it is. url() is the single source of truth
 * unless gratora.portal.url is set.
 *
 * @since 1.0.0
 */
final class PortalPage
{
    public const OPTION_PAGE_ID = 'gratora_portal_page_id';
    public const OPTION_VERSION = 'gratora_portal_page_version';
    public const SLUG           = 'donor-portal';
    public const META_MANAGED   = '_gratora_managed_portal';
    public const SHORTCODE      = '[gratora_donor_portal]';

    /**
     * Idempotent: keeps a stored id that still resolves to a published page, else
     * adopts an existing page at the canonical slug, else inserts a fresh one unless
     * the stored page is only hidden.
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

        if ($this->hiddenPage() !== null) {
            return 0;
        }

        $id = wp_insert_post([
            'post_type'    => 'page',
            'post_title'   => __('Donor portal', 'gratora-donation-platform'),
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
        $page = $this->stored();

        return $page !== null && $page->post_status === 'publish' ? (int) $page->ID : 0;
    }

    /**
     * The stored page while its owner keeps it unpublished or in the bin.
     *
     * @unreleased
     */
    public function hiddenPage(): ?WP_Post
    {
        $page = $this->stored();

        return $page !== null && $page->post_status !== 'publish' ? $page : null;
    }

    /** @unreleased */
    private function stored(): ?WP_Post
    {
        $id = (int) get_option(self::OPTION_PAGE_ID, 0);
        // get_post(0) answers with the page being shown.
        $post = $id > 0 ? get_post($id) : null;

        return $post instanceof WP_Post && $post->post_type === 'page' ? $post : null;
    }

    /**
     * Canonical portal URL. The `gratora.portal.url` filter overrides; otherwise
     * the URL is the permalink of the stored page, or the slug-based
     * home_url() fallback while the page is being provisioned.
     *
     * @since 1.0.0
     */
    public function url(): string
    {
        $filtered = $this->filteredUrl();
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
     * The address a site supplies through `gratora.portal.url` in place of the page's own.
     *
     * @unreleased
     */
    public function filteredUrl(): string
    {
        return (string) apply_filters('gratora.portal.url', '');
    }

    /**
     * Heal pass for plugin updates: register_activation_hook does not fire
     * on updates, so we re-run ensure() once per GRATORA_VERSION bump. Steady
     * state is a single option read.
     *
     * @since 1.0.0
     */
    public function maybeHeal(): void
    {
        if (get_option(self::OPTION_VERSION) === GRATORA_VERSION) {
            return;
        }
        $this->ensure();
        update_option(self::OPTION_VERSION, GRATORA_VERSION, false);
    }
}
