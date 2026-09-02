<?php

declare(strict_types=1);

namespace FundKit\Forms\Blocks;

use FundKit\Foundation\Helpers\View;

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
        return 'fundkit/comment';
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
            'label'       => (string) ($attrs['label']       ?? '') ?: __('Add a message', 'fundraising-toolkit'),
            'placeholder' => (string) ($attrs['placeholder'] ?? '') ?: __('Anything you want to share?', 'fundraising-toolkit'),
            'required'    => (bool)   ($attrs['required']    ?? false),
        ]);
    }
}
