<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Foundation\Helpers\View;

/**
 * Consent / opt-in purposes block.
 *
 * @version 1.0.0
 */
final class ConsentBlock implements Block
{
    /** Block name. */
    public function name(): string
    {
        return 'dono/consent';
    }

    /** Editor attribute schema. */
    public function attributes(): array
    {
        return [
            'label'        => ['type' => 'string', 'default' => ''],
            'helpText'     => ['type' => 'string', 'default' => ''],
            'purposes'     => ['type' => 'array',  'default' => [
                ['id' => 'email_updates', 'label' => 'Email me about future campaigns', 'description' => '', 'requiredByLaw' => false],
            ]],
            'defaultState' => ['type' => 'string', 'default' => 'opt-in'],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $purposes     = self::normalizePurposes($attrs['purposes'] ?? null);
        $defaultState = (string) ($attrs['defaultState'] ?? 'opt-in');
        if (! in_array($defaultState, ['opt-in', 'opt-out'], true)) {
            $defaultState = 'opt-in';
        }

        return View::loadRelative(__DIR__, 'views/consent', [
            'label'        => (string) ($attrs['label']    ?? ''),
            'helpText'     => (string) ($attrs['helpText'] ?? ''),
            'purposes'     => $purposes,
            'defaultState' => $defaultState,
        ]);
    }

    /** @return list<array{id:string,label:string,description:string,requiredByLaw:bool}> */
    public static function normalizePurposes(mixed $raw): array
    {
        $items = is_array($raw) ? $raw : [];
        $out   = [];
        $seen  = [];
        foreach ($items as $i => $item) {
            if (! is_array($item)) continue;
            $id = (string) ($item['id'] ?? '');
            $id = strtolower(preg_replace('/[^a-z0-9]+/', '_', strtolower($id)) ?? '');
            $id = trim($id, '_');
            if ($id === '') $id = 'purpose_' . ($i + 1);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = [
                'id'            => $id,
                'label'         => (string) ($item['label'] ?? ''),
                'description'   => (string) ($item['description'] ?? ''),
                'requiredByLaw' => (bool)   ($item['requiredByLaw'] ?? false),
            ];
        }
        if (empty($out)) {
            $out = [[
                'id'            => 'email_updates',
                'label'         => 'Email me about future campaigns',
                'description'   => '',
                'requiredByLaw' => false,
            ]];
        }
        return $out;
    }
}
