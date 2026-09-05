<?php

declare(strict_types=1);

namespace FundKit\Forms;

use FundKit\Campaigns\Campaign;
use FundKit\Currency\Currency;
use FundKit\Currency\FxRates;
use FundKit\Donors\ConsentService;
use FundKit\Forms\Blocks\ConsentBlock;
use FundKit\Forms\Blocks\TermsBlock;
use FundKit\Forms\Blocks\DateBlock;
use FundKit\Forms\Blocks\DonationAmountBlock;
use FundKit\Forms\Blocks\DropdownBlock;
use FundKit\Forms\Blocks\RecurringToggleBlock;
use FundKit\Foundation\Helpers\Money;
use WP_Error;

/**
 * The REST schema cannot express "required iff the author toggled it", so
 * per-field rules are enforced here once the form resolves. The Preact client
 * validates the same rules; this side closes the crafted-POST hole.
 *
 * @since 1.0.0
 */
final class FormSubmissionValidator
{
    /** How far a converted preset may sit from the figure the donor was shown. */
    private const PRESET_TOLERANCE = 0.15;

    private ?Form $form = null;

    private bool $offersCurrencyChoice = false;

    /** @since 1.0.0 */
    public function validate(Form $form, array $body): ?WP_Error
    {
        $this->form = $form;
        $blocks = parse_blocks((string) ($form->blocks ?? ''));
        $this->offersCurrencyChoice = self::treeHasBlock($blocks, 'fundkit/currency-switcher');

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
            return $this->reject(__('That donation frequency is not available for this form.', 'fundraising-toolkit'));
        }

        return null;
    }

    /** @since 1.0.0 */
    public static function consentPurposeIds(string $blocks): array
    {
        $ids = [];
        self::collectConsentIds(parse_blocks((string) $blocks), $ids);
        return $ids;
    }

    /**
     * Recorded alongside the acceptance, so editing the terms later cannot
     * rewrite what somebody already agreed to. Null when there is nothing to agree to.
     *
     * @since 1.0.0
     */
    public static function termsRevision(string $blocks): ?int
    {
        return self::findTermsRevision(parse_blocks($blocks));
    }

    /** @since 1.0.0 */
    private static function findTermsRevision(array $blocks): ?int
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'fundkit/terms') {
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

    /** @since 1.0.0 */
    public static function hasBlock(string $blocks, string $blockName): bool
    {
        return self::treeHasBlock(parse_blocks($blocks), $blockName);
    }

    /** @since 1.0.0 */
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

    /**
     * Whether this submission was actually offered the block.
     *
     * Present is not the same as offered: a block hidden by its own display
     * condition submits nothing and validateBlock returns before its rules run,
     * so a gate keyed on presence let a crafted payload send a value the block
     * would never have accepted. Stays in lockstep with validateBlock, which
     * tests a block's own condition and nothing else.
     *
     * @since 1.0.0
     */
    public static function offersBlock(string $blocks, string $blockName, array $body): bool
    {
        return self::treeOffersBlock(parse_blocks($blocks), $blockName, $body);
    }

    /** @param array<string,mixed> $body */
    private static function treeOffersBlock(array $blocks, string $blockName, array $body): bool
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === $blockName) {
                $attrs  = is_array($block['attrs'] ?? null) ? $block['attrs'] : [];
                $hidden = isset($attrs['condition']) && is_array($attrs['condition'])
                    && ! ConditionEvaluator::passes($attrs['condition'], $body);
                if (! $hidden) {
                    return true;
                }
            }
            if (! empty($block['innerBlocks']) && is_array($block['innerBlocks'])
                && self::treeOffersBlock($block['innerBlocks'], $blockName, $body)) {
                return true;
            }
        }
        return false;
    }

    /** @since 1.0.0 */
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

    /** @since 1.0.0 */
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
            case 'fundkit/name':
                // requireFirst/requireLast default true (NameBlock); the editor
                // omits an attr equal to its default, so absent means required.
                if ((bool) ($attrs['requireFirst'] ?? true) && ! $this->filled($profile['first_name'] ?? null)) {
                    return $this->requiredError(__('First name', 'fundraising-toolkit'));
                }
                if ((bool) ($attrs['requireLast'] ?? true) && ! $this->filled($profile['last_name'] ?? null)) {
                    return $this->requiredError(__('Last name', 'fundraising-toolkit'));
                }
                break;

            case 'fundkit/terms':
                // The consent record is only worth keeping if agreement was
                // actually required, and this is the only side the donor cannot edit.
                if (TermsBlock::isConfigured($attrs)) {
                    $consents = is_array($body['consents'] ?? null) ? $body['consents'] : [];
                    if (empty($consents[TermsBlock::PURPOSE])) {
                        return $this->reject(__('Please agree to the terms to continue.', 'fundraising-toolkit'));
                    }
                }
                break;

            case 'fundkit/phone':
                if (! empty($attrs['required']) && ! $this->filled($profile['phone'] ?? null)) {
                    return $this->requiredError($this->label($attrs, __('Phone', 'fundraising-toolkit')));
                }
                break;

            case 'fundkit/country':
                if (! empty($attrs['required']) && ! $this->filled($profile['country'] ?? null)) {
                    return $this->requiredError($this->label($attrs, __('Country', 'fundraising-toolkit')));
                }
                break;

            case 'fundkit/comment':
                $note = (string) ($body['note_to_org'] ?? '');
                if (! empty($attrs['required']) && ! $this->filled($note)) {
                    return $this->requiredError($this->label($attrs, __('Comment', 'fundraising-toolkit')));
                }
                // Cap length server-side: the note can surface publicly, and the
                // client's maxlength is bypassable by a crafted POST.
                $noteMax = (int) ($attrs['maxLength'] ?? 5000);
                if ($noteMax > 0 && mb_strlen($note) > $noteMax) {
                    return $this->reject(__('Your message is too long.', 'fundraising-toolkit'));
                }
                break;

            case 'fundkit/donation-amount':
                // A presets-only form (custom amounts disabled) must only accept
                // a listed preset; a crafted POST can otherwise send any amount.
                // 'fixed' donation type is a single custom input, so it's exempt.
                $allowCustom = DonationAmountBlock::acceptsTypedAmount($attrs);

                // A minimum set on the block. Checked against the net, like the
                // preset check below: covering the fee is not the donor giving
                // more, so it must not lift them over the bar. It bounds what a
                // donor types, so a presets-only block does not carry one: the
                // listed amounts are the whole menu, and the editor offers no
                // minimum there to see or clear.
                $minCents = (int) ($attrs['minCents'] ?? 0);
                if ($allowCustom && $minCents > 0) {
                    $net      = (int) ($body['amount_cents'] ?? 0) - (int) ($body['fee_covered_cents'] ?? 0);
                    $authored = self::authoredCurrency($attrs);
                    $paying   = $this->payingCurrency($authored, $body);
                    $bar      = $this->minimumIn($minCents, $authored, $paying);
                    if ($bar !== null && $net < $bar) {
                        return $this->reject(sprintf(
                            /* translators: %s: minimum donation amount, formatted. */
                            __('The smallest donation this form accepts is %s.', 'fundraising-toolkit'),
                            Money::format($bar, $paying)
                        ));
                    }
                }
                if (! $allowCustom) {
                    $raw = $attrs['presets'] ?? null;
                    if (! is_array($raw) || empty($raw)) {
                        $raw = $campaignPresets;
                    }
                    // Renderer parity: the amounts the donor was shown pass
                    // through the same filter (variant/visitor context is
                    // render-only and unavailable at submit time).
                    $presets = (array) apply_filters(
                        'fundkit.form.amounts',
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
                    // Keyed on the form offering the choice, not on the currency
                    // posted: a form with no switcher can only be paid in the
                    // authored currency, so naming another in the JSON would skip
                    // the allow-list entirely.
                    $submittedCurrency = strtoupper((string) ($body['currency'] ?? ''));
                    $presetCurrency    = strtoupper(Money::defaultCurrency());
                    $convertedByDonor  = $this->offersCurrencyChoice
                        && $submittedCurrency !== ''
                        && $submittedCurrency !== $presetCurrency;

                    if (! $convertedByDonor) {
                        if (! in_array($net, $allowedCents, true)) {
                            return $this->reject(__('Choose one of the listed donation amounts.', 'fundraising-toolkit'));
                        }
                        break;
                    }

                    // Converted, the exact figure is not reproducible: the rate
                    // moves between render and submit. Near one of them is, and
                    // the alternative was accepting any amount at all, which let
                    // a crafted payload name a currency and pay what it liked on
                    // a form whose whole point is a fixed menu.
                    //
                    // Wide on purpose. A day of rate movement is a fraction of
                    // this, so no donor is refused the figure they were shown,
                    // and it still holds the amount to the menu.
                    if (! self::nearAnyPreset($net, $allowedCents, $presetCurrency, $submittedCurrency)) {
                        return $this->reject(__('Choose one of the listed donation amounts.', 'fundraising-toolkit'));
                    }
                }
                break;

            case 'fundkit/fund-picker':
                // When the picker restricts to a set of funds, a chosen fund
                // must be one of them; a crafted POST can otherwise route to any
                // fund in the org. A cleared choice (0) falls back to the form's
                // default and is left to the create path.
                $allowedFunds = array_values(array_filter(array_map('intval', (array) ($attrs['fundIds'] ?? []))));
                $chosenFund   = (int) ($body['fund_id'] ?? 0);
                if ($allowedFunds !== [] && $chosenFund !== 0 && ! in_array($chosenFund, $allowedFunds, true)) {
                    return $this->reject(__('That fund is not available for this form.', 'fundraising-toolkit'));
                }
                break;

            case 'fundkit/address':
                $addr = is_array($profile['address'] ?? null) ? $profile['address'] : [];
                $sub  = [
                    'line1'   => ['showLine1',   'requireLine1',   true,  __('Address', 'fundraising-toolkit')],
                    'city'    => ['showCity',    'requireCity',    true,  __('City', 'fundraising-toolkit')],
                    'region'  => ['showRegion',  'requireRegion',  false, __('Region', 'fundraising-toolkit')],
                    'postal'  => ['showPostal',  'requirePostal',  true,  __('Postal code', 'fundraising-toolkit')],
                    'country' => ['showCountry', 'requireCountry', true,  __('Country', 'fundraising-toolkit')],
                ];
                foreach ($sub as $key => [$showAttr, $reqAttr, $reqDefault, $sLabel]) {
                    $shown    = (bool) ($attrs[$showAttr] ?? true);
                    $required = (bool) ($attrs[$reqAttr] ?? $reqDefault);
                    if ($shown && $required && ! $this->filled($addr[$key] ?? null)) {
                        return $this->requiredError($sLabel);
                    }
                }
                break;

            case 'fundkit/text-input':
                $key = $this->customKey($attrs);
                $val = $custom[$key] ?? null;
                if (! empty($attrs['required']) && ! $this->filled($val)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                if ($this->filled($val)) {
                    $max = (int) ($attrs['maxLength'] ?? 0);
                    if ($max > 0 && mb_strlen((string) $val) > $max) {
                        return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('%s is too long.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                    }
                    $pattern = (string) ($attrs['pattern'] ?? '');
                    if ($pattern !== '' && ! $this->matchesPattern($pattern, (string) $val)) {
                        return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('%s is not in the expected format.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                    }
                }
                break;

            case 'fundkit/number-input':
                $key = $this->customKey($attrs);
                $val = $custom[$key] ?? null;
                if (! empty($attrs['required']) && ! $this->filled($val)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                if ($this->filled($val)) {
                    if (! is_numeric($val)) {
                        return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('%s must be a number.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                    }
                    $n = (float) $val;
                    if (isset($attrs['min']) && is_numeric($attrs['min']) && $n < (float) $attrs['min']) {
                        return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('%s is below the minimum.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                    }
                    if (isset($attrs['max']) && is_numeric($attrs['max']) && $n > (float) $attrs['max']) {
                        return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('%s is above the maximum.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                    }
                }
                break;

            case 'fundkit/date':
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
                        return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('%s is outside the allowed range.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                    }
                }
                break;

            case 'fundkit/dropdown':
            case 'fundkit/radio':
                $key = $this->customKey($attrs);
                if (! empty($attrs['required']) && ! $this->filled($custom[$key] ?? null)) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                break;

            case 'fundkit/checkbox':
                $key = $this->customKey($attrs);
                if (! empty($attrs['required']) && empty($custom[$key])) {
                    return $this->reject(sprintf(/* translators: %s: the label of the form field. */ __('Please check %s.', 'fundraising-toolkit'), $this->label($attrs, $key)));
                }
                break;

            case 'fundkit/multi-select':
                $key   = $this->customKey($attrs);
                $sel   = is_array($custom[$key] ?? null) ? $custom[$key] : [];
                $count = count($sel);
                if (! empty($attrs['required']) && $count === 0) {
                    return $this->requiredError($this->label($attrs, $key));
                }
                $min = max(0, (int) ($attrs['minSelections'] ?? 0));
                $max = max(0, (int) ($attrs['maxSelections'] ?? 0));
                if ($count > 0 && $min > 0 && $count < $min) {
                    return $this->reject(sprintf(/* translators: %1$d: smallest number of options allowed. %2$s: the label of the form field. */ __('Select at least %1$d for %2$s.', 'fundraising-toolkit'), $min, $this->label($attrs, $key)));
                }
                if ($max > 0 && $count > $max) {
                    return $this->reject(sprintf(/* translators: %1$d: largest number of options allowed. %2$s: the label of the form field. */ __('Select at most %1$d for %2$s.', 'fundraising-toolkit'), $max, $this->label($attrs, $key)));
                }
                break;

            case 'fundkit/consent':
                $consents = is_array($body['consents'] ?? null) ? $body['consents'] : [];
                // Required lives on the org's purpose, not on the block, so a
                // form cannot make something mandatory the registry does not.
                // Resolved rather than injected: this validator is constructed
                // inline at the one call site and takes no dependencies.
                $registry = \FundKit\Foundation\Plugin::instance()->container->get(ConsentService::class);
                foreach (ConsentBlock::purposeKeys($attrs) as $key) {
                    $p = $registry->findPurpose($key);
                    if ($p === null) continue;
                    if (! empty($p['required']) && empty($consents[$key])) {
                        return $this->reject(sprintf(
                            /* translators: %s: consent purpose label */
                            __('Please agree to: %s', 'fundraising-toolkit'),
                            (string) ($p['label'] ?? '')
                        ));
                    }
                }
                break;

            case 'fundkit/recurring-toggle':
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

    /**
     * The currency the block's amounts are written in: its own attribute, or
     * the org default. Presets, and the minimum beside them, are authored here.
     *
     * @since 1.0.0
     */
    private static function authoredCurrency(array $attrs): string
    {
        $code = strtoupper(trim((string) ($attrs['currency'] ?? '')));
        return $code !== '' ? $code : strtoupper(Money::defaultCurrency());
    }

    /**
     * A form with no switcher can only be paid in the currency it authored, so
     * naming another one in the JSON must not move the bar.
     *
     * @since 1.0.0
     */
    private function payingCurrency(string $authored, array $body): string
    {
        $submitted = strtoupper(trim((string) ($body['currency'] ?? '')));
        if ($submitted === '' || ! $this->offersCurrencyChoice) {
            return $authored;
        }
        return $submitted;
    }

    /**
     * The minimum expressed in the currency the donor is paying in. Comparing
     * the authored figure against another currency's minor units enforces a
     * different bar than the one the author set, in both directions.
     *
     * Null when no rate makes the comparison meaningful: the org enabled a
     * currency it cannot convert (the settings screen warns about that), and a
     * floor nobody can state in the donor's currency is not one to enforce.
     *
     * @since 1.0.0
     */
    private function minimumIn(int $minCents, string $authored, string $paying): ?int
    {
        if ($paying === $authored) {
            return $minCents;
        }

        $converted = (new FxRates())->convertCents($minCents, $authored, $paying);
        if ($converted === null) {
            return null;
        }

        // Zero-decimal amounts land on whole major units, so a bar between two
        // of them would refuse the figure the message quotes.
        $bar = Currency::minorUnits($paying) === 0
            ? (int) (ceil($converted / 100) * 100)
            : $converted;

        // The form renders converted presets nice-rounded, which can land below
        // the exact conversion, and a form must accept the amount it offers.
        // niceAmount never decreases as its input grows, so every preset at or
        // above the authored minimum still clears this bar.
        return min($bar, self::niceAmount($converted));
    }

    /**
     * The rounding the rendered amount tiles get: assets/donation-form/util/fx.js
     * nicePreset, in whole major units with a step that scales with magnitude.
     *
     * @since 1.0.0
     */
    /**
     * Whether a converted amount is one of the offered ones.
     *
     * Unconvertible currencies pass: no rate is a fact about this site, not
     * about the donor, and refusing them would close the form.
     *
     * @param list<int> $presetsInBase
     *
     * @since 1.0.0
     */
    private static function nearAnyPreset(int $net, array $presetsInBase, string $base, string $paying): bool
    {
        $fx = new FxRates();

        foreach ($presetsInBase as $cents) {
            if ($cents <= 0) {
                continue;
            }

            $converted = $fx->convertCents($cents, $base, $paying);
            if ($converted === null) {
                return true;
            }

            // What the form showed, and the exact conversion, both count.
            foreach ([$converted, self::niceAmount($converted)] as $target) {
                if ($target > 0 && abs($net - $target) <= (int) round($target * self::PRESET_TOLERANCE)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function niceAmount(int $cents): int
    {
        if ($cents <= 0) {
            return $cents;
        }

        $units = $cents / 100;
        $step  = $units >= 100 ? 10 : ($units >= 20 ? 5 : 1);

        return (int) (max($step, round($units / $step) * $step) * 100);
    }

    /** @since 1.0.0 */
    private static function collectConsentIds(array $blocks, array &$ids): void
    {
        foreach ($blocks as $block) {
            if (($block['blockName'] ?? '') === 'fundkit/consent') {
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

    /** @since 1.0.0 */
    private function customKey(array $attrs): string
    {
        return DropdownBlock::deriveField(
            (string) ($attrs['field'] ?? ''),
            (string) ($attrs['label'] ?? '')
        );
    }

    /** @since 1.0.0 */
    private function label(array $attrs, string $fallback): string
    {
        $label = trim((string) ($attrs['label'] ?? ''));
        return $label !== '' ? $label : $fallback;
    }

    /** @since 1.0.0 */
    private function filled(mixed $v): bool
    {
        if ($v === null) return false;
        if (is_string($v)) return trim($v) !== '';
        if (is_array($v)) return $v !== [];
        return $v !== '' && $v !== false;
    }

    /** @since 1.0.0 */
    private function matchesPattern(string $pattern, string $value): bool
    {
        $regex = '~^(?:' . str_replace('~', '\\~', $pattern) . ')$~';
        $r = @preg_match($regex, $value);
        return $r === false ? true : $r === 1;
    }

    /** @since 1.0.0 */
    private function requiredError(string $label): WP_Error
    {
        return $this->reject(sprintf(
            /* translators: %s: form field label */
            __('Please complete the %s field.', 'fundraising-toolkit'),
            $label
        ));
    }

    /** @since 1.0.0 */
    private function reject(string $message): WP_Error
    {
        return new WP_Error('fundkit_form_validation', $message, ['status' => 400]);
    }
}
