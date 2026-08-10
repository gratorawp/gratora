<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Acceptance is recorded in dono_consents under the `terms` purpose along with
 * the text revision, so what a donor agreed to survives later edits to the
 * wording. The block ships no default wording: terms are the org's to write.
 *
 * @since 1.0.0
 */
final class TermsBlock implements Block
{
    public const PURPOSE = 'terms';

    /** @since 1.0.0 */
    public function name(): string
    {
        return 'dono/terms';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'    => ['type' => 'string',  'default' => ''],
            'terms'    => ['type' => 'string',  'default' => ''],
            'linkUrl'  => ['type' => 'string',  'default' => ''],
            'linkText' => ['type' => 'string',  'default' => ''],
        ];
    }

    /**
     * Derived from the text rather than kept by hand, because a version nobody
     * remembers to bump records the wrong answer. Whitespace-normalised, so
     * reflowing a paragraph is not a new policy. crc32 is a revision id, not a
     * security claim.
     *
     * @since 1.0.0
     */
    public static function revisionOf(string $terms, string $linkUrl = ''): int
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($terms)));

        return crc32($normalized . '|' . trim($linkUrl));
    }

    /** @since 1.0.0 */
    public static function isConfigured(array $attrs): bool
    {
        return trim((string) ($attrs['terms'] ?? '')) !== ''
            || trim((string) ($attrs['linkUrl'] ?? '')) !== '';
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/terms', [
            'label'    => (string) ($attrs['label']    ?? ''),
            'terms'    => (string) ($attrs['terms']    ?? ''),
            'linkUrl'  => (string) ($attrs['linkUrl']  ?? ''),
            'linkText' => (string) ($attrs['linkText'] ?? ''),
            'purpose'  => self::PURPOSE,
        ]);
    }
}
