<?php

declare(strict_types=1);

namespace Gratora\Forms;

use MO;

/**
 * Unticks the cover-the-fee box, once, on saved forms that still carry the
 * wording a released template gave it, and gives them the current label. A
 * label the site owner wrote is left as it is.
 *
 * @since 1.2.4
 */
final class FeeBoxDefaults
{
    public const DONE = 'gratora_fee_box_defaults_done';

    private const LABEL = 'Add {fee} to cover processing fees';

    /** WordPress's own block comment grammar, for the self-closing fee box. */
    private const BLOCK = '#<!--\s+wp:gratora/cover-fees\s+(?<attrs>\{(?:[^}]+|\}+(?=\})|(?!\}\s+/?-->).)*+\})\s+/-->#s';

    /**
     * The labels core's templates shipped from 1.0.0 to 1.2.3, as they were
     * stored: in English, or in the shipped German or Spanish when the form was
     * made in that language. Both German catalogs worded them the same.
     */
    private const SHIPPED = [
        '' => [
            "I'll cover the fees so 100% goes to the cause",
            'Cover the processing fee so the full amount reaches us',
            'Cover the processing fee so the full amount reaches the response',
            'Cover the processing fee so the full amount reaches this fund',
            'Cover the processing fee so 100% reaches the campaign',
            'Cover the processing fee on each payment',
            "I'll cover the processing fee so 100% of my donation goes to the mission",
        ],
        'de_DE' => [
            'Ich übernehme die Gebühren, damit 100 % dem guten Zweck zugutekommen',
            'Transaktionsgebühr übernehmen, damit der volle Betrag bei uns ankommt',
            'Transaktionsgebühr übernehmen, damit der volle Betrag der Nothilfe zugutekommt',
            'Transaktionsgebühr übernehmen, damit der volle Betrag diesem Fonds zugutekommt',
            'Transaktionsgebühr übernehmen, damit 100 % bei der Kampagne ankommen',
            'Transaktionsgebühr bei jeder Zahlung übernehmen',
            'Ich übernehme die Transaktionsgebühr, damit 100 % meiner Spende dem guten Zweck zugutekommen',
        ],
        'es_ES' => [
            "Cubriré las comisiones para que el 100\u{a0}% llegue a la causa",
            'Cubrir la comisión de procesamiento para que nos llegue el importe íntegro',
            'Cubrir la comisión de procesamiento para que el importe íntegro llegue a la respuesta de emergencia',
            'Cubrir la comisión de procesamiento para que el importe íntegro llegue a este fondo',
            'Cubrir la comisión de procesamiento para que el 100% llegue a la campaña',
            'Cubrir la comisión de procesamiento en cada pago',
            'Cubriré la comisión de procesamiento para que el 100% de mi donación vaya a la misión',
        ],
    ];

    /**
     * On wp_loaded, so the first request after an update reaches it.
     *
     * @since 1.2.4
     */
    public static function maybeRun(): void
    {
        if (get_option(self::DONE, false) !== false) {
            return;
        }

        try {
            self::run();
        } catch (\Throwable $e) {
            // Left unrecorded: the next request tries again from the top.
            return;
        }

        update_option(self::DONE, gmdate('Y-m-d H:i:s'), true);
    }

    private static function run(): void
    {
        $forms = Form::query()->where('blocks', '%wp:gratora/cover-fees%', 'LIKE')->getAll();

        foreach ($forms as $form) {
            $blocks = (string) $form->blocks;
            $next   = self::untick($blocks);

            if ($next !== $blocks) {
                Form::query()->where('id', (int) $form->id)->update(['blocks' => $next]);
            }
        }
    }

    private static function untick(string $blocks): string
    {
        return (string) preg_replace_callback(self::BLOCK, static function (array $m): string {
            $attrs  = json_decode($m['attrs'], true);
            $locale = is_array($attrs) ? self::shippedIn((string) ($attrs['label'] ?? '')) : null;

            if ($locale === null) {
                return $m[0];
            }

            $attrs['label']     = self::label($locale);
            $attrs['defaultOn'] = false;

            return '<!-- wp:gratora/cover-fees ' . serialize_block_attributes($attrs) . ' /-->';
        }, $blocks);
    }

    private static function shippedIn(string $label): ?string
    {
        $decoded = html_entity_decode($label, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        foreach (self::SHIPPED as $locale => $labels) {
            if (in_array($label, $labels, true) || in_array($decoded, $labels, true)) {
                return $locale;
            }
        }

        return null;
    }

    /** The current label in the language the form was made in, from the shipped catalog. */
    private static function label(string $locale): string
    {
        $file = GRATORA_DIR . 'languages/gratora-donation-platform-' . $locale . '.mo';
        $mo   = new MO();

        if ($locale === '' || ! is_readable($file) || ! $mo->import_from_file($file)) {
            return self::LABEL;
        }

        return $mo->translate(self::LABEL);
    }
}
