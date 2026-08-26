<?php

declare(strict_types=1);

namespace GiveFlow\Forms\Blocks;

use GiveFlow\Foundation\Helpers\View;

/**
 * Free-text message field block.
 *
 * @since 1.0.0
 */
final class CommentBlock implements Block
{
    /** @since 1.0.0 */
    public function name(): string
    {
        return 'giveflow/comment';
    }

    /** @since 1.0.0 */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** @since 1.0.0 */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/comment', [
            'label'       => (string) ($attrs['label']       ?? '') ?: __('Add a message', 'giveflow-fundraising-campaigns'),
            'placeholder' => (string) ($attrs['placeholder'] ?? '') ?: __('Anything you want to share?', 'giveflow-fundraising-campaigns'),
            'required'    => (bool)   ($attrs['required']    ?? false),
        ]);
    }
}
