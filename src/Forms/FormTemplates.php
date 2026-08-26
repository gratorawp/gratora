<?php

declare(strict_types=1);

namespace GiveFlow\Forms;

/** @since 1.0.0 */
final class FormTemplates
{
    /**
     * One template per donor situation, and only situations core can actually
     * build. Memorial, tribute, event ticketing and peer-to-peer need blocks
     * that live in add-ons, so those add-ons register their own through the
     * giveflow.form.templates filter rather than core shipping a template whose
     * fields go missing when the add-on is not installed.
     *
     * @return list<array{id:string,name:string,description:string,icon:string,category:string,thumbnail_hint:string,settings:array<string,mixed>,blocks:string}>
     *
     * @since 1.0.0
     */
    public static function all(): array
    {
        $templates = [
            self::blank(),
            self::everyday(),
            self::guided(),
            self::quickGive(),
            self::monthlySustainer(),
            self::emergencyAppeal(),
            self::designated(),
            self::campaignPage(),
            self::impactTiers(),
        ];
        return (array) apply_filters('giveflow.form.templates', $templates);
    }

    /** @since 1.0.0 */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }

    /** @since 1.0.0 */
    private static function block(string $name, array $attrs = [], string $inner = ''): string
    {
        $attrsJson = $attrs ? ' ' . wp_json_encode($attrs) : '';
        if ($inner === '') {
            return "<!-- wp:{$name}{$attrsJson} /-->\n";
        }
        return "<!-- wp:{$name}{$attrsJson} -->\n{$inner}<!-- /wp:{$name} -->\n";
    }

    /** @since 1.0.0 */
    private static function presets(array $dollars, array $impactLabels = [], int $preselectedIndex = -1): array
    {
        $out = [];
        foreach ($dollars as $i => $amount) {
            $out[] = [
                'cents'       => (int) round($amount * 100),
                'impact'      => $impactLabels[$i] ?? '',
                'preselected' => $i === $preselectedIndex,
            ];
        }
        return $out;
    }

    /** @since 1.0.0 */
    private static function defaultSettings(): array
    {
        return [
            'layout'            => 'inline',
            'style'             => ['preset_id' => ''],
            'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
            'gateways'          => ['allowed' => []],
            'anonymous_allowed' => true,
            'thank_you_message' => '',
        ];
    }

    /** @since 1.0.0 */
    private static function blank(): array
    {
        return [
            'id'             => 'blank',
            'name'           => __('Blank', 'giveflow-fundraising-campaigns'),
            'description'    => __('Empty form. Build it from scratch.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'admin-page',
            'category'       => 'Blank',
            'thumbnail_hint' => 'Empty canvas with a single + button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => '',
        ];
    }

    /** @since 1.0.0 */
    private static function quickGive(): array
    {
        // Short because that is the entire point. A goal bar, phone number,
        // message box and anonymity toggle all belong on some other template.
        $blocks = self::block('giveflow/heading', ['text' => __('Chip in', 'giveflow-fundraising-campaigns'), 'level' => 2])
                . self::block('giveflow/paragraph', ['text' => __('Every bit helps. Takes 20 seconds.', 'giveflow-fundraising-campaigns')])
                . self::block('giveflow/donation-amount', [
                    'presets' => self::presets(
                        [10, 25, 50, 100],
                        [
                            __("A coffee's worth", 'giveflow-fundraising-campaigns'),
                            __('A round of thanks', 'giveflow-fundraising-campaigns'),
                            __('A bigger boost', 'giveflow-fundraising-campaigns'),
                            __('MVP status', 'giveflow-fundraising-campaigns'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => false])
                . self::block('giveflow/email', ['required' => true])
                . self::checkout(
                    __('Send {amount}', 'giveflow-fundraising-campaigns'),
                    __("I'll cover the fees so 100% goes to the cause", 'giveflow-fundraising-campaigns')
                );

        return [
            'id'             => 'quick-give',
            'name'           => __('Quick Give', 'giveflow-fundraising-campaigns'),
            'description'    => __('Minimal single-page form. Amount, name, email, donate. Perfect first form.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'share',
            'category'       => 'Starter',
            'thumbnail_hint' => 'Mobile-shaped card, three purple progress dots, rounded amount pills.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("You're amazing. Share to multiply your impact.", 'giveflow-fundraising-campaigns'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function impactTiers(): array
    {
        $blocks = self::block('giveflow/heading', ['text' => __('Every tier makes a real difference', 'giveflow-fundraising-campaigns'), 'level' => 1])
                . self::block('giveflow/paragraph', ['text' => __('Last year, your support reached thousands of people across our community. Your donation moves a family from just getting by to getting ahead.', 'giveflow-fundraising-campaigns')])
                . self::block('giveflow/donation-amount', [
                    'presets' => self::presets(
                        [25, 50, 100, 250, 500, 1000],
                        [
                            __('Friend - supports one person', 'giveflow-fundraising-campaigns'),
                            __('Sustainer - supports a family for a week', 'giveflow-fundraising-campaigns'),
                            __('Champion - supports a family for a month', 'giveflow-fundraising-campaigns'),
                            __('Guardian - supports ten households', 'giveflow-fundraising-campaigns'),
                            __('Patron - supports a community program', 'giveflow-fundraising-campaigns'),
                            __('Benefactor - supports a person for a season', 'giveflow-fundraising-campaigns'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('giveflow/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => false])
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('giveflow/email', ['required' => true])
                . self::block('giveflow/comment', [
                    'label' => __('Add a message of support', 'giveflow-fundraising-campaigns'),
                    'placeholder' => __('Why this cause matters to you...', 'giveflow-fundraising-campaigns'),
                    'required' => false,
                ])
                . self::block('giveflow/anonymous-toggle', [
                    'label' => __('Hide my name from the donor wall', 'giveflow-fundraising-campaigns'),
                    'defaultOn' => false,
                ])
                . self::block('giveflow/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __("I'll cover the processing fee so 100% of my donation goes to the mission", 'giveflow-fundraising-campaigns'),
                    'defaultOn' => false,
                ])
                . self::block('giveflow/payment-gateways', ['style' => 'cards'])
                . self::block('giveflow/donation-summary')
                . self::block('giveflow/submit-button', ['label' => __('Give {amount}', 'giveflow-fundraising-campaigns')]);

        return [
            'id'             => 'impact-tiers',
            'name'           => __('Impact Tiers', 'giveflow-fundraising-campaigns'),
            'description'    => __('Long-scroll campaign page where each donation level maps to a named tier and a tangible outcome.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'awards',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Cream page, serif headline above six green tier cards stacked over a goal bar.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("Thank you. Your donation is already at work. Here's what happens next, and how to tell a friend.", 'giveflow-fundraising-campaigns'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /**
     * Standard fee-cover block. The rate matches the common card cost; an org
     * on different pricing edits the numbers rather than the wording.
     *
     * @since 1.0.0
     */
    private static function coverFees(string $label): string
    {
        return self::block('giveflow/cover-fees', [
            'percent'   => 2.9,
            'fixed'     => 30,
            'label'     => $label,
            'defaultOn' => true,
        ]);
    }

    /**
     * Gateways, running total, button. Every template ends this way.
     *
     * @since 1.0.0
     */
    private static function checkout(string $submitLabel, string $feeLabel): string
    {
        return self::coverFees($feeLabel)
            . self::block('giveflow/payment-gateways', ['style' => 'cards'])
            . self::block('giveflow/donation-summary')
            . self::block('giveflow/submit-button', ['label' => $submitLabel]);
    }

    /** @since 1.0.0 */
    private static function everyday(): array
    {
        $blocks = self::block('giveflow/heading', ['text' => __('Make a donation', 'giveflow-fundraising-campaigns'), 'level' => 1])
                . self::block('giveflow/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('giveflow/recurring-toggle', [
                    'label'            => __('Make this a recurring donation', 'giveflow-fundraising-campaigns'),
                    'defaultFrequency' => 'one-time',
                    'frequencies'      => ['one-time', 'monthly'],
                    'style'            => 'pills',
                ])
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('giveflow/email', ['required' => true])
                . self::block('giveflow/country', ['required' => false])
                . self::checkout(
                    __('Donate {amount}', 'giveflow-fundraising-campaigns'),
                    __('Cover the processing fee so the full amount reaches us', 'giveflow-fundraising-campaigns')
                );

        return [
            'id'             => 'everyday',
            'name'           => __('Everyday donation', 'giveflow-fundraising-campaigns'),
            'description'    => __('The general-purpose form: pick an amount, optionally make it monthly, pay. Start here if no other template fits.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'heart',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Single column, four amount tiles, one-time/monthly pills, three donor fields, pay button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function guided(): array
    {
        $amount = self::block(
            'giveflow/step',
            ['title' => __('Your donation', 'giveflow-fundraising-campaigns')],
            self::block('giveflow/donation-amount', [
                'presets'     => self::presets([25, 50, 100, 250], [], 1),
                'allowCustom' => true,
            ])
            . self::block('giveflow/recurring-toggle', [
                'label'            => __('Make this a recurring donation', 'giveflow-fundraising-campaigns'),
                'defaultFrequency' => 'one-time',
                'frequencies'      => ['one-time', 'monthly'],
                'style'            => 'pills',
            ])
        );

        $details = self::block(
            'giveflow/step',
            ['title' => __('Your details', 'giveflow-fundraising-campaigns')],
            self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
            . self::block('giveflow/email', ['required' => true])
            . self::block('giveflow/country', ['required' => false])
        );

        $confirm = self::block(
            'giveflow/step',
            ['title' => __('Confirm', 'giveflow-fundraising-campaigns')],
            self::checkout(
                __('Donate {amount}', 'giveflow-fundraising-campaigns'),
                __('Cover the processing fee so the full amount reaches us', 'giveflow-fundraising-campaigns')
            )
        );

        return [
            'id'             => 'guided',
            'name'           => __('Guided donation', 'giveflow-fundraising-campaigns'),
            'description'    => __('The same fields as the everyday form, split across three steps. Fewer decisions per screen, which suits longer forms and small screens.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'forms',
            'category'       => 'Wizard',
            'thumbnail_hint' => 'Three-step wizard with dot progress: amounts, then donor fields, then payment.',
            'settings'       => self::defaultSettings(),
            'blocks'         => self::block('giveflow/steps', ['progressStyle' => 'dots'], $amount . $details . $confirm),
        ];
    }

    /** @since 1.0.0 */
    private static function monthlySustainer(): array
    {
        $blocks = self::block('giveflow/heading', ['text' => __('Become a monthly supporter', 'giveflow-fundraising-campaigns'), 'level' => 1])
                . self::block('giveflow/paragraph', ['text' => __('A donation that arrives every month lets us plan further ahead than any single one can.', 'giveflow-fundraising-campaigns')])
                // Preselected monthly, and no one-time option: a form that offers
                // both is the everyday one. Add one-time here if you want both.
                . self::block('giveflow/recurring-toggle', [
                    'label'            => __('How often', 'giveflow-fundraising-campaigns'),
                    'defaultFrequency' => 'monthly',
                    'frequencies'      => ['monthly', 'yearly'],
                    'style'            => 'pills',
                ])
                . self::block('giveflow/donation-amount', [
                    // The amount renders above the label in the org's currency and
                    // the cadence is whichever pill is active, so labels name neither.
                    'presets' => self::presets([10, 25, 50, 100], [
                        __('Supporter', 'giveflow-fundraising-campaigns'),
                        __('Sustainer', 'giveflow-fundraising-campaigns'),
                        __('Champion', 'giveflow-fundraising-campaigns'),
                        __('Guardian', 'giveflow-fundraising-campaigns'),
                    ], 1),
                    'allowCustom' => true,
                ])
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('giveflow/email', ['required' => true])
                . self::coverFees(__('Cover the processing fee on each payment', 'giveflow-fundraising-campaigns'))
                . self::block('giveflow/payment-gateways', ['style' => 'cards'])
                . self::block('giveflow/donation-summary')
                // Next to the button, not on a screen already passed: this is the
                // last moment the donor can act on what they are agreeing to.
                . self::block('giveflow/paragraph', ['text' => __('Your first payment is taken today. If you chose a repeating frequency, the same amount is taken on this date at that frequency, and you can change or stop it any time from your donor portal.', 'giveflow-fundraising-campaigns')])
                . self::block('giveflow/submit-button', ['label' => __('Start my monthly donation', 'giveflow-fundraising-campaigns')]);

        return [
            'id'             => 'monthly-sustainer',
            'name'           => __('Monthly sustainer', 'giveflow-fundraising-campaigns'),
            'description'    => __('For recruiting regular givers. Monthly is preselected, amounts are named tiers, and the commitment is restated next to the button.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'update',
            'category'       => 'Recurring',
            'thumbnail_hint' => 'Monthly/yearly pills with monthly active, named amount tiles, commitment sentence above the button.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Thank you. Your first payment is on its way, and we will email you before anything changes.', 'giveflow-fundraising-campaigns'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function emergencyAppeal(): array
    {
        $blocks = self::block('giveflow/heading', ['text' => __('Emergency appeal', 'giveflow-fundraising-campaigns'), 'level' => 1])
                . self::block('giveflow/paragraph', ['text' => __('Say what happened, who it affects, and what a donation pays for today. Keep it to a few sentences.', 'giveflow-fundraising-campaigns')])
                . self::block('giveflow/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('giveflow/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                // No fund picker: the appeal is the designation. Nothing optional
                // either, because every extra field costs donations while it matters.
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('giveflow/email', ['required' => true])
                . self::checkout(
                    __('Give now', 'giveflow-fundraising-campaigns'),
                    __('Cover the processing fee so the full amount reaches the response', 'giveflow-fundraising-campaigns')
                );

        return [
            'id'             => 'emergency-appeal',
            'name'           => __('Emergency appeal', 'giveflow-fundraising-campaigns'),
            'description'    => __('For a crisis with a deadline. Leads with the goal and countdown, asks only for what a receipt needs, and offers no fund choice because the appeal is the fund.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'megaphone',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Bold headline over a goal bar with countdown, four amount tiles, two donor fields, one button.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => false, 'frequencies' => []],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Thank you. Your donation is already part of the response.', 'giveflow-fundraising-campaigns'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function designated(): array
    {
        $blocks = self::block('giveflow/heading', ['text' => __('Choose where your donation goes', 'giveflow-fundraising-campaigns'), 'level' => 1])
                . self::block('giveflow/paragraph', ['text' => __('Pick the work you want to fund, or leave it to us to send it wherever it is needed most.', 'giveflow-fundraising-campaigns')])
                // Needs at least two real funds to be worth showing. The
                // greatest-need option is what a donor with no preference picks.
                . self::block('giveflow/fund-picker', [
                    'label'            => __('Fund', 'giveflow-fundraising-campaigns'),
                    'allowEmpty'       => true,
                    'emptyLabel'       => __('Wherever the need is greatest', 'giveflow-fundraising-campaigns'),
                    'emptyDescription' => __('We direct it to the most urgent work that month.', 'giveflow-fundraising-campaigns'),
                ])
                . self::block('giveflow/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('giveflow/email', ['required' => true])
                . self::block('giveflow/country', ['required' => false])
                . self::checkout(
                    __('Donate {amount}', 'giveflow-fundraising-campaigns'),
                    __('Cover the processing fee so the full amount reaches this fund', 'giveflow-fundraising-campaigns')
                );

        return [
            'id'             => 'designated',
            'name'           => __('Designated giving', 'giveflow-fundraising-campaigns'),
            'description'    => __('Lets the donor say which fund their money goes to. Set up your funds first, since a picker with one option is just a label.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'portfolio',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Fund dropdown above the amount tiles, greatest-need option listed last.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function campaignPage(): array
    {
        $blocks = self::block('giveflow/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('giveflow/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('giveflow/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('giveflow/email', ['required' => true])
                // Both of these assume a public supporter list. Remove them if
                // the page has none: a privacy promise about nothing reads badly.
                . self::block('giveflow/comment', [
                    'label'       => __('Add a message of support', 'giveflow-fundraising-campaigns'),
                    'placeholder' => __('Shown on the supporter wall', 'giveflow-fundraising-campaigns'),
                    'required'    => false,
                ])
                . self::block('giveflow/anonymous-toggle', [
                    'label'     => __('Hide my name from the supporter wall', 'giveflow-fundraising-campaigns'),
                    'defaultOn' => false,
                ])
                . self::checkout(
                    __('Donate {amount}', 'giveflow-fundraising-campaigns'),
                    __('Cover the processing fee so 100% reaches the campaign', 'giveflow-fundraising-campaigns')
                );

        return [
            'id'             => 'campaign-page',
            'name'           => __('Campaign page', 'giveflow-fundraising-campaigns'),
            'description'    => __('For a public campaign with a goal and a supporter wall. Carries a progress bar, a message field and a name-hiding toggle.', 'giveflow-fundraising-campaigns'),
            'icon'           => 'chart-area',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Goal bar on top, amount tiles, message box and anonymity checkbox above the button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

}
