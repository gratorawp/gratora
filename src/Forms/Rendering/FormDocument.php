<?php

declare(strict_types=1);

namespace Gratora\Forms\Rendering;

use Gratora\Forms\Form;
use Gratora\Forms\Shortcode\DonationFormShortcode;

/**
 * A donation form's markup together with the assets that hydrate it.
 *
 * @since 1.1.0
 */
final class FormDocument
{
    /** @since 1.1.0 */
    public function __construct(private DonationFormShortcode $shortcode)
    {
    }

    /**
     * @return array{html:string, cssUrl:string, jsUrl:string, jsDeps:list<string>}
     *
     * @since 1.1.0
     */
    public function forForm(Form $form): array
    {
        $assetPath = GRATORA_DIR . 'build/donation-form/runtime/index.asset.php';
        $asset     = is_file($assetPath) ? include $assetPath : ['dependencies' => [], 'version' => GRATORA_VERSION];

        return [
            'html'   => $this->shortcode->renderBlocks($form),
            'cssUrl' => GRATORA_URL . 'build/donation-form/' . self::cssFileName() . '?v=' . self::cssVersion(),
            'jsUrl'  => GRATORA_URL . 'build/donation-form/runtime/index.js?v=' . ($asset['version'] ?? GRATORA_VERSION),
            'jsDeps' => (array) ($asset['dependencies'] ?? []),
        ];
    }

    /**
     * Resolve transitive script dependencies in load order; srcdoc has no WordPress queue to do
     * this.
     *
     * @param list<string> $handles
     * @return list<string>
     *
     * @since 1.1.0
     */
    public static function withDependencies(array $handles): array
    {
        $scripts = wp_scripts();
        $seen    = [];
        $ordered = [];

        // Post-order: a handle is appended only after everything it needs.
        // $seen is set on entry, so a dependency cycle terminates instead of
        // recursing until the stack gives out.
        $walk = static function (string $handle) use (&$walk, $scripts, &$seen, &$ordered): void {
            if (isset($seen[$handle])) {
                return;
            }
            $seen[$handle] = true;

            $registered = $scripts->registered[$handle] ?? null;
            foreach ((array) ($registered->deps ?? []) as $dep) {
                $walk((string) $dep);
            }

            $ordered[] = $handle;
        };

        foreach ($handles as $handle) {
            $walk((string) $handle);
        }

        return $ordered;
    }

    /** @since 1.1.0 */
    public static function cssFileName(): string
    {
        return is_rtl() ? 'runtime-rtl.css' : 'runtime.css';
    }

    /**
     * Versioned by mtime so an SCSS-only rebuild busts a cached document.
     *
     * @since 1.1.0
     */
    public static function cssVersion(): string
    {
        $path = GRATORA_DIR . 'build/donation-form/runtime.css';

        return (string) (@filemtime($path) ?: GRATORA_VERSION);
    }
}
