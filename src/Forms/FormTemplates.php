<?php

declare(strict_types=1);

namespace FundKit\Forms;

/** @since 1.0.0 */
final class FormTemplates
{
    /**
     * One template per donor situation, and only situations core can actually
     * build. Memorial, tribute, event ticketing and peer-to-peer need blocks
     * that live in add-ons, so those add-ons register their own through the
     * fundkit.form.templates filter rather than core shipping a template whose
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
        return (array) apply_filters('fundkit.form.templates', $templates);
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
            'name'           => __('Blank', 'fundraising-toolkit'),
            'description'    => __('Empty form. Build it from scratch.', 'fundraising-toolkit'),
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
        $blocks = self::block('fundkit/heading', ['text' => __('Chip in', 'fundraising-toolkit'), 'level' => 2])
                . self::block('fundkit/paragraph', ['text' => __('Every bit helps. Takes 20 seconds.', 'fundraising-toolkit')])
                . self::block('fundkit/donation-amount', [
                    'presets' => self::presets(
                        [10, 25, 50, 100],
                        [
                            __("A coffee's worth", 'fundraising-toolkit'),
                            __('A round of thanks', 'fundraising-toolkit'),
                            __('A bigger boost', 'fundraising-toolkit'),
                            __('MVP status', 'fundraising-toolkit'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => false])
                . self::block('fundkit/email', ['required' => true])
                . self::checkout(
                    __('Send {amount}', 'fundraising-toolkit'),
                    __("I'll cover the fees so 100% goes to the cause", 'fundraising-toolkit')
                );

        return [
            'id'             => 'quick-give',
            'name'           => __('Quick Give', 'fundraising-toolkit'),
            'description'    => __('Minimal single-page form. Amount, name, email, donate. Perfect first form.', 'fundraising-toolkit'),
            'icon'           => 'share',
            'category'       => 'Starter',
            'thumbnail_hint' => 'Mobile-shaped card, three purple progress dots, rounded amount pills.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("You're amazing. Share to multiply your impact.", 'fundraising-toolkit'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function impactTiers(): array
    {
        $blocks = self::block('fundkit/heading', ['text' => __('Every tier makes a real difference', 'fundraising-toolkit'), 'level' => 1])
                . self::block('fundkit/paragraph', ['text' => __('Last year, your support reached thousands of people across our community. Your donation moves a family from just getting by to getting ahead.', 'fundraising-toolkit')])
                . self::block('fundkit/donation-amount', [
                    'presets' => self::presets(
                        [25, 50, 100, 250, 500, 1000],
                        [
                            __('Friend - supports one person', 'fundraising-toolkit'),
                            __('Sustainer - supports a family for a week', 'fundraising-toolkit'),
                            __('Champion - supports a family for a month', 'fundraising-toolkit'),
                            __('Guardian - supports ten households', 'fundraising-toolkit'),
                            __('Patron - supports a community program', 'fundraising-toolkit'),
                            __('Benefactor - supports a person for a season', 'fundraising-toolkit'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('fundkit/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => false])
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('fundkit/email', ['required' => true])
                . self::block('fundkit/comment', [
                    'label' => __('Add a message of support', 'fundraising-toolkit'),
                    'placeholder' => __('Why this cause matters to you...', 'fundraising-toolkit'),
                    'required' => false,
                ])
                . self::block('fundkit/anonymous-toggle', [
                    'label' => __('Hide my name from the donor wall', 'fundraising-toolkit'),
                    'defaultOn' => false,
                ])
                . self::block('fundkit/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __("I'll cover the processing fee so 100% of my donation goes to the mission", 'fundraising-toolkit'),
                    'defaultOn' => false,
                ])
                . self::block('fundkit/payment-gateways', ['style' => 'cards'])
                . self::block('fundkit/donation-summary')
                . self::block('fundkit/submit-button', ['label' => __('Give {amount}', 'fundraising-toolkit')]);

        return [
            'id'             => 'impact-tiers',
            'name'           => __('Impact Tiers', 'fundraising-toolkit'),
            'description'    => __('Long-scroll campaign page where each donation level maps to a named tier and a tangible outcome.', 'fundraising-toolkit'),
            'icon'           => 'awards',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Cream page, serif headline above six green tier cards stacked over a goal bar.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("Thank you. Your donation is already at work. Here's what happens next, and how to tell a friend.", 'fundraising-toolkit'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /**
     * Default fee estimates are editable per organization.
     *
     * @since 1.0.0
     */
    private static function coverFees(string $label): string
    {
        return self::block('fundkit/cover-fees', [
            'percent'   => 2.9,
            'fixed'     => 30,
            'label'     => $label,
            'defaultOn' => true,
        ]);
    }

    /** @since 1.0.0 */
    private static function checkout(string $submitLabel, string $feeLabel): string
    {
        return self::coverFees($feeLabel)
            . self::block('fundkit/payment-gateways', ['style' => 'cards'])
            . self::block('fundkit/donation-summary')
            . self::block('fundkit/submit-button', ['label' => $submitLabel]);
    }

    /** @since 1.0.0 */
    private static function everyday(): array
    {
        $blocks = self::block('fundkit/heading', ['text' => __('Make a donation', 'fundraising-toolkit'), 'level' => 1])
                . self::block('fundkit/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('fundkit/recurring-toggle', [
                    'label'            => __('Make this a recurring donation', 'fundraising-toolkit'),
                    'defaultFrequency' => 'one-time',
                    'frequencies'      => ['one-time', 'monthly'],
                    'style'            => 'pills',
                ])
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('fundkit/email', ['required' => true])
                . self::block('fundkit/country', ['required' => false])
                . self::checkout(
                    __('Donate {amount}', 'fundraising-toolkit'),
                    __('Cover the processing fee so the full amount reaches us', 'fundraising-toolkit')
                );

        return [
            'id'             => 'everyday',
            'name'           => __('Everyday donation', 'fundraising-toolkit'),
            'description'    => __('The general-purpose form: pick an amount, optionally make it monthly, pay. Start here if no other template fits.', 'fundraising-toolkit'),
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
            'fundkit/step',
            ['title' => __('Your donation', 'fundraising-toolkit')],
            self::block('fundkit/donation-amount', [
                'presets'     => self::presets([25, 50, 100, 250], [], 1),
                'allowCustom' => true,
            ])
            . self::block('fundkit/recurring-toggle', [
                'label'            => __('Make this a recurring donation', 'fundraising-toolkit'),
                'defaultFrequency' => 'one-time',
                'frequencies'      => ['one-time', 'monthly'],
                'style'            => 'pills',
            ])
        );

        $details = self::block(
            'fundkit/step',
            ['title' => __('Your details', 'fundraising-toolkit')],
            self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
            . self::block('fundkit/email', ['required' => true])
            . self::block('fundkit/country', ['required' => false])
        );

        $confirm = self::block(
            'fundkit/step',
            ['title' => __('Confirm', 'fundraising-toolkit')],
            self::checkout(
                __('Donate {amount}', 'fundraising-toolkit'),
                __('Cover the processing fee so the full amount reaches us', 'fundraising-toolkit')
            )
        );

        return [
            'id'             => 'guided',
            'name'           => __('Guided donation', 'fundraising-toolkit'),
            'description'    => __('The same fields as the everyday form, split across three steps. Fewer decisions per screen, which suits longer forms and small screens.', 'fundraising-toolkit'),
            'icon'           => 'forms',
            'category'       => 'Wizard',
            'thumbnail_hint' => 'Three-step wizard with dot progress: amounts, then donor fields, then payment.',
            'settings'       => self::defaultSettings(),
            'blocks'         => self::block('fundkit/steps', ['progressStyle' => 'dots'], $amount . $details . $confirm),
        ];
    }

    /** @since 1.0.0 */
    private static function monthlySustainer(): array
    {
        $blocks = self::block('fundkit/heading', ['text' => __('Become a monthly supporter', 'fundraising-toolkit'), 'level' => 1])
                . self::block('fundkit/paragraph', ['text' => __('A donation that arrives every month lets us plan further ahead than any single one can.', 'fundraising-toolkit')])
                // Preselected monthly, and no one-time option: a form that offers
                // both is the everyday one. Add one-time here if you want both.
                . self::block('fundkit/recurring-toggle', [
                    'label'            => __('How often', 'fundraising-toolkit'),
                    'defaultFrequency' => 'monthly',
                    'frequencies'      => ['monthly', 'yearly'],
                    'style'            => 'pills',
                ])
                . self::block('fundkit/donation-amount', [
                    // The amount renders above the label in the org's currency and
                    // the cadence is whichever pill is active, so labels name neither.
                    'presets' => self::presets([10, 25, 50, 100], [
                        __('Supporter', 'fundraising-toolkit'),
                        __('Sustainer', 'fundraising-toolkit'),
                        __('Champion', 'fundraising-toolkit'),
                        __('Guardian', 'fundraising-toolkit'),
                    ], 1),
                    'allowCustom' => true,
                ])
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('fundkit/email', ['required' => true])
                . self::coverFees(__('Cover the processing fee on each payment', 'fundraising-toolkit'))
                . self::block('fundkit/payment-gateways', ['style' => 'cards'])
                . self::block('fundkit/donation-summary')
                // Next to the button, not on a screen already passed: this is the
                // last moment the donor can act on what they are agreeing to.
                . self::block('fundkit/paragraph', ['text' => __('Your first payment is taken today. If you chose a repeating frequency, the same amount is taken on this date at that frequency, and you can change or stop it any time from your donor portal.', 'fundraising-toolkit')])
                . self::block('fundkit/submit-button', ['label' => __('Start my monthly donation', 'fundraising-toolkit')]);

        return [
            'id'             => 'monthly-sustainer',
            'name'           => __('Monthly sustainer', 'fundraising-toolkit'),
            'description'    => __('For recruiting regular givers. Monthly is preselected, amounts are named tiers, and the commitment is restated next to the button.', 'fundraising-toolkit'),
            'icon'           => 'update',
            'category'       => 'Recurring',
            'thumbnail_hint' => 'Monthly/yearly pills with monthly active, named amount tiles, commitment sentence above the button.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Thank you. Your first payment is on its way, and we will email you before anything changes.', 'fundraising-toolkit'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function emergencyAppeal(): array
    {
        $blocks = self::block('fundkit/heading', ['text' => __('Emergency appeal', 'fundraising-toolkit'), 'level' => 1])
                . self::block('fundkit/paragraph', ['text' => __('Say what happened, who it affects, and what a donation pays for today. Keep it to a few sentences.', 'fundraising-toolkit')])
                . self::block('fundkit/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('fundkit/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                // No fund picker: the appeal is the designation. Nothing optional
                // either, because every extra field costs donations while it matters.
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('fundkit/email', ['required' => true])
                . self::checkout(
                    __('Give now', 'fundraising-toolkit'),
                    __('Cover the processing fee so the full amount reaches the response', 'fundraising-toolkit')
                );

        return [
            'id'             => 'emergency-appeal',
            'name'           => __('Emergency appeal', 'fundraising-toolkit'),
            'description'    => __('For a crisis with a deadline. Leads with the goal and countdown, asks only for what a receipt needs, and offers no fund choice because the appeal is the fund.', 'fundraising-toolkit'),
            'icon'           => 'megaphone',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Bold headline over a goal bar with countdown, four amount tiles, two donor fields, one button.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => false, 'frequencies' => []],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Thank you. Your donation is already part of the response.', 'fundraising-toolkit'),
            ],
            'blocks'         => $blocks,
        ];
    }

    /** @since 1.0.0 */
    private static function designated(): array
    {
        $blocks = self::block('fundkit/heading', ['text' => __('Choose where your donation goes', 'fundraising-toolkit'), 'level' => 1])
                . self::block('fundkit/paragraph', ['text' => __('Pick the work you want to fund, or leave it to us to send it wherever it is needed most.', 'fundraising-toolkit')])
                // Needs at least two real funds to be worth showing. The
                // greatest-need option is what a donor with no preference picks.
                . self::block('fundkit/fund-picker', [
                    'label'            => __('Fund', 'fundraising-toolkit'),
                    'allowEmpty'       => true,
                    'emptyLabel'       => __('Wherever the need is greatest', 'fundraising-toolkit'),
                    'emptyDescription' => __('We direct it to the most urgent work that month.', 'fundraising-toolkit'),
                ])
                . self::block('fundkit/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('fundkit/email', ['required' => true])
                . self::block('fundkit/country', ['required' => false])
                . self::checkout(
                    __('Donate {amount}', 'fundraising-toolkit'),
                    __('Cover the processing fee so the full amount reaches this fund', 'fundraising-toolkit')
                );

        return [
            'id'             => 'designated',
            'name'           => __('Designated giving', 'fundraising-toolkit'),
            'description'    => __('Lets the donor say which fund their money goes to. Set up your funds first, since a picker with one option is just a label.', 'fundraising-toolkit'),
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
        $blocks = self::block('fundkit/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('fundkit/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('fundkit/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('fundkit/email', ['required' => true])
                // Both of these assume a public supporter list. Remove them if
                // the page has none: a privacy promise about nothing reads badly.
                . self::block('fundkit/comment', [
                    'label'       => __('Add a message of support', 'fundraising-toolkit'),
                    'placeholder' => __('Shown on the supporter wall', 'fundraising-toolkit'),
                    'required'    => false,
                ])
                . self::block('fundkit/anonymous-toggle', [
                    'label'     => __('Hide my name from the supporter wall', 'fundraising-toolkit'),
                    'defaultOn' => false,
                ])
                . self::checkout(
                    __('Donate {amount}', 'fundraising-toolkit'),
                    __('Cover the processing fee so 100% reaches the campaign', 'fundraising-toolkit')
                );

        return [
            'id'             => 'campaign-page',
            'name'           => __('Campaign page', 'fundraising-toolkit'),
            'description'    => __('For a public campaign with a goal and a supporter wall. Carries a progress bar, a message field and a name-hiding toggle.', 'fundraising-toolkit'),
            'icon'           => 'chart-area',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Goal bar on top, amount tiles, message box and anonymity checkbox above the button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

}
