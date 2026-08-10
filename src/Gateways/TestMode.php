<?php

declare(strict_types=1);

namespace Dono\Gateways;

use Dono\Forms\Form;
use Dono\Forms\FormRepository;

/**
 * Single resolver for "is this donation a test". A donation is test when its
 * form opts in (form.settings.test_mode) or the org flips the global kill switch
 * (dono_gateway_config.test_mode). Once created, a donation carries its own
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

        $cfg = get_option('dono_gateway_config', []);

        return is_array($cfg) && ! empty($cfg['test_mode']);
    }

    /** @since 1.0.0 */
    public function forFormId(?int $formId): bool
    {
        $form = ($formId !== null && $formId > 0) ? $this->forms->findById($formId) : null;

        return $this->forForm($form);
    }
}
