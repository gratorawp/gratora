<?php

declare(strict_types=1);

namespace GiveFlow\Tests\Integration;

use GiveFlow\Receipts\PdfBuilder;

/**
 * Every PDF the plugin produces goes through PdfBuilder, which rewrites this
 * site's own image URLs to the files behind them so rendering makes no network
 * call. Dompdf then refuses to read those files unless the directory holding
 * them is in its chroot, which defaults to Dompdf's own library directory. An
 * org that set a receipt logo got a document with the alt text where the logo
 * should be, and nothing anywhere said why.
 */
final class PdfImageChrootTest extends IntegrationTestCase
{
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    /** @var list<string> */
    private array $written = [];

    protected function tearDown(): void
    {
        foreach ($this->written as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        $this->written = [];

        parent::tearDown();
    }

    private function writePng(string $dir, string $name): string
    {
        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        $file = trailingslashit($dir) . $name;
        file_put_contents($file, base64_decode(self::PNG));
        $this->written[] = $file;

        return $file;
    }

    private function embeddedImages(string $pdf): int
    {
        return substr_count($pdf, '/Subtype /Image');
    }

    public function test_an_image_in_uploads_reaches_the_pdf(): void
    {
        $uploads = wp_upload_dir();
        $this->writePng($uploads['basedir'], 'giveflow-logo-probe.png');

        $pdf = (new PdfBuilder())->fromHtml(
            '<html><body><img src="' . $uploads['baseurl'] . '/giveflow-logo-probe.png"></body></html>'
        );

        // A PNG with an alpha channel emits its soft mask as a second image
        // object, so this counts "drawn at all", not how many.
        $this->assertGreaterThan(
            0,
            $this->embeddedImages($pdf),
            'a logo stored in uploads is drawn into the document, not dropped for its alt text'
        );
    }

    public function test_a_file_outside_uploads_is_still_refused(): void
    {
        // Reachable as a path but not through this site's uploads URL, so
        // localizeImages leaves it alone and the chroot has to hold the line.
        $outside = $this->writePng(sys_get_temp_dir() . '/giveflow-chroot-probe', 'elsewhere.png');
        $this->assertFileExists($outside, 'the refusal below is the chroot, not a missing file');

        $pdf = (new PdfBuilder())->fromHtml(
            '<html><body><img src="' . $outside . '"></body></html>'
        );

        $this->assertSame(
            0,
            $this->embeddedImages($pdf),
            'widening the chroot to fix logos must not turn the renderer into a file reader'
        );
    }
}
