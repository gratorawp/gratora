<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Donors\Donor;
use Dono\Donors\DonorAvatarUploader;

/**
 * An upload that fails has to say why. PHP rejects an oversized file before any
 * of our code runs, so the size cases arrive as an error code with no size
 * attached, and a generic "that did not upload" leaves the donor retrying the
 * same file forever.
 */
final class DonorAvatarUploadErrorsTest extends IntegrationTestCase
{
    private function upload(array $file): \WP_Error
    {
        $donor = Donor::make();
        $donor->id = 1;

        $result = (new DonorAvatarUploader())->store($donor, $file);

        $this->assertInstanceOf(\WP_Error::class, $result);

        return $result;
    }

    /**
     * The limit the donor is told is the one the server will actually honour,
     * not the one this class would prefer: PHP's upload_max_filesize is
     * routinely lower than our own cap, and it wins.
     */
    public function test_the_published_limit_never_exceeds_what_php_accepts(): void
    {
        $max = DonorAvatarUploader::maxBytes();

        $this->assertGreaterThan(0, $max);
        foreach (['upload_max_filesize', 'post_max_size'] as $key) {
            $php = wp_convert_hr_to_bytes((string) ini_get($key));
            if ($php > 0) {
                $this->assertLessThanOrEqual($php, $max, "the limit must not exceed {$key}");
            }
        }
    }

    public function test_php_refusing_an_oversized_file_reads_as_too_large(): void
    {
        $err = $this->upload(['error' => UPLOAD_ERR_INI_SIZE, 'size' => 9999999, 'tmp_name' => '', 'name' => 'big.jpg']);

        $this->assertSame('dono_upload_too_large', $err->get_error_code());
        $this->assertSame(413, $err->get_error_data()['status']);
        $this->assertStringContainsString(size_format(DonorAvatarUploader::maxBytes()), $err->get_error_message());
    }

    public function test_our_own_cap_reads_the_same_way(): void
    {
        $err = $this->upload(['error' => UPLOAD_ERR_OK, 'size' => PHP_INT_MAX, 'tmp_name' => '', 'name' => 'big.jpg']);

        $this->assertSame('dono_upload_too_large', $err->get_error_code());
        $this->assertStringContainsString(size_format(DonorAvatarUploader::maxBytes()), $err->get_error_message());
    }

    public function test_a_dropped_connection_is_not_reported_as_a_bad_file(): void
    {
        $err = $this->upload(['error' => UPLOAD_ERR_PARTIAL, 'size' => 100, 'tmp_name' => '', 'name' => 'x.jpg']);

        $this->assertSame('dono_upload_failed', $err->get_error_code());
        $this->assertStringContainsString('cut short', $err->get_error_message());
    }

    public function test_no_file_says_so(): void
    {
        $err = $this->upload(['error' => UPLOAD_ERR_NO_FILE, 'size' => 0, 'tmp_name' => '', 'name' => '']);

        $this->assertSame('dono_upload_missing', $err->get_error_code());
    }

    /** A file claiming to be an image but is not never reaches the library. */
    public function test_a_non_image_is_refused(): void
    {
        $path = wp_upload_dir()['path'] . '/not-an-image.png';
        file_put_contents($path, 'this is plain text wearing a png extension');

        $err = $this->upload(['error' => UPLOAD_ERR_OK, 'size' => 41, 'tmp_name' => $path, 'name' => 'not-an-image.png']);

        $this->assertSame('dono_upload_not_image', $err->get_error_code());
        $this->assertSame(415, $err->get_error_data()['status']);

        @unlink($path);
    }
}
