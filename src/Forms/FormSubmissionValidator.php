<?php

declare(strict_types=1);

namespace Dono\Forms;

use Dono\Campaigns\Campaign;
use Dono\Donors\ConsentService;
use Dono\Forms\Blocks\ConsentBlock;
use Dono\Forms\Blocks\TermsBlock;
use Dono\Forms\Blocks\DateBlock;
use Dono\Forms\Blocks\DonationAmountBlock;
use Dono\Forms\Blocks\DropdownBlock;
use Dono\Forms\Blocks\RecurringToggleBlock;
use Dono\Foundation\Helpers\Money;
use WP_Error;

/**
 * The REST schema cannot express "required iff the author toggled it", so
 * per-field rules are enforced here once the form resolves. The Preact client
 * validates the same rules; this side closes the crafted-POST hole.
 */
final class FormSubmissionValidator
{
    private ?Form $form = null;

    private bool $offersCurrencyChoice = false;

    public function validate(Form $form, array $body): ?WP_Error
    {
        $this->form = $form;
        $blocks = parse_blocks((string) ($form->blocks ?? ''));
        $this->offersCurrencyChoice = self::treeHasBlock($blocks, 'dono/currency-switcher');

        // The rendered amount step falls back to the campaign's presets when the
        // block omits its own (see DonationFormShortcode::buildSteps). The
        // presets-only check must use the same set, or a form on campaign presets
        // rejects every amount the donor is actually shown.
        $campaignPresets = null;
        $campaignId = isset($form->campaign_id) ? (int) $form->campaign_id : 0;
        if ($campaignId > 0) {
            $campaign = Campaign::query()->find('id', $campaignId);
            if ($campaign && is_array($campaign->default_amount_presets) && ! empty($campaign->default_amount_presets)) {
                $campaignPresets = $campaign->default_amount_presets;
            }
        }

        $offered = ['one_time'];
        $err = $this->walk($blocks, $body, $offered, $campaignPresets);
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

    public static function consentPurposeIds(string $blocks): array
    {
        $ids = [];
        self::collectConsentIds(parse_blocks((string) $blocks), $ids);
        return $ids;
    }

    /**
     * Recorded alongside the acceptance, so editing the terms later cannot
     * rewrite what somebody already agreed to. Null when there is nothing to agree to.
     */
    public static function termsRevision(string $blocks): ?int
    {
        return self::findTermsRevision(parse_blocks($blocks));
    }

    private static function findTermsRevision(array $blocks): ?int
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'dono/terms') {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                if (TermsBlock::isConfigured($attrs)) {
                    return TermsBlock::revisionOf(
                        (string) ($attrs['terms']   ?? ''),
                        (string) ($attrs['linkUrl'] ?? '')
                    );
                }
            }
            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $found = self::findTermsRevision($block['innerBlocks']);
                if ($found !== null) return $found;
            }
        }
        return null;
    }

    public static function hasBlock(string $blocks, string $blockName): bool
    {
        return self::treeHasBlock(parse_blocks($blocks), $blockName);
    }

    private static function treeHasBlock(array $blocks, string $blockName): bool
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === $blockName) {
                return true;
            }
            if (! empty($block['innerBlocks']) && self::treeHasBlock($block['innerBlocks'], $blockName)) {
                return true;
            }
        }
        return false;
    }

    private function walk(array $blocks, array $body, array &$offered, ?array $campaignPresets): ?WP_Error
    {
        foreach ($blocks as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];

            $err = $this->validateBlock($name, $attrs, $body, $offered, $campaignPresets);
            if ($err !== null) {
                return $err;
            }

            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])) {
                $err = $this->walk($block['innerBlocks'], $body, $offered, $campaignPresets);
                if ($err !== null) {
                    return $err;
                }
            }
        }
        return null;
    }

    private function validateBlock(string $name, array $attrs, array $body, array &$offered, ?array $campaignPresets): ?WP_Error
    {
        // A block hidden by its display condition submits no value by design, so
        // its required/format rules must not be enforced (mirrors the client).
        if (isset($attrs['condition']) && is_array($attrs['condition'])
            && ! ConditionEvaluator::passes($attrs['condition'], $body)) {
            return null;
        }

        $profile = is_array($body['profile'] ?? null) ? $body['profile'] : [];
        $custom  = is_array($body['custom'] ?? null) ? $body['custom'] : [];

        switch ($name) {
            case 'dono/name':
                // requireFirst/requireLast default true (NameBlock); the editor
                // omits an attr equal to its default, so absent means required.
                if ((bool) ($attrs['requireFirst'] ?? true) && ! $this->filled($profile['first_name'] ?? null)) {
                    return $this->requiredError(__('First name', 'dono'));
                }
                if ((bool) ($attrs['requireLast'] ?? true) && ! $this->filled($profile['last_name'] ?? null)) {
                    return $this->requiredError(__('Last name', 'dono'));
                }
                break;

            case 'dono/terms':
                // The consent record is only worth keeping if agreement was
                // actually required, and this is the only side the donor cannot edit.
                if (TermsBlock::isConfigured($attrs)) {
                    $consents = is_array($body['consents'] ?? null) ? $body['consents'] : [];
                    if (empty($consents[TermsBlock::PURPOSE])) {
                        return $this->reject(__('Please agree to the terms to continue.', 'dono'));
                    }
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
                $note = (string) ($body['note_to_org'] ?? '');
                if (! empty($attrs['required']) && ! $this->filled($note)) {
                    return $this->requiredError($this->label($attrs, __('Comment', 'dono')));
                }
                // Cap length server-side: the note can surface publicly, and the
                // client's maxlength is bypassable by a crafted POST.
                $noteMax = (int) ($attrs['maxLength'] ?? 5000);
                if ($noteMax > 0 && mb_strlen($note) > $noteMax) {
                    return $this->reject(__('Your message is too long.', 'dono'));
                }
                break;

            case 'dono/donation-amount':
                // A presets-only form (custom amounts disabled) must only accept
                // a listed preset; a crafted POST can otherwise send any amount.
                // 'fixed' donation type is a single custom input, so it's exempt.
                $amountType = (string) ($attrs['donationType'] ?? 'multi');
                $allowCustom = $amountType === 'fixed' ? true : (bool) ($attrs['allowCustom'] ?? true);
                if (! $allowCustom) {
                    $raw = $attrs['presets'] ?? null;
                    if (! is_array($raw) || empty($raw)) {
                        $raw = $campaignPresets;
                    }
                    // Renderer parity: the amounts the donor was shown pass
                    // through the same filter (variant/visitor context is
                    // render-only and unavailable at submit time).
                    $presets = (array) apply_filters(
                        'dono.form.amounts',
                        DonationAmountBlock::normalizePresets($raw),
                        $this->form,
                        null,
                        null
                    );
                    $allowedCents = array_map(
                        static fn ($p) => (int) ($p['cents'] ?? 0),
                        $presets
                    );
                    // The charged amount folds the optional covered fee on top
                    // of the chosen preset; membership applies to the net.
                    $gross = (int) ($body['amount_cents'] ?? 0);
                    $fee   = min($gross, max(0, (int) ($body['fee_covered_cents'] ?? 0)));
                    $net   = $gross - $fee;
                    // Presets are authored in the org base currency. A donor who
                    // switched currency pays a converted, rounded value this side
                    // cannot reproduce (rates drift between render and submit), so
                    // membership is only enforceable in the authored currency; the
                    // amount floor/cap still apply. Keyed on the form offering that
                    // choice, not on the currency posted: a form with no switcher
                    // can only be paid in the authored currency, so naming another
                    // one in the JSON would skip the allow-list entirely.
                    $submittedCurrency = strtoupper((string) ($body['currency'] ?? ''));
                    $presetCurrency    = strtoupper(Money::defaultCurrency());
                    $convertedByDonor  = $this->offersCurrencyChoice
                        && $submittedCurrency !== ''
                        && $submittedCurrency !== $presetCurrency;

                    if (! $convertedByDonor && ! in_array($net, $allowedCents, true)) {
                        return $this->reject(__('Choose one of the listed donation amounts.', 'dono'));
                    }
                }
                break;

            case 'dono/fund-picker':
                // When the picker restricts to a set of funds, a chosen fund
                // must be one of them; a crafted POST can otherwise route to any
                // fund in the org. A cleared choice (0) falls back to the form's
                // default and is left to the create path.
                $allowedFunds = array_values(array_filter(array_map('intval', (array) ($attrs['fundIds'] ?? []))));
                $chosenFund   = (int) ($body['fund_id'] ?? 0);
                if ($allowedFunds !== [] && $chosenFund !== 0 && ! in_array($chosenFund, $allowedFunds, true)) {
                    return $this->reject(__('That fund is not available for this form.', 'dono'));
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
                // Required lives on the org's purpose, not on the block, so a
                // form cannot make something mandatory the registry does not.
                // Resolved rather than injected: this validator is constructed
                // inline at the one call site and takes no dependencies.
                $registry = \Dono\Foundation\Plugin::instance()->container->get(ConsentService::class);
                foreach (ConsentBlock::purposeKeys($attrs) as $key) {
                    $p = $registry->findPurpose($key);
                    if ($p === null) continue;
                    if (! empty($p['required']) && empty($consents[$key])) {
                        return $this->reject(sprintf(
                            /* translators: %s: consent purpose label */
                            __('Please agree to: %s', 'dono'),
                            (string) ($p['label'] ?? '')
                        ));
                    }
                }
                break;

            case 'dono/recurring-toggle':
                // Gutenberg omits an attribute equal to its registered default,
                // so an absent frequencies key means the default set, not none.
                // Must match the renderer's fallback or offered frequencies are
                // rejected on submit.
                $freqs = RecurringToggleBlock::normalizeFrequencies($attrs['frequencies'] ?? RecurringToggleBlock::DEFAULT_FREQUENCIES);
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

    private static function collectConsentIds(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'dono/consent') {
                $attrs = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                foreach (ConsentBlock::purposeKeys($attrs) as $key) {
                    $ids[$key] = true;
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
