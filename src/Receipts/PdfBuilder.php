<?php

declare(strict_types=1);

namespace Dono\Receipts;

use Dono\Vendor\Dompdf\Dompdf;
use Dono\Vendor\Dompdf\Options;

/**
 * Turns an HTML string into PDF bytes.
 *
 * Temp dir is forced to wp-content/uploads/dono/tmp because /tmp is
 * ephemeral on many deployment environments.
 *
 * @since 1.0.0
 */
final class PdfBuilder
{
    /** @since 1.0.0 */
    public function fromHtml(string $html, array $options = []): string
    {
        $opts = new Options();
        $opts->setTempDir($this->ensureTmpDir());
        $opts->setFontDir($this->ensureTmpDir() . '/fonts');
        $opts->setFontCache($this->ensureTmpDir() . '/fonts');
        $opts->setDefaultFont('DejaVu Sans');
        $opts->setIsHtml5ParserEnabled(true);

        // Rendering a receipt must not make network calls: a locked-down host
        // would hang or fail on one, and it would turn anything that reaches a
        // template into a request this server makes. Images are resolved to
        // files on disk before we get here instead.
        $opts->setIsRemoteEnabled(false);

        $dompdf = new Dompdf($opts);
        $dompdf->setPaper($options['format'] ?? 'A4');

        foreach ([
            'Title'   => $options['title']   ?? '',
            'Author'  => $options['author']  ?? '',
            'Subject' => $options['subject'] ?? '',
            'Creator' => 'Dono',
        ] as $key => $value) {
            if ($value !== '') {
                $dompdf->add_info($key, (string) $value);
            }
        }

        $dompdf->loadHtml($this->prepare($html, $options));
        $dompdf->render();

        return (string) $dompdf->output();
    }

    /**
     * Page margins are a stylesheet rule here rather than a constructor
     * argument, so they are prepended as one. Values stay in millimetres, which
     * is what the callers pass.
     */
    private function prepare(string $html, array $options): string
    {
        $page = sprintf(
            '<style>@page { margin: %dmm %dmm %dmm %dmm; }</style>',
            (int) ($options['margin_top']    ?? 22),
            (int) ($options['margin_right']  ?? 20),
            (int) ($options['margin_bottom'] ?? 22),
            (int) ($options['margin_left']   ?? 20)
        );

        return $page . $this->localizeImages($html);
    }

    /**
     * Rewrite this site's own image URLs to the files behind them.
     *
     * An org logo arrives as an attachment URL, which is a network fetch to the
     * renderer. Anything that is not this site's uploads is left alone and
     * simply will not render, which is the right answer for a document that has
     * to be reproducible years later.
     */
    private function localizeImages(string $html): string
    {
        $uploads = wp_upload_dir();
        $baseUrl = (string) ($uploads['baseurl'] ?? '');
        $baseDir = (string) ($uploads['basedir'] ?? '');

        if ($baseUrl === '' || $baseDir === '') {
            return $html;
        }

        return (string) preg_replace_callback(
            '#(<img[^>]+src=["\'])([^"\']+)(["\'])#i',
            static function (array $m) use ($baseUrl, $baseDir): string {
                $url = $m[2];

                // Protocol-relative and http/https forms of the same file.
                $normalized = preg_replace('#^https?:#', '', $url);
                $base       = preg_replace('#^https?:#', '', $baseUrl);

                if ($normalized === null || $base === null || strpos($normalized, $base) !== 0) {
                    return $m[0];
                }

                $path = $baseDir . substr($normalized, strlen($base));
                $real = realpath($path);

                // realpath before the prefix test, so ../ in a stored URL cannot
                // walk out of uploads and read something else off the disk.
                if ($real === false || strpos($real, (string) realpath($baseDir)) !== 0 || ! is_file($real)) {
                    return $m[0];
                }

                return $m[1] . $real . $m[3];
            },
            $html
        );
    }

    /** @since 1.0.0 */
    private function ensureTmpDir(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'dono/tmp';

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Block direct HTTP access to temp files (cached fonts, etc.).
        $htaccess = $dir . '/.htaccess';
        if (! file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        return $dir;
    }
}
