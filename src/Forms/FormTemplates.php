<?php

declare(strict_types=1);

namespace Dono\Forms;

final class FormTemplates
{
    /**
     * One template per donor situation, and only situations core can actually
     * build. Memorial, tribute, event ticketing and peer-to-peer need blocks
     * that live in add-ons, so those add-ons register their own through the
     * dono.form.templates filter rather than core shipping a template whose
     * fields go missing when the add-on is not installed.
     *
     * @return list<array{id:string,name:string,description:string,icon:string,category:string,thumbnail_hint:string,settings:array<string,mixed>,blocks:string}>
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
        return (array) apply_filters('dono.form.templates', $templates);
    }

    public static function find(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }

    private static function block(string $name, array $attrs = [], string $inner = ''): string
    {
        $attrsJson = $attrs ? ' ' . wp_json_encode($attrs) : '';
        if ($inner === '') {
            return "<!-- wp:{$name}{$attrsJson} /-->\n";
        }
        return "<!-- wp:{$name}{$attrsJson} -->\n{$inner}<!-- /wp:{$name} -->\n";
    }

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

    private static function defaultSettings(): array
    {
        return [
            'layout'            => 'inline',
            'style'             => ['preset_id' => ''],
            'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
            'gateways'          => ['allowed' => []],
            'anonymous_allowed' => true,
            'thank_you_message' => '',
            'redirect_url'      => '',
        ];
    }

    private static function blank(): array
    {
        return [
            'id'             => 'blank',
            'name'           => __('Blank', 'dono'),
            'description'    => __('Empty form. Build it from scratch.', 'dono'),
            'icon'           => 'admin-page',
            'category'       => 'Blank',
            'thumbnail_hint' => 'Empty canvas with a single + button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => '',
        ];
    }

    private static function quickGive(): array
    {
        // Short because that is the entire point. A goal bar, phone number,
        // message box and anonymity toggle all belong on some other template.
        $blocks = self::block('dono/heading', ['text' => __('Chip in', 'dono'), 'level' => 2])
                . self::block('dono/paragraph', ['text' => __('Every bit helps. Takes 20 seconds.', 'dono')])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [10, 25, 50, 100],
                        [
                            __("A coffee's worth", 'dono'),
                            __('A round of thanks', 'dono'),
                            __('A bigger boost', 'dono'),
                            __('MVP status', 'dono'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => false])
                . self::block('dono/email', ['required' => true])
                . self::checkout(
                    __('Send {amount}', 'dono'),
                    __("I'll cover the fees so 100% goes to the cause", 'dono')
                );

        return [
            'id'             => 'quick-give',
            'name'           => __('Quick Give', 'dono'),
            'description'    => __('Minimal single-page form. Amount, name, email, donate. Perfect first form.', 'dono'),
            'icon'           => 'share',
            'category'       => 'Starter',
            'thumbnail_hint' => 'Mobile-shaped card, three purple progress dots, rounded amount pills.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("You're amazing. Share to multiply your impact.", 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    private static function impactTiers(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('$50 makes a real difference', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('Last year, your support reached thousands of people across our community. Your donation moves a family from just getting by to getting ahead.', 'dono')])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [25, 50, 100, 250, 500, 1000],
                        [
                            __('Friend - supports one person', 'dono'),
                            __('Sustainer - supports a family for a week', 'dono'),
                            __('Champion - supports a family for a month', 'dono'),
                            __('Guardian - supports ten households', 'dono'),
                            __('Patron - supports a community program', 'dono'),
                            __('Benefactor - supports a person for a season', 'dono'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => false])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/comment', [
                    'label' => __('Add a message of support', 'dono'),
                    'placeholder' => __('Why this cause matters to you...', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/anonymous-toggle', [
                    'label' => __('Hide my name from the donor wall', 'dono'),
                    'defaultOn' => false,
                ])
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __("I'll cover the processing fee so 100% of my donation goes to the mission", 'dono'),
                    'defaultOn' => false,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/donation-summary')
                . self::block('dono/submit-button', ['label' => __('Give {amount}', 'dono')]);

        return [
            'id'             => 'impact-tiers',
            'name'           => __('Impact Tiers', 'dono'),
            'description'    => __('Long-scroll campaign page where each donation level maps to a named tier and a tangible outcome.', 'dono'),
            'icon'           => 'awards',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Cream page, serif headline above six green tier cards stacked over a goal bar.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("Thank you. Your donation is already at work. Here's what happens next, and how to tell a friend.", 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /**
     * Standard fee-cover block. The rate matches the common card cost; an org
     * on different pricing edits the numbers rather than the wording.
     */
    private static function coverFees(string $label): string
    {
        return self::block('dono/cover-fees', [
            'percent'   => 2.9,
            'fixed'     => 30,
            'label'     => $label,
            'defaultOn' => true,
        ]);
    }

    /** Gateways, running total, button. Every template ends this way. */
    private static function checkout(string $submitLabel, string $feeLabel): string
    {
        return self::coverFees($feeLabel)
            . self::block('dono/payment-gateways', ['style' => 'cards'])
            . self::block('dono/donation-summary')
            . self::block('dono/submit-button', ['label' => $submitLabel]);
    }

    private static function everyday(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Make a donation', 'dono'), 'level' => 1])
                . self::block('dono/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('dono/recurring-toggle', [
                    'label'            => __('Make this a repeating gift', 'dono'),
                    'defaultFrequency' => 'one-time',
                    'frequencies'      => ['one-time', 'monthly'],
                    'style'            => 'pills',
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/country', ['required' => false])
                . self::checkout(
                    __('Donate {amount}', 'dono'),
                    __('Cover the processing fee so the full amount reaches us', 'dono')
                );

        return [
            'id'             => 'everyday',
            'name'           => __('Everyday donation', 'dono'),
            'description'    => __('The general-purpose form: pick an amount, optionally make it monthly, pay. Start here if no other template fits.', 'dono'),
            'icon'           => 'heart',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Single column, four amount tiles, one-time/monthly pills, three donor fields, pay button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

    private static function guided(): array
    {
        $amount = self::block(
            'dono/step',
            ['title' => __('Your donation', 'dono')],
            self::block('dono/donation-amount', [
                'presets'     => self::presets([25, 50, 100, 250], [], 1),
                'allowCustom' => true,
            ])
            . self::block('dono/recurring-toggle', [
                'label'            => __('Make this a repeating gift', 'dono'),
                'defaultFrequency' => 'one-time',
                'frequencies'      => ['one-time', 'monthly'],
                'style'            => 'pills',
            ])
        );

        $details = self::block(
            'dono/step',
            ['title' => __('Your details', 'dono')],
            self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
            . self::block('dono/email', ['required' => true])
            . self::block('dono/country', ['required' => false])
        );

        $confirm = self::block(
            'dono/step',
            ['title' => __('Confirm', 'dono')],
            self::checkout(
                __('Donate {amount}', 'dono'),
                __('Cover the processing fee so the full amount reaches us', 'dono')
            )
        );

        return [
            'id'             => 'guided',
            'name'           => __('Guided donation', 'dono'),
            'description'    => __('The same fields as the everyday form, split across three steps. Fewer decisions per screen, which suits longer forms and small screens.', 'dono'),
            'icon'           => 'forms',
            'category'       => 'Wizard',
            'thumbnail_hint' => 'Three-step wizard with dot progress: amounts, then donor fields, then payment.',
            'settings'       => self::defaultSettings(),
            'blocks'         => self::block('dono/steps', ['progressStyle' => 'dots'], $amount . $details . $confirm),
        ];
    }

    private static function monthlySustainer(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Become a monthly supporter', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('A gift that arrives every month lets us plan further ahead than any single donation can.', 'dono')])
                // Preselected monthly, and no one-time option: a form that offers
                // both is the everyday one. Add one-time here if you want both.
                . self::block('dono/recurring-toggle', [
                    'label'            => __('How often', 'dono'),
                    'defaultFrequency' => 'monthly',
                    'frequencies'      => ['monthly', 'yearly'],
                    'style'            => 'pills',
                ])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets([10, 25, 50, 100], [
                        __('$10 a month', 'dono'),
                        __('$25 a month', 'dono'),
                        __('$50 a month', 'dono'),
                        __('$100 a month', 'dono'),
                    ], 1),
                    'allowCustom' => true,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::coverFees(__('Cover the processing fee on each payment', 'dono'))
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/donation-summary')
                // Next to the button, not on a screen already passed: this is the
                // last moment the donor can act on what they are agreeing to.
                . self::block('dono/paragraph', ['text' => __('Your first payment is taken today, then the same amount on this date each month. Change or stop it any time from your donor portal.', 'dono')])
                . self::block('dono/submit-button', ['label' => __('Start my monthly gift', 'dono')]);

        return [
            'id'             => 'monthly-sustainer',
            'name'           => __('Monthly sustainer', 'dono'),
            'description'    => __('For recruiting regular givers. Monthly is preselected, amounts are framed per month, and the commitment is restated next to the button.', 'dono'),
            'icon'           => 'update',
            'category'       => 'Recurring',
            'thumbnail_hint' => 'Monthly/yearly pills with monthly active, per-month amount tiles, commitment sentence above the button.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Thank you. Your first payment is on its way, and we will email you before anything changes.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    private static function emergencyAppeal(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Emergency appeal', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('Say what happened, who it affects, and what a donation pays for today. Keep it to a few sentences.', 'dono')])
                . self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('dono/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                // No fund picker: the appeal is the designation. Nothing optional
                // either, because every extra field costs gifts while it matters.
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::checkout(
                    __('Give now', 'dono'),
                    __('Cover the processing fee so the full amount reaches the response', 'dono')
                );

        return [
            'id'             => 'emergency-appeal',
            'name'           => __('Emergency appeal', 'dono'),
            'description'    => __('For a crisis with a deadline. Leads with the goal and countdown, asks only for what a receipt needs, and offers no fund choice because the appeal is the fund.', 'dono'),
            'icon'           => 'megaphone',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Bold headline over a goal bar with countdown, four amount tiles, two donor fields, one button.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => false, 'frequencies' => []],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Thank you. Your donation is already part of the response.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    private static function designated(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Choose where your donation goes', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('Pick the work you want to fund, or leave it to us to send it wherever it is needed most.', 'dono')])
                // Needs at least two real funds to be worth showing. The
                // greatest-need option is what a donor with no preference picks.
                . self::block('dono/fund-picker', [
                    'label'            => __('Fund', 'dono'),
                    'allowEmpty'       => true,
                    'emptyLabel'       => __('Wherever the need is greatest', 'dono'),
                    'emptyDescription' => __('We direct it to the most urgent work that month.', 'dono'),
                ])
                . self::block('dono/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/country', ['required' => false])
                . self::checkout(
                    __('Donate {amount}', 'dono'),
                    __('Cover the processing fee so the full amount reaches this fund', 'dono')
                );

        return [
            'id'             => 'designated',
            'name'           => __('Designated giving', 'dono'),
            'description'    => __('Lets the donor say which fund their money goes to. Set up your funds first, since a picker with one option is just a label.', 'dono'),
            'icon'           => 'portfolio',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Fund dropdown above the amount tiles, greatest-need option listed last.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

    private static function campaignPage(): array
    {
        $blocks = self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('dono/donation-amount', [
                    'presets'     => self::presets([25, 50, 100, 250], [], 1),
                    'allowCustom' => true,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                // Both of these assume a public supporter list. Remove them if
                // the page has none: a privacy promise about nothing reads badly.
                . self::block('dono/comment', [
                    'label'       => __('Add a message of support', 'dono'),
                    'placeholder' => __('Shown on the supporter wall', 'dono'),
                    'required'    => false,
                ])
                . self::block('dono/anonymous-toggle', [
                    'label'     => __('Hide my name from the supporter wall', 'dono'),
                    'defaultOn' => false,
                ])
                . self::checkout(
                    __('Donate {amount}', 'dono'),
                    __('Cover the processing fee so 100% reaches the campaign', 'dono')
                );

        return [
            'id'             => 'campaign-page',
            'name'           => __('Campaign page', 'dono'),
            'description'    => __('For a public campaign with a goal and a supporter wall. Carries a progress bar, a message field and a name-hiding toggle.', 'dono'),
            'icon'           => 'chart-area',
            'category'       => 'Campaign',
            'thumbnail_hint' => 'Goal bar on top, amount tiles, message box and anonymity checkbox above the button.',
            'settings'       => self::defaultSettings(),
            'blocks'         => $blocks,
        ];
    }

}
