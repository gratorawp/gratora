<?php

declare(strict_types=1);

namespace FundKit\Gateways;

use FundKit\Forms\Form;
use FundKit\Forms\FormRepository;

/**
 * Single resolver for "is this donation a test". A donation is test when its
 * form opts in (form.settings.test_mode) or the org flips the global kill switch
 * (fundkit_gateway_config.test_mode). Once created, a donation carries its own
 * is_test; every later step reads that, never re-resolves.
 *
 * @since 1.0.0
 */
final class TestMode
{
    /** @since 1.0.0 */
    public function __construct(private FormRepository $forms)
    {
    }

    /** @since 1.0.0 */
    public function forForm(?Form $form): bool
    {
        if ($form !== null) {
            $settings = is_array($form->settings ?? null) ? $form->settings : [];
            if (! empty($settings['test_mode'])) {
                return true;
            }
        }

        return self::siteWide();
    }

    /**
     * The site-wide switch, for a question with no form and no donation in it.
     *
     * Static because the callers that need it are answering "what is this
     * install doing right now" rather than working on a record: the donor
     * picker asking a gateway which currencies it can settle is the one that
     * matters, and it has nothing to resolve a form from.
     *
     * @since 1.0.0
     */
    public static function siteWide(): bool
    {
        $cfg = get_option('fundkit_gateway_config', []);

        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /** @since 1.0.0 */
    public function forFormId(?int $formId): bool
    {
        $form = ($formId !== null && $formId > 0) ? $this->forms->findById($formId) : null;

        return $this->forForm($form);
    }
}
