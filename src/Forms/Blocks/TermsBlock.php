<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Agreement to the organisation's own terms, recorded as evidence.
 *
 * The checkbox is the cheap half. What has value later is the record: which
 * revision of which text somebody accepted, on which form, with which donation,
 * and when. That goes to dono_consents under the `terms` purpose, so the answer
 * to "what did this donor agree to" survives the text being edited afterwards.
 *
 * The block ships no wording. Terms are the organisation's to write and differ
 * by country, cause and legal form; sample text sits unread until it is live on
 * the page where money changes hands, and then it is the org publishing
 * somebody else's boilerplate about their own gifts.
 *
 * @version 1.0.0
 */
final class TermsBlock implements Block
{
    /** The consent purpose an acceptance is recorded under. */
    public const PURPOSE = 'terms';

    public function name(): string
    {
        return 'dono/terms';
    }

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
     * Which revision of the terms this is, derived from the text rather than
     * kept by hand, because a version nobody remembers to bump records the
     * wrong answer. Consent rows carry it, so a changed policy is visible as a
     * changed number and every acceptance stays attributable to what it
     * accepted.
     *
     * Whitespace-normalised, so reflowing a paragraph in the editor is not a
     * new policy. crc32 is a revision id, not a security claim.
     */
    public static function revisionOf(string $terms, string $linkUrl = ''): int
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', wp_strip_all_tags($terms)));

        return crc32($normalized . '|' . trim($linkUrl));
    }

    /** Nothing to agree to means nothing to enforce. */
    public static function isConfigured(array $attrs): bool
    {
        return trim((string) ($attrs['terms'] ?? '')) !== ''
            || trim((string) ($attrs['linkUrl'] ?? '')) !== '';
    }

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
