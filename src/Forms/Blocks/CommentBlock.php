<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Free-text message field block.
 *
 * @version 1.0.0
 */
final class CommentBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/comment';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'       => ['type' => 'string',  'default' => ''],
            'placeholder' => ['type' => 'string',  'default' => ''],
            'required'    => ['type' => 'boolean', 'default' => false],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        return View::loadRelative(__DIR__, 'views/comment', [
            'label'       => (string) ($attrs['label']       ?? '') ?: __('Add a message', 'dono'),
            'placeholder' => (string) ($attrs['placeholder'] ?? '') ?: __('Anything you want to share?', 'dono'),
            'required'    => (bool)   ($attrs['required']    ?? false),
        ]);
    }
}
