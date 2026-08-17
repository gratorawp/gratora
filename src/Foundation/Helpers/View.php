<?php

declare(strict_types=1);

namespace Dono\Foundation\Helpers;

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

    /** @since 1.0.0 */
    public static function render(string $path, array $args = []): void
    {
        // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- self::load returns a whole rendered template, which escaping would mangle; each template escapes its own values with esc_html()/esc_attr() as it prints them.
        echo self::load($path, $args);
    }

    /**
     * Load a view relative to a caller-supplied base directory.
     * Use when the module layout does not match src/{Module}/resources/views/.
     *
     * @since 1.0.0
     */
    public static function loadRelative(string $baseDir, string $path, array $args = []): string
    {
        $rel = str_replace('.', '/', $path);
        $template = rtrim($baseDir, '/\\') . '/' . $rel . '.php';
        return self::renderFile($template, $args);
    }

    /** @since 1.0.0 */
    private static function renderFile(string $template, array $args): string
    {
        if (! file_exists($template)) {
            throw new InvalidArgumentException(esc_html("Dono view template not found: {$template}"));
        }

        ob_start();

        if (! empty($args)) {
            extract($args, EXTR_SKIP);
        }

        include $template;

        return (string) ob_get_clean();
    }

    /** @since 1.0.0 */
    private static function resolve(string $path): string
    {
        if (str_contains($path, '.')) {
            [$domain, $rest] = explode('.', $path, 2);
            $rest = str_replace('.', '/', $rest);
            return DONO_DIR . "src/{$domain}/resources/views/{$rest}.php";
        }

        return DONO_DIR . "src/resources/views/{$path}.php";
    }
}
