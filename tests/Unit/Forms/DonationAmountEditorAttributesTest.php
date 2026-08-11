<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Forms;

use Dono\Forms\Blocks\DonationAmountBlock;
use PHPUnit\Framework\TestCase;

/**
 * The editor writes block attributes, the server reads them, and WordPress
 * serializes only the keys the editor registered. An attribute the server
 * declares and the editor does not is therefore dropped on save: the inspector
 * control moves, the admin is told the form saved, and the value is gone on
 * reload.
 *
 * @since 1.0.0
 */
final class DonationAmountEditorAttributesTest extends TestCase
{
    /** The `attributes` object the editor block passes to api.register(). */
    private function registeredAttributes(): string
    {
        $path = dirname(__DIR__, 3) . '/assets/admin/forms/blocks/donation-amount/index.js';
        $this->assertFileExists($path);

        $js    = (string) file_get_contents($path);
        $start = strpos($js, 'attributes: {');
        $end   = $start === false ? false : strpos($js, 'edit: Edit', $start);
        $this->assertIsInt($start, 'The editor block no longer registers an attributes object.');
        $this->assertIsInt($end, 'The editor block no longer registers an edit component.');

        return substr($js, $start, $end - $start);
    }

    public function test_editor_registers_every_attribute_the_server_block_declares(): void
    {
        $registered = $this->registeredAttributes();

        foreach (array_keys((new DonationAmountBlock())->attributes()) as $name) {
            $this->assertMatchesRegularExpression(
                '/\b' . preg_quote($name, '/') . '\s*:\s*\{\s*type:/',
                $registered,
                "The editor drops `{$name}` on save: it is not in the block's registered attributes."
            );
        }
    }
}
