<?php

declare(strict_types=1);

namespace Gratora\Foundation\Translations;

use Gratora\Foundation\Hooks\HookProvider;

/**
 * The translations in languages/, for a site with no language pack from
 * WordPress.org. A regional variant with no files of its own, such as Mexican
 * Spanish or Austrian German, reads the shipped language closest to it.
 *
 * @since unreleased
 */
final class ShippedTranslations extends HookProvider
{
    private const DOMAIN = 'gratora-donation-platform';

    /** @since unreleased */
    protected function filters(): array
    {
        return [
            'load_textdomain_mofile'       => ['closestFile', 10, 2],
            'load_script_translation_file' => ['closestScriptFile', 10, 3],
        ];
    }

    /** @since unreleased */
    public function register(): void
    {
        $GLOBALS['wp_textdomain_registry']->set_custom_path(self::DOMAIN, self::directory());

        parent::register();
    }

    /**
     * The shipped language a locale without files of its own reads, or null.
     *
     * @since unreleased
     */
    public static function closest(string $locale): ?string
    {
        if (str_starts_with($locale, 'es_')) {
            return 'es_ES';
        }
        if (str_starts_with($locale, 'de_')) {
            // WordPress's Swiss German addresses the reader as "Sie", as every _formal variant does.
            return $locale === 'de_CH' || str_ends_with($locale, '_formal') ? 'de_DE_formal' : 'de_DE';
        }

        return null;
    }

    /** @since unreleased */
    public function closestFile(mixed $file, mixed $domain): mixed
    {
        if ($domain !== self::DOMAIN || ! is_string($file)) {
            return $file;
        }

        $ours = wp_normalize_path(self::directory()) . '/' . self::DOMAIN . '-';
        $path = wp_normalize_path($file);
        if (! str_starts_with($path, $ours) || is_readable($path)) {
            return $file;
        }

        // What follows the domain is the locale, then ".mo" or "-<bundle>.json".
        $rest    = substr($path, strlen($ours));
        $locale  = (string) strtok($rest, '-.');
        $closest = self::closest($locale);
        if ($closest === null || $closest === $locale) {
            return $file;
        }

        $candidate = $ours . $closest . substr($rest, strlen($locale));

        return is_readable($candidate) ? $candidate : $file;
    }

    /** @since unreleased */
    public function closestScriptFile(mixed $file, mixed $handle, mixed $domain): mixed
    {
        return $this->closestFile($file, $domain);
    }

    private static function directory(): string
    {
        return GRATORA_DIR . 'languages';
    }
}
