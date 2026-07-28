<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * The UK Gift Aid declaration.
 *
 * Renders nothing at all unless Gift Aid is switched on in settings: a
 * declaration on a form that cannot claim is a promise the charity will not
 * keep, and the donor has no way to tell.
 *
 * The statutory wording is the default rather than a placeholder. HMRC expects
 * a declaration to say the donor is a UK taxpayer and that they understand they
 * must pay enough tax to cover the reclaim. An org may add to that; the block
 * does not invite them to replace it.
 *
 * @version 1.0.0
 */
final class GiftAidBlock implements Block
{
    public function name(): string
    {
        return 'dono/gift-aid';
    }

    public function attributes(): array
    {
        return [
            'label'    => ['type' => 'string', 'default' => ''],
            'helpText' => ['type' => 'string', 'default' => ''],
        ];
    }

    public function render(array $attrs, string $content): string
    {
        $settings = get_option('dono_gift_aid', []);
        $settings = is_array($settings) ? $settings : [];

        if (empty($settings['enabled'])) {
            return '';
        }

        return View::loadRelative(__DIR__, 'views/gift-aid', [
            'label'     => (string) ($attrs['label']    ?? ''),
            'helpText'  => (string) ($attrs['helpText'] ?? ''),
            'statement' => self::statement(),
        ]);
    }

    /**
     * The wording the donor agrees to. Stored verbatim on the declaration, so
     * changing this setting later cannot rewrite what past donors were shown.
     */
    public static function statement(): string
    {
        $settings = get_option('dono_gift_aid', []);
        $custom   = is_array($settings) ? trim((string) ($settings['statement'] ?? '')) : '';

        return $custom !== '' ? $custom : self::defaultStatement();
    }

    public static function defaultStatement(): string
    {
        return __(
            'I want to Gift Aid my donation and any donations I make in the future or have made in the past 4 years to this charity. I am a UK taxpayer and understand that if I pay less Income Tax and/or Capital Gains Tax than the amount of Gift Aid claimed on all my donations in that tax year it is my responsibility to pay any difference.',
            'dono'
        );
    }
}
