<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Blocks\Block;
use Dono\Forms\Blocks\BlockRegistry;
use Dono\Foundation\Plugin;

/**
 * Add-on modules boot after core, so a broadcast fired inside core's own boot
 * is already over by the time an add-on could listen for it. The block
 * broadcast has to run late enough that an add-on's boot-time handler is
 * honored, or its field block registers in the editor and 404s on the server.
 */
final class BlockRegisterHookTest extends IntegrationTestCase
{
    public function test_a_block_added_after_core_boot_still_registers_with_wordpress(): void
    {
        $block = new class () implements Block {
            public function name(): string
            {
                return 'acme/keepsake';
            }

            public function attributes(): array
            {
                return ['label' => ['type' => 'string', 'default' => '']];
            }

            public function render(array $attrs, string $content): string
            {
                return '<p>keepsake</p>';
            }
        };

        add_action('dono.blocks.register_server', static function (BlockRegistry $blocks) use ($block): void {
            $blocks->add($block);
        });

        // The broadcast rides `init`, the same hook that hands the registry to
        // WordPress; re-firing it is what a late-booting module effectively
        // sees. Everything core registered on the first `init` is re-offered
        // and rejected by name, which is only noise here.
        $this->expected_doing_it_wrong = [
            'WP_Block_Type_Registry::register',
            'WP_Block_Bindings_Registry::register',
            'WP_Block_Templates_Registry::register',
        ];
        do_action('init');

        $this->assertTrue(Plugin::instance()->container->get(BlockRegistry::class)->has('acme/keepsake'));
        $this->assertTrue(\WP_Block_Type_Registry::get_instance()->is_registered('acme/keepsake'));

        unregister_block_type('acme/keepsake');
    }
}
