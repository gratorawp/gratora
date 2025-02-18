<?php

declare(strict_types=1);

namespace Dono\Receipts;

use Dono\Vendor\Mpdf\Mpdf;
use Dono\Vendor\Mpdf\Output\Destination;

/**
 * mPDF wrapper: turns an HTML string into PDF bytes.
 *
 * Temp dir is forced to wp-content/uploads/dono/tmp because /tmp is
 * ephemeral on many deployment environments.
 *
 * @version 1.0.0
 */
final class PdfBuilder
{
    public function fromHtml(string $html, array $options = []): string
    {
        $tmpDir = $this->ensureTmpDir();

        $mpdf = new Mpdf([
            'mode'         => 'utf-8',
            'format'       => $options['format'] ?? 'A4',
            'tempDir'      => $tmpDir,
            'margin_left'  => $options['margin_left']  ?? 20,
            'margin_right' => $options['margin_right'] ?? 20,
            'margin_top'   => $options['margin_top']   ?? 22,
            'margin_bottom'=> $options['margin_bottom']?? 22,
            'default_font' => 'dejavusans',  // bundled, full Latin/Cyrillic/Greek
        ]);

        if (! empty($options['title']))    $mpdf->SetTitle($options['title']);
        if (! empty($options['author']))   $mpdf->SetAuthor($options['author']);
        if (! empty($options['subject']))  $mpdf->SetSubject($options['subject']);
        $mpdf->SetCreator('Dono');

        $mpdf->WriteHTML($html);

        return (string) $mpdf->Output('', Destination::STRING_RETURN);
    }

    private function ensureTmpDir(): string
    {
        $uploads = wp_upload_dir();
        $dir = trailingslashit($uploads['basedir']) . 'dono/tmp';

        if (! is_dir($dir)) {
            wp_mkdir_p($dir);
        }

        // Block direct HTTP access to mPDF temp files (cached fonts, etc.).
        $htaccess = $dir . '/.htaccess';
        if (! file_exists($htaccess)) {
            @file_put_contents($htaccess, "Deny from all\n");
        }

        return $dir;
    }
}
