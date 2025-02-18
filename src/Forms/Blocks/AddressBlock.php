<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Address field block.
 *
 * @version 1.0.0
 */
final class AddressBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/address';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'          => ['type' => 'string',  'default' => ''],
            'showLine1'      => ['type' => 'boolean', 'default' => true],
            'showLine2'      => ['type' => 'boolean', 'default' => true],
            'showCity'       => ['type' => 'boolean', 'default' => true],
            'showRegion'     => ['type' => 'boolean', 'default' => true],
            'showPostal'     => ['type' => 'boolean', 'default' => true],
            'showCountry'    => ['type' => 'boolean', 'default' => true],
            'requireLine1'   => ['type' => 'boolean', 'default' => true],
            'requireCity'    => ['type' => 'boolean', 'default' => true],
            'requireRegion'  => ['type' => 'boolean', 'default' => false],
            'requirePostal'  => ['type' => 'boolean', 'default' => true],
            'requireCountry' => ['type' => 'boolean', 'default' => true],
            'line1Label'     => ['type' => 'string',  'default' => ''],
            'line2Label'     => ['type' => 'string',  'default' => ''],
            'cityLabel'      => ['type' => 'string',  'default' => ''],
            'regionLabel'    => ['type' => 'string',  'default' => ''],
            'postalLabel'    => ['type' => 'string',  'default' => ''],
            'countryLabel'   => ['type' => 'string',  'default' => ''],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/address', [
            'label'          => (string) ($attrs['label'] ?? ''),
            'showLine1'      => (bool) ($attrs['showLine1']      ?? true),
            'showLine2'      => (bool) ($attrs['showLine2']      ?? true),
            'showCity'       => (bool) ($attrs['showCity']       ?? true),
            'showRegion'     => (bool) ($attrs['showRegion']     ?? true),
            'showPostal'     => (bool) ($attrs['showPostal']     ?? true),
            'showCountry'    => (bool) ($attrs['showCountry']    ?? true),
            'requireLine1'   => (bool) ($attrs['requireLine1']   ?? true),
            'requireCity'    => (bool) ($attrs['requireCity']    ?? true),
            'requireRegion'  => (bool) ($attrs['requireRegion']  ?? false),
            'requirePostal'  => (bool) ($attrs['requirePostal']  ?? true),
            'requireCountry' => (bool) ($attrs['requireCountry'] ?? true),
            'line1Label'     => (string) ($attrs['line1Label']   ?? ''),
            'line2Label'     => (string) ($attrs['line2Label']   ?? ''),
            'cityLabel'      => (string) ($attrs['cityLabel']    ?? ''),
            'regionLabel'    => (string) ($attrs['regionLabel']  ?? ''),
            'postalLabel'    => (string) ($attrs['postalLabel']  ?? ''),
            'countryLabel'   => (string) ($attrs['countryLabel'] ?? ''),
        ]);
    }
}
