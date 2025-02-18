<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Forms\Blocks\ConsentBlock;
use Dono\Forms\Blocks\DateBlock;
use Dono\Forms\Blocks\DropdownBlock;
use Dono\Forms\Blocks\RecurringToggleBlock;
use WP_Error;

/**
 * Server-side submit-time validation of a public donation payload against the
 * form's authored blocks. The REST schema can't express "required iff the author
 * toggled it", so per-field rules are enforced here after the form resolves.
 * The Preact client validates the same rules; this closes the crafted-POST hole.
 */
final class FormSubmissionValidator
{
    public function validate(Form $form, array $body): ?WP_Error
    {
        $blocks = parse_blocks((string) ($form->blocks ?? ''));

        $offered = ['one_time'];
        $err = $this->walk($blocks, $body, $offered);
        if ($err !== null) {
            return $err;
        }

        $freq = (string) ($body['frequency'] ?? 'one_time');
        if ($freq === '') $freq = 'one_time';
        if (! in_array($freq, $offered, true)) {
            return $this->reject(__('That donation frequency is not available for this form.', 'dono'));
        }

        return null;
    }

    /** Consent purpose ids the form's own consent block defines. */
    public static function consentPurposeIds(string $blocks): array
    {
        $ids = [];
        self::collectConsentIds(parse_blocks((string) $blocks), $ids);
        return $ids;
    }

    /**
     * @param array<int,array<string,mixed>> $blocks
     * @param array<string,bool>             $offered passed by ref; accumulates offered frequencies
     */
    private function walk(array $blocks, array $body, array &$offered): ?WP_Error
    {
        foreach ($blocks as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            $err = $this->validateBlock($name, $attrs, $body, $offered);
            if ($err !== null) {
                return $err;
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $err = $this->walk($block['innerBlocks'], $body, $offered);
                if ($err !== null) {
                    return $err;
                }
            }
        }
        return null;
    }

    private function validateBlock(string $name, array $attrs, array $body, array &$offered): ?WP_Error
    {
        $profile = is_array($body['profile'] ?? null) ? $body['profile'] : [];
        $custom  = is_array($body['custom'] ?? null) ? $body['custom'] : [];

        switch ($name) {
            case 'dono/name':
                if (! empty($attrs['requireFirst']) && ! $this->filled($profile['first_name'] ?? null)) {
                    return $this->requiredError(__('First name', 'dono'));
                }
                if (! empty($attrs['requireLast']) && ! $this->filled($profile['last_name'] ?? null)) {
                    return $this->requiredError(__('Last name', 'dono'));
                }
                break;

            case 'dono/phone':
                if (! empty($attrs['required']) && ! $this->filled($profile['phone'] ?? null)) {
                    return $this->requiredError($this->label($attrs, __('Phone', 'dono')));
                }
                break;

            case 'dono/country':
                if (! empty($attrs['required']) && ! $this->filled($profile['country'] ?? null)) {
                    return $this->requiredError($this->label($attrs, __('Country', 'dono')));
                }
                break;

            case 'dono/comment':
                if (! empty($attrs['required']) && ! $this->filled($body['note_to_org'] ?? null)) {
                    return $this->requiredError($this->label($attrs, __('Comment', 'dono')));
                }
                break;

            case 'dono/address':
                $addr = is_array($profile['address'] ?? null) ? $profile['address'] : [];
                $sub  = [
                    'line1'   => ['showLine1',   'requireLine1',   true,  __('Address', 'dono')],
                    'city'    => ['showCity',    'requireCity',    true,  __('City', 'dono')],
                    'region'  => ['showRegion',  'requireRegion',  false, __('Region', 'dono')],
                    'postal'  => ['showPostal',  'requirePostal',  true,  __('Postal code', 'dono')],
                    'country' => ['showCountry', 'requireCountry', true,  __('Country', 'dono')],
                ];
                foreach ($sub as $key => [$showAttr, $reqAttr, $reqDefault, $sLabel]) {
                    $shown    = (bool) ($attrs[$showAttr] ?? true);
                    $required = (bool) ($attrs[$reqAttr] ?? $reqDefault);
                    if ($shown && $required && ! $this->filled($addr[$key] ?? null)) {
                        return $this->requiredError($sLabel);
                    }
                }
                break;

            case 'dono/text-input':
                $key = $this->customKey($attrs);
                $val = $custom[$key] ?? null;
                if (! empty($attrs['required']) && ! $this->filled($val)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                if ($this->filled($val)) {
                    $max = (int) ($attrs['maxLength'] ?? 0);
                    if ($max > 0 && mb_strlen((string) $val) > $max) {
                        return $this->reject(sprintf(__('%s is too long.', 'dono'), $this->label($attrs, $key)));
                    }
                    $pattern = (string) ($attrs['pattern'] ?? '');
                    if ($pattern !== '' && ! $this->matchesPattern($pattern, (string) $val)) {
                        return $this->reject(sprintf(__('%s is not in the expected format.', 'dono'), $this->label($attrs, $key)));
                    }
                }
                break;

            case 'dono/number-input':
                $key = $this->customKey($attrs);
                $val = $custom[$key] ?? null;
                if (! empty($attrs['required']) && ! $this->filled($val)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                if ($this->filled($val)) {
                    if (! is_numeric($val)) {
                        return $this->reject(sprintf(__('%s must be a number.', 'dono'), $this->label($attrs, $key)));
                    }
                    $n = (float) $val;
                    if (isset($attrs['min']) && is_numeric($attrs['min']) && $n < (float) $attrs['min']) {
                        return $this->reject(sprintf(__('%s is below the minimum.', 'dono'), $this->label($attrs, $key)));
                    }
                    if (isset($attrs['max']) && is_numeric($attrs['max']) && $n > (float) $attrs['max']) {
                        return $this->reject(sprintf(__('%s is above the maximum.', 'dono'), $this->label($attrs, $key)));
                    }
                }
                break;

            case 'dono/date':
                $key = $this->customKey($attrs);
                $val = $custom[$key] ?? null;
                if (! empty($attrs['required']) && ! $this->filled($val)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                if ($this->filled($val)) {
                    $d   = (string) $val;
                    $min = DateBlock::normalizeDate((string) ($attrs['minDate'] ?? ''));
                    $max = DateBlock::normalizeDate((string) ($attrs['maxDate'] ?? ''));
                    if (($min !== '' && $d < $min) || ($max !== '' && $d > $max)) {
                        return $this->reject(sprintf(__('%s is outside the allowed range.', 'dono'), $this->label($attrs, $key)));
                    }
                }
                break;

            case 'dono/dropdown':
            case 'dono/radio':
                $key = $this->customKey($attrs);
                if (! empty($attrs['required']) && ! $this->filled($custom[$key] ?? null)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                break;

            case 'dono/checkbox':
                $key = $this->customKey($attrs);
                if (! empty($attrs['required']) && empty($custom[$key])) {
                    return $this->reject(sprintf(__('Please check %s.', 'dono'), $this->label($attrs, $key)));
                }
                break;

            case 'dono/multi-select':
                $key   = $this->customKey($attrs);
                $sel   = is_array($custom[$key] ?? null) ? $custom[$key] : [];
                $count = count($sel);
                if (! empty($attrs['required']) && $count === 0) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                $min = max(0, (int) ($attrs['minSelections'] ?? 0));
                $max = max(0, (int) ($attrs['maxSelections'] ?? 0));
                if ($count > 0 && $min > 0 && $count < $min) {
                    return $this->reject(sprintf(__('Select at least %d for %s.', 'dono'), $min, $this->label($attrs, $key)));
                }
                if ($max > 0 && $count > $max) {
                    return $this->reject(sprintf(__('Select at most %d for %s.', 'dono'), $max, $this->label($attrs, $key)));
                }
                break;

            case 'dono/consent':
                $consents = is_array($body['consents'] ?? null) ? $body['consents'] : [];
                foreach (ConsentBlock::normalizePurposes($attrs['purposes'] ?? null) as $p) {
                    if (! empty($p['requiredByLaw']) && empty($consents[$p['id']])) {
                        return $this->reject(sprintf(
                            /* translators: %s: consent purpose label */
                            __('Please agree to: %s', 'dono'),
                            (string) ($p['label'] ?? '')
                        ));
                    }
                }
                break;

            case 'dono/recurring-toggle':
                $freqs = RecurringToggleBlock::normalizeFrequencies($attrs['frequencies'] ?? []);
                if (! in_array('one-time', $freqs, true) && ! empty($freqs)) {
                    array_unshift($freqs, 'one-time');
                }
                if (count($freqs) >= 2) {
                    foreach ($freqs as $f) {
                        $offered[] = $f === 'one-time' ? 'one_time' : $f;
                    }
                }
                break;
        }

        return null;
    }

    /** @param array<string,bool> $ids */
    private static function collectConsentIds(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'dono/consent') {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                foreach (ConsentBlock::normalizePurposes($attrs['purposes'] ?? null) as $p) {
                    $id = (string) ($p['id'] ?? '');
                    if ($id !== '') $ids[$id] = true;
                }
            }
            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                self::collectConsentIds($block['innerBlocks'], $ids);
            }
        }
    }

    private function customKey(array $attrs): string
    {
        return DropdownBlock::deriveField(
            (string) ($attrs['field'] ?? ''),
            (string) ($attrs['label'] ?? '')
        );
    }

    private function label(array $attrs, string $fallback): string
    {
        $label = trim((string) ($attrs['label'] ?? ''));
        return $label !== '' ? $label : $fallback;
    }

    private function filled(mixed $v): bool
    {
        if ($v === null) return false;
        if (is_string($v)) return trim($v) !== '';
        if (is_array($v)) return $v !== [];
        return $v !== '' && $v !== false;
    }

    private function matchesPattern(string $pattern, string $value): bool
    {
        $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~';
        $r = @preg_match($regex, $value);
        return $r === false ? true : $r === 1;
    }

    private function requiredError(string $label): WP_Error
    {
        return $this->reject(sprintf(
            /* translators: %s: form field label */
            __('Please complete the %s field.', 'dono'),
            $label
        ));
    }

    private function reject(string $message): WP_Error
    {
        return new WP_Error('dono_form_validation', $message, ['status' => 400]);
    }
}
