<?php

declare(strict_types=1);

namespace Dono\Forms\Blocks;

use Dono\Donors\ConsentService;
use Dono\Foundation\Helpers\View;

/**
 * Consent / opt-in purposes block.
 *
 * @version 1.0.0
 */
final class ConsentBlock implements Block
{
    public function __construct(private ConsentService $consents)
    {
    }

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
            // Keys into the org's consent registry, not purposes of its own.
            // A purpose invented here would exist on one form and nowhere else,
            // so the label the donor read and the wording the org later edits
            // could never agree.
            'purposeKeys'  => ['type' => 'array',  'default' => []],
        ];
    }

    /** Render server-side markup. */
    public function render(array $attrs, string $content): string
    {
        $purposes = $this->resolvePurposes(self::purposeKeys($attrs));

        // Every key was deleted from the registry, or none was picked. Donors
        // see nothing rather than an empty legend; the author gets a hint.
        if ($purposes === []) {
            if (! (is_user_logged_in() && current_user_can('edit_posts'))) {
                return '';
            }

            return '<div class="dono-block-notice">'
                . esc_html__('Pick which consent purposes this form asks for, or add one in Settings, Consents.', 'dono')
                . '</div>';
        }

        return View::loadRelative(__DIR__, 'views/consent', [
            'label'    => (string) ($attrs['label']    ?? ''),
            'helpText' => (string) ($attrs['helpText'] ?? ''),
            'purposes' => $purposes,
        ]);
    }

    /**
     * Registry purposes for the keys this form picked, in the form's order.
     * A key the org has since deleted resolves to nothing rather than to an
     * invented purpose, so a form cannot outlive what it asks about.
     *
     * @param  list<string> $keys
     * @return list<array{key:string,label:string,description:string,required:bool,default:bool}>
     */
    private function resolvePurposes(array $keys): array
    {
        $out = [];
        foreach ($keys as $key) {
            $p = $this->consents->findPurpose($key);
            if ($p === null) continue;
            $out[] = [
                'key'         => (string) $p['key'],
                'label'       => (string) $p['label'],
                'description' => (string) $p['description'],
                'required'    => (bool) $p['required'],
                'default'     => (bool) $p['default'],
            ];
        }
        return $out;
    }

    /**
     * @param  array<string,mixed> $attrs
     * @return list<string>
     */
    public static function purposeKeys(array $attrs): array
    {
        $raw  = is_array($attrs['purposeKeys'] ?? null) ? $attrs['purposeKeys'] : [];
        $out  = [];
        $seen = [];
        foreach ($raw as $key) {
            $key = trim((string) $key);
            if ($key === '' || isset($seen[$key])) continue;
            $seen[$key] = true;
            $out[] = $key;
        }
        return $out;
    }

}
