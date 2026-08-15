<?php

declare(strict_types=1);

namespace Dono\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * This product calls the money a donor gives a donation, everywhere, in copy a
 * donor reads and in the code behind it. The other word is a sector habit, and
 * it drifts back in one comment at a time until it reaches a form label and a
 * donor is shown a word the rest of the screen does not use.
 *
 * Three sweeps by hand each missed survivors, including four strings on the
 * default donation form. A sweep that is not enforced is a sweep that has to be
 * done again, so this is the enforcement.
 */
final class DonationNotGiftTest extends TestCase
{
    private const WORD = '/\bgift(s|ed|ing)?\b/i';

    /**
     * Every spelling the word is still allowed in, with the reason. A line
     * matching any of these is skipped whole.
     */
    private const ALLOWED = [
        // Gift Aid is the name of a UK tax scheme and of the add-on that
        // claims it, so it survives translation and cannot be reworded.
        '/gift[ -]aid/i',
        // A column header other fundraising platforms export. It is their
        // spelling, matched on import, not ours.
        '/\'gift amount\'/',
    ];

    /** @var list<string> */
    private const ROOT_FILES = ['readme.txt', 'dono.php', 'uninstall.php'];

    private function root(): string
    {
        return dirname(__DIR__, 2);
    }

    /**
     * The pattern has to catch the plural and the participle: "between gifts"
     * and "a gifted amount" are the same habit, and a word-for-word check for
     * "gift" reads both as clean.
     */
    public function test_the_pattern_catches_the_forms_the_word_arrives_in(): void
    {
        $caught = ['a gift', 'between gifts', 'gifted last year', 'gifting', 'GIFT-2026-1', 'Gifts'];
        foreach ($caught as $sample) {
            $this->assertSame(1, preg_match(self::WORD, $sample), "the scan would not catch: {$sample}");
        }

        $this->assertSame(0, preg_match(self::WORD, 'a donation the donor made'));
    }

    /** Gift Aid keeps its name; nothing else does. */
    public function test_the_allowances_cover_what_they_claim_to(): void
    {
        foreach (['Gift Aid claims', '?tab=gift-aid', "'gift amount'"] as $allowed) {
            $this->assertTrue($this->isAllowed($allowed), "no allowance covers: {$allowed}");
        }

        $this->assertFalse($this->isAllowed('Recurring gift paused'));
    }

    public function test_no_source_file_calls_a_donation_a_gift(): void
    {
        $offenders = [];
        $scanned   = 0;

        foreach ($this->sources() as $path) {
            $scanned++;
            foreach (preg_split('/\R/', (string) file_get_contents($path)) ?: [] as $i => $line) {
                if (preg_match(self::WORD, $line) !== 1 || $this->isAllowed($line)) {
                    continue;
                }
                $offenders[] = substr($path, strlen($this->root()) + 1) . ':' . ($i + 1) . '  ' . trim($line);
            }
        }

        // Without this the whole check passes by reading nothing, which is how
        // a scan scoped to a directory that moved would report the tree clean.
        $this->assertGreaterThan(500, $scanned, 'the scan read almost nothing, so it proves nothing.');

        sort($offenders);

        $this->assertSame(
            [],
            $offenders,
            "This product says donation, never gift (Gift Aid is the scheme's own name):\n"
                . implode("\n", $offenders)
        );
    }

    private function isAllowed(string $line): bool
    {
        foreach (self::ALLOWED as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * Shipped code, the tests that describe it, and the two files wp.org
     * renders. Generated artefacts are left out: languages/*.pot is rebuilt
     * from these sources at release, so it cannot hold a word they do not.
     *
     * @return list<string>
     */
    private function sources(): array
    {
        $out = [];

        foreach (['src', 'assets', 'tests'] as $dir) {
            $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->root() . '/' . $dir));
            foreach ($files as $file) {
                if (! $file->isFile()) {
                    continue;
                }
                if (! in_array($file->getExtension(), ['php', 'js', 'jsx', 'scss', 'css'], true)) {
                    continue;
                }
                // This file names the word to forbid it.
                if ($file->getFilename() === basename(__FILE__)) {
                    continue;
                }
                $out[] = $file->getPathname();
            }
        }

        foreach (self::ROOT_FILES as $name) {
            $path = $this->root() . '/' . $name;
            $this->assertFileExists($path);
            $out[] = $path;
        }

        return $out;
    }
}
