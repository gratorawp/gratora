<?php

declare(strict_types=1);

namespace Gratora\Foundation\Helpers;

use InvalidArgumentException;

/**
 * Renders PHP templates from each module's resources/views directory.
 *
 * Dot-notation: View::load('Admin.dashboard') resolves to
 * src/Admin/resources/views/dashboard.php. $args are extracted into template scope.
 *
 * @since 1.0.0
 */
final class View
{
    /** @since 1.0.0 */
    public static function load(string $path, array $args = []): string
    {
        return self::renderFile(self::resolve($path), $args);
    }

    /**
     * Load a view relative to a caller-supplied base directory.
     * Use when the module layout does not match src/{Module}/resources/views/.
     *
     * @since 1.0.0
     */
    public static function loadRelative(string $baseDir, string $path, array $args = []): string
    {
        return self::renderFile(self::relative($baseDir, $path), $args);
    }

    /** @since 1.1.0 */
    public static function printRelative(string $baseDir, string $path, array $args = []): void
    {
        self::includeFile(self::relative($baseDir, $path), $args);
    }

    /** @since 1.0.0 */
    private static function renderFile(string $template, array $args): string
    {
        ob_start();

        try {
            self::includeFile($template, $args);
        } finally {
            $html = (string) ob_get_clean();
        }

        return $html;
    }

    /** @since 1.1.0 */
    private static function includeFile(string $template, array $args): void
    {
        if (! file_exists($template)) {
            throw new InvalidArgumentException(esc_html("Gratora view template not found: {$template}"));
        }

        if (! empty($args)) {
            extract($args, EXTR_SKIP);
        }

        include $template;
    }

    /** @since 1.1.0 */
    private static function relative(string $baseDir, string $path): string
    {
        return rtrim($baseDir, '/\\') . '/' . str_replace('.', '/', $path) . '.php';
    }

    /** @since 1.0.0 */
    private static function resolve(string $path): string
    {
        if (str_contains($path, '.')) {
            [$domain, $rest] = explode('.', $path, 2);
            $rest = str_replace('.', '/', $rest);
            return GRATORA_DIR . "src/{$domain}/resources/views/{$rest}.php";
        }

        return GRATORA_DIR . "src/resources/views/{$path}.php";
    }
}
