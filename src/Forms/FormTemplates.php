<?php

declare(strict_types=1);

namespace Dono\Forms;

/**
 * Built-in donation form templates (block markup plus form settings).
 *
 * @version 1.0.0
 */
final class FormTemplates
{
    /**
     * Every built-in template that is registered. Several more are defined
     * below but deliberately left out of this list; re-enable one by appending
     * it, or add your own via the dono.form.templates filter.
     *
     * @return list<array{id:string,name:string,description:string,icon:string,category:string,thumbnail_hint:string,settings:array<string,mixed>,blocks:string}>
     */
    public static function all(): array
    {
        $templates = [
            self::blank(),            // Blank
            self::quickGive(),        // Starter
            self::conversionModal(), // Standard
            self::posterHero(),       // Standard
            self::impactTiers(),      // Standard
            self::sundayTithe(),      // Recurring
            self::essentialsWizard(), // Wizard
            self::workplaceMatch(),   // Wizard
            self::galaPledge(),       // Formal
        ];
        return (array) apply_filters('dono.form.templates', $templates);
    }

    /** Find a template by id. */
    public static function find(string $id): ?array
    {
        foreach (self::all() as $t) {
            if ($t['id'] === $id) return $t;
        }
        return null;
    }

    /** Serialize a block (with optional attrs and inner markup) to comment markup. */
    private static function block(string $name, array $attrs = [], string $inner = ''): string
    {
        $attrsJson = $attrs ? ' ' . wp_json_encode($attrs) : '';
        if ($inner === '') {
            return "<!-- wp:{$name}{$attrsJson} /-->\n";
        }
        return "<!-- wp:{$name}{$attrsJson} -->\n{$inner}<!-- /wp:{$name} -->\n";
    }

    /** Build amount presets; $preselectedIndex marks the default tile by index. */
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

    /** Baseline form settings shared by most templates. */
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

    /** Empty starter template. */
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

    /** Modal donation form template for high-intent traffic. */
    private static function conversionModal(): array
    {
        $row = self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
             . self::block('dono/country', ['required' => true]);
        $blocks = self::block('dono/heading', ['text' => __('Support our work', 'dono'), 'level' => 2])
                . self::block('dono/paragraph', ['text' => __('Recurring donations go further. Most donors choose monthly.', 'dono')])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets([25, 50, 100, 250, 500]),
                    'allowCustom' => true,
                ])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/row', ['columns' => 2, 'gap' => 12], $row)
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Cover transaction fees so 100% reaches us', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Donate {amount}', 'dono')]);

        return [
            'id'             => 'conversion-modal',
            'name'           => __('Conversion Modal', 'dono'),
            'description'    => __('Drop-in modal donation form for high-intent traffic. Frequency pill, five amount tiles, pre-checked fee cover.', 'dono'),
            'icon'           => 'external',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Overlay modal on a dimmed page, frequency pill, five amount tiles, single primary CTA.',
            'settings'       => [
                'layout'            => 'modal',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => false,
                'thank_you_message' => __('Your donation is on its way. Watch your inbox.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Casual, low-friction give template. */
    private static function quickGive(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Chip in', 'dono'), 'level' => 2])
                . self::block('dono/paragraph', ['text' => __('Every bit helps. Takes 20 seconds.', 'dono')])
                . self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => false])
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
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __("Sweet, I'll cover the fees so 100% goes to the cause", 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => false])
                . self::block('dono/phone', [
                    'label' => __('Get text receipts', 'dono'),
                    'placeholder' => __('Optional', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/comment', [
                    'label' => __('Say something nice', 'dono'),
                    'placeholder' => __('140 chars, keep it kind', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/anonymous-toggle', [
                    'label' => __('Show me as Anonymous', 'dono'),
                    'defaultOn' => false,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Send {amount}', 'dono')]);

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

    /** Image-led single-outcome template. */
    private static function posterHero(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('100% goes to the cause', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('Private donors cover our operations, so every dollar you give goes straight to the people we serve.', 'dono')])
                . self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => false])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [30, 60, 120, 240, 500],
                        [
                            __('$30 supports one person', 'dono'),
                            __('$60 supports two people', 'dono'),
                            __('$120 supports a family', 'dono'),
                            __('$240 supports a classroom', 'dono'),
                            __('$500 funds a community project', 'dono'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/country', ['required' => false])
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Add a little to cover processing so 100% reaches the cause', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Donate now', 'dono')]);

        return [
            'id'             => 'poster-hero',
            'name'           => __('Poster Hero', 'dono'),
            'description'    => __('One photograph, one outcome, one button. Built for organisations with strong imagery.', 'dono'),
            'icon'           => 'format-image',
            'category'       => 'Standard',
            'thumbnail_hint' => 'Oversized lowercase yellow headline on near-black, single tile row, full-width yellow CTA.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("Thank you. Your donation is already on its way to the people we serve. We'll send one email when it is put to work, and one more when the project is complete. That's it.", 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Signed-letter appeal template. */
    private static function personalAppeal(): array
    {
        $appeal = __("I'll keep this short. I'm Sara, and I've worked on this project for nine years. We don't run ads. We don't sell your address. We don't pay a fundraising firm a cut of what you give. What we do is answer the phone when a teacher writes asking for help, and we keep the lights on with small donations from people who read this far. If what we do has been useful to you this year, please consider giving what a cup of coffee costs. If it hasn't, that's okay too, thank you for reading. - Sara", 'dono');

        $blocks = self::block('dono/paragraph', ['text' => $appeal])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets([3, 5, 10, 20, 50]),
                    'allowCustom' => true,
                ])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/name', ['requireFirst' => false, 'requireLast' => false])
                . self::block('dono/comment', [
                    'label' => __('Why you gave (optional)', 'dono'),
                    'placeholder' => '',
                    'required' => false,
                ])
                . self::block('dono/anonymous-toggle', [
                    'label' => __('Keep my donation anonymous', 'dono'),
                    'defaultOn' => false,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Continue', 'dono')]);

        return [
            'id'             => 'personal-appeal',
            'name'           => __('Personal Appeal', 'dono'),
            'description'    => __('A signed letter on warm paper. Narrow column, serif body, anti-marketing tone.', 'dono'),
            'icon'           => 'edit',
            'category'       => 'Story-led',
            'thumbnail_hint' => 'Beige column, serif paragraph signed by name, plain number tiles, link-style CTA.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("Thank you. I read every comment that comes in, and I'm grateful you took a minute on a day when you didn't have to. Your receipt is in your inbox. - Sara", 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Tiered campaign template mapping amounts to named outcomes. */
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

    /** Catalog-style template where each amount is a named kit. */
    private static function concreteKit(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Choose what you want to send', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('Every kit is a real bundle delivered to someone who needs it. Pick one, or give any amount toward whatever is most urgent this month.', 'dono')])
                . self::block('dono/currency-switcher', [
                    'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
                    'label' => __('Currency', 'dono'),
                ])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [19.99, 42.50, 65.00, 110.00],
                        [
                            __('Starter Kit - supports one person', 'dono'),
                            __('Essentials Kit - supports one person', 'dono'),
                            __('Monthly Kit - supports one person for a month', 'dono'),
                            __('Family Kit - supports one family', 'dono'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/phone', [
                    'label' => __('Phone (optional)', 'dono'),
                    'placeholder' => __('For shipping updates only', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/country', ['required' => true])
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Cover the processing fee so the full kit value reaches the cause', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Send the kit', 'dono')]);

        return [
            'id'             => 'concrete-kit',
            'name'           => __('Concrete Kit', 'dono'),
            'description'    => __('Catalog-style donation flow where each amount is a named kit being sent on the donor behalf.', 'dono'),
            'icon'           => 'cart',
            'category'       => 'Niche',
            'thumbnail_hint' => 'UNICEF-blue header over a four-tile product grid with kit thumbnails.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => false, 'frequencies' => []],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __("Your kit is on its way. We'll email you when it ships, and you can download a printable donation card to share with someone special.", 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Mobile-first recurring giving template for churches. */
    private static function sundayTithe(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Give to our church', 'dono'), 'level' => 1])
                . self::block('dono/paragraph', ['text' => __('Your generosity sustains our ministry, our missions, and our neighborhood.', 'dono')])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [25, 50, 100, 250],
                        [
                            __('General Fund', 'dono'),
                            __('Missions', 'dono'),
                            __('Building', 'dono'),
                            __('Benevolence', 'dono'),
                        ]
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Cover the processing fee so the full amount supports our ministry', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/email', [
                    'placeholder' => __('For your tax receipt', 'dono'),
                    'required' => true,
                ])
                . self::block('dono/name', [
                    'firstPlaceholder' => 'First',
                    'lastPlaceholder' => 'Last',
                    'requireFirst' => true,
                    'requireLast' => true,
                ])
                . self::block('dono/phone', [
                    'label' => __('Mobile', 'dono'),
                    'placeholder' => __('For text giving receipts', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/comment', [
                    'label' => __('Add a prayer request or note', 'dono'),
                    'placeholder' => __('Our pastoral team reads every one.', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Give {amount} {frequency}', 'dono')]);

        return [
            'id'             => 'sunday-tithe',
            'name'           => __('Sunday Tithe', 'dono'),
            'description'    => __('Mobile-first recurring giving card for churches. Designed for repeat tithers finishing in under thirty seconds.', 'dono'),
            'icon'           => 'sos',
            'category'       => 'Recurring',
            'thumbnail_hint' => 'Soft teal card, frequency pills with Monthly selected, four amount tiles, large rounded CTA.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['weekly', 'biweekly', 'monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => false,
                'thank_you_message' => __('Thank you for your generosity. A receipt is on its way.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Time-bound campaign template with goal bar and countdown. */
    private static function campaignThermometer(): array
    {
        $row = self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
             . self::block('dono/email', ['required' => true]);
        $blocks = self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => true])
                . self::block('dono/heading', ['text' => __('Help us reach our goal by Tuesday', 'dono'), 'level' => 2])
                . self::block('dono/paragraph', ['text' => __('We are almost there, with 72 hours on the clock. Your donation right now is what closes the gap.', 'dono')])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [25, 50, 100, 250, 500],
                        [
                            __('$25 - gets us started', 'dono'),
                            __('$50 - builds momentum', 'dono'),
                            __('$100 - makes a real dent', 'dono'),
                            __('$250 - a major push', 'dono'),
                            __('$500 - helps close the gap', 'dono'),
                        ],
                        2 // Preselect $100 ("40 meals") as the default amount.
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/currency-switcher', [
                    'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD'],
                    'label' => __('Currency', 'dono'),
                ])
                . self::block('dono/row', ['columns' => 2, 'gap' => 12], $row)
                . self::block('dono/anonymous-toggle', [
                    'label' => __('Show my name on the supporter wall', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/comment', [
                    'label' => __('Leave a message of support', 'dono'),
                    'placeholder' => __('Cheering you on...', 'dono'),
                    'required' => false,
                ])
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Cover the processing fee so 100% reaches the kitchen', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Give {amount} now', 'dono')]);

        return [
            'id'             => 'campaign-thermometer',
            'name'           => __('Campaign Thermometer', 'dono'),
            'description'    => __('Time-bound campaign form with goal bar, countdown, and supporter wall for urgency-driven pushes.', 'dono'),
            'icon'           => 'chart-bar',
            'category'       => 'Event',
            'thumbnail_hint' => 'Coral progress bar at 67%, countdown clock "3 days left", supporter avatars trailing below.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('You did it. You just helped us hit 67% of our goal. Share to push us to 100%.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Multi-step institutional donation template with project portfolios. */
    private static function stewardshipWizard(): array
    {
        $blocks = self::block('dono/heading', ['text' => __('Make your donation', 'dono'), 'level' => 2])
                . self::block('dono/paragraph', ['text' => __('Your support funds the programs below. A member of our development team will follow up personally to confirm how your donation is being directed.', 'dono')])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets([50, 100, 250, 500, 1000]),
                    'allowCustom' => true,
                ])
                . self::block('dono/currency-switcher', [
                    'currencies' => ['USD', 'EUR', 'GBP', 'CAD', 'AUD', 'CHF'],
                    'label' => __('Currency', 'dono'),
                ])
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [0, 0, 0, 0],
                        [
                            __('Education - educates one student for a term', 'dono'),
                            __('Health - provides medical care for one family', 'dono'),
                            __('Food Security - supports a community for a week', 'dono'),
                            __('Where the need is greatest - let our team allocate', 'dono'),
                        ]
                    ),
                    'allowCustom' => false,
                ])
                . self::block('dono/name', ['requireFirst' => true, 'requireLast' => true])
                . self::block('dono/email', ['required' => true])
                . self::block('dono/country', ['required' => false])
                . self::block('dono/phone', [
                    'label' => __('Phone (optional)', 'dono'),
                    'placeholder' => '',
                    'required' => false,
                ])
                . self::block('dono/comment', [
                    'label' => __('Mailing address (street, city, region, postal)', 'dono'),
                    'placeholder' => __('123 Main Street', 'dono'),
                    'required' => true,
                ])
                . self::block('dono/anonymous-toggle', [
                    'label' => __('Withhold my name from public recognition lists', 'dono'),
                    'defaultOn' => false,
                ])
                . self::block('dono/comment', [
                    'label' => __('Message to our team', 'dono'),
                    'placeholder' => __("Anything you'd like our development team to know...", 'dono'),
                    'required' => false,
                ])
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Add 3% to cover processing costs', 'dono'),
                    'defaultOn' => false,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Complete my donation', 'dono')]);

        return [
            'id'             => 'stewardship-wizard',
            'name'           => __('Stewardship Wizard', 'dono'),
            'description'    => __('Three-step institutional donation form for mid-to-large nonprofits with project portfolios and stewardship workflows.', 'dono'),
            'icon'           => 'businessman',
            'category'       => 'Enterprise',
            'thumbnail_hint' => 'Navy header with serif "Make your donation", three-dot stepper, restrained tile grid below.',
            'settings'       => [
                'layout'            => 'inline',
                'style'            => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('We have received your donation. A member of our development team will be in touch within five business days.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Two-step wizard template that steers donors toward monthly giving. */
    private static function sustainerPath(): array
    {
        $hero = self::block(
            'dono/section',
            [
                'background' => '#e7f5ec',
                'padding'    => ['top' => 32, 'right' => 32, 'bottom' => 32, 'left' => 32],
                'border'     => ['radius' => 12],
            ],
            self::block('dono/heading',   ['text' => __('Become a monthly sustainer', 'dono'), 'level' => 2])
            . self::block('dono/paragraph', ['text' => __('A small recurring donation goes further than a single big one. Pick what works for you.', 'dono')])
        );

        $detailsRow = self::block('dono/name',  ['requireFirst' => true, 'requireLast' => true])
                    . self::block('dono/email', ['required' => true]);

        $step1 = self::block(
            'dono/step',
            ['title' => __('Choose your impact', 'dono')],
            $hero
            . self::block('dono/recurring-toggle', ['defaultFrequency' => 'monthly', 'style' => 'pills', 'frequencies' => ['one-time', 'monthly']])
            . self::block('dono/donation-amount', [
                'presets'     => self::presets([10, 25, 50, 100], [], 1),
                'allowCustom' => true,
            ])
            . self::block('dono/paragraph', ['text' => __('Monthly donors fund 70% of our year-round programs.', 'dono')])
        );

        $step2 = self::block(
            'dono/step',
            ['title' => __('Your details', 'dono')],
            self::block('dono/row', ['columns' => 2, 'gap' => 12], $detailsRow)
            . self::block('dono/cover-fees', [
                'percent' => 2.9, 'fixed' => 30,
                'label' => __('Cover transaction fees so 100% reaches us', 'dono'),
                'defaultOn' => true,
            ])
            . self::block('dono/consent')
            . self::block('dono/payment-gateways', ['style' => 'cards'])
            . self::block('dono/submit-button', ['label' => __('Start monthly support', 'dono')])
        );

        return [
            'id'             => 'sustainer-path',
            'name'           => __('Sustainer Path', 'dono'),
            'description'    => __('Two-step wizard that coaches donors toward monthly giving. Highest-ROI pattern for recurring acquisition.', 'dono'),
            'icon'           => 'update',
            'category'       => 'Recurring',
            'thumbnail_hint' => 'Two-step wizard. Step 1: accent-soft hero with monthly default, four amount tiles ($25 preselected). Step 2: name + email row, fee cover, consent.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => 'classic'],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Welcome to our sustainer community. Your first donation is on its way and we\'ll send updates monthly.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => self::block(
                'dono/steps',
                ['progressStyle' => 'dots'],
                $step1 . $step2
            ),
        ];
    }

    /** Urgency-led modal template for disaster relief appeals. */
    private static function rapidResponse(): array
    {
        $hero = self::block(
            'dono/section',
            [
                'background' => '#e7f5ec',
                'padding'    => ['top' => 32, 'right' => 32, 'bottom' => 32, 'left' => 32],
                'border'     => ['radius' => 16],
            ],
            self::block('dono/heading',   ['text' => __('Help families in crisis today', 'dono'), 'level' => 2])
            . self::block('dono/paragraph', ['text' => __('An emergency is unfolding. Our teams are already on the ground. Every dollar gets there within 24 hours.', 'dono')])
        );

        $detailsRow = self::block('dono/name',  ['requireFirst' => true, 'requireLast' => true])
                    . self::block('dono/email', ['required' => true]);

        $blocks = $hero
                . self::block('dono/goal', ['showAmount' => true, 'showDonors' => true, 'showDeadline' => false])
                . self::block('dono/donation-amount', [
                    'presets'     => self::presets([50, 100, 250, 500]),
                    'allowCustom' => true,
                ])
                . self::block('dono/row', ['columns' => 2, 'gap' => 12], $detailsRow)
                . self::block('dono/cover-fees', [
                    'percent' => 2.9, 'fixed' => 30,
                    'label' => __('Cover the fees so 100% reaches the response', 'dono'),
                    'defaultOn' => true,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Send help now', 'dono')]);

        return [
            'id'             => 'rapid-response',
            'name'           => __('Rapid Response', 'dono'),
            'description'    => __('Modal popup for disaster relief and breaking-news appeals. Urgency-led, single page, no friction.', 'dono'),
            'icon'           => 'warning',
            'category'       => 'Urgent',
            'thumbnail_hint' => 'Modal overlay. Bold hero with relief headline, goal bar framed as "raised in first 72 hours", four amount tiles, "Send help now" CTA.',
            'settings'       => [
                'layout'            => 'modal',
                'style'             => ['preset_id' => 'bold'],
                'recurring'         => ['enabled' => false, 'frequencies' => []],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => true,
                'thank_you_message' => __('Help is on the way. We\'ll send an update in 48 hours.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Tiered pledge template for galas and paddle-raise events. */
    private static function galaPledge(): array
    {
        $header = self::block(
            'dono/section',
            [
                'border'  => ['width' => 1, 'style' => 'solid', 'color' => '#e5e7eb', 'radius' => 10],
                'padding' => ['top' => 32, 'right' => 32, 'bottom' => 32, 'left' => 32],
            ],
            self::block('dono/heading',   ['text' => __('The Annual Gala', 'dono'), 'level' => 2])
            . self::block('dono/paragraph', ['text' => __('Choose your pledge level below. Every level keeps our mission going for another year.', 'dono')])
        );

        $detailsRow = self::block('dono/name',  ['requireFirst' => true, 'requireLast' => true])
                    . self::block('dono/email', ['required' => true]);

        $blocks = $header
                . self::block('dono/donation-amount', [
                    'presets' => self::presets(
                        [500, 1000, 2500, 5000, 10000],
                        [
                            __('Friend',     'dono'),
                            __('Patron',     'dono'),
                            __('Benefactor', 'dono'),
                            __('Founder',    'dono'),
                            __('Visionary',  'dono'),
                        ],
                        2
                    ),
                    'allowCustom' => true,
                ])
                . self::block('dono/row', ['columns' => 2, 'gap' => 12], $detailsRow)
                . self::block('dono/phone', [
                    'label'    => __('Phone (for stewardship follow-up)', 'dono'),
                    'required' => true,
                ])
                . self::block('dono/comment', [
                    'label'       => __('A note for the honoree', 'dono'),
                    'placeholder' => __('Optional', 'dono'),
                    'required'    => false,
                ])
                . self::block('dono/payment-gateways', ['style' => 'cards'])
                . self::block('dono/submit-button', ['label' => __('Pledge tonight', 'dono')]);

        return [
            'id'             => 'gala-pledge',
            'name'           => __('Gala Pledge', 'dono'),
            'description'    => __('Tiered pledge form for galas and paddle-raise events. Named giving tiers, dedication option, stewardship phone capture.', 'dono'),
            'icon'           => 'awards',
            'category'       => 'Formal',
            'thumbnail_hint' => 'Quiet, formal. Bordered hero, five named tiers (Friend through Visionary), dedication panel, stewardship phone, comment to honoree.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => 'quiet'],
                'recurring'         => ['enabled' => false, 'frequencies' => []],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => false,
                'thank_you_message' => __('Your pledge is recorded. Look for your name on the wall before dessert.', 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => $blocks,
        ];
    }

    /** Multi-step template that captures employer-match details inline. */
    private static function workplaceMatch(): array
    {
        $step1 = self::block(
            'dono/step',
            ['title' => __('Your donation', 'dono')],
            self::block('dono/heading',   ['text' => __('Double your impact', 'dono'), 'level' => 2])
            . self::block('dono/paragraph', ['text' => __("Many employers match employee donations 1:1, sometimes 2:1. Tell us where you work and we'll handle the rest.", 'dono')])
            . self::block('dono/donation-amount', [
                'presets'     => self::presets([50, 100, 250, 500]),
                'allowCustom' => true,
            ])
            . self::block('dono/recurring-toggle', ['defaultFrequency' => 'one-time', 'style' => 'pills', 'frequencies' => ['one-time', 'weekly', 'biweekly', 'monthly']])
        );

        $employerHero = self::block(
            'dono/section',
            [
                'background' => '#f8fafb',
                'padding'    => ['top' => 20, 'right' => 20, 'bottom' => 20, 'left' => 20],
                'border'     => ['radius' => 8],
            ],
            self::block('dono/heading',   ['text' => __('Your employer', 'dono'), 'level' => 3])
            . self::block('dono/paragraph', ['text' => __("We'll check our database and pre-fill the match form.", 'dono')])
        );

        $step2 = self::block(
            'dono/step',
            ['title' => __('Employer match', 'dono')],
            $employerHero
            . self::block('dono/text-input', [
                'label'    => __('Employer name', 'dono'),
                'required' => true,
                'field'    => 'employer_name',
            ])
            . self::block('dono/dropdown', [
                'label'   => __('Your role', 'dono'),
                'field'   => 'employer_role',
                'options' => [
                    ['value' => 'employee', 'label' => __('Employee',           'dono'), 'isDefault' => true],
                    ['value' => 'retiree',  'label' => __('Retiree',            'dono'), 'isDefault' => false],
                    ['value' => 'spouse',   'label' => __('Spouse of employee', 'dono'), 'isDefault' => false],
                    ['value' => 'board',    'label' => __('Board member',       'dono'), 'isDefault' => false],
                ],
                'required' => false,
            ])
            . self::block('dono/text-input', [
                'label'    => __('Work email (for verification)', 'dono'),
                'field'    => 'work_email',
                'required' => false,
            ])
        );

        $detailsRow = self::block('dono/name',  ['requireFirst' => true, 'requireLast' => true])
                    . self::block('dono/email', ['required' => true]);

        $step3 = self::block(
            'dono/step',
            ['title' => __('Your details', 'dono')],
            self::block('dono/row', ['columns' => 2, 'gap' => 12], $detailsRow)
            . self::block('dono/address', ['requireCountry' => true, 'requirePostal' => true])
            . self::block('dono/cover-fees', [
                'percent' => 2.9, 'fixed' => 30,
                'label' => __('Cover the fees so 100% reaches us', 'dono'),
                'defaultOn' => true,
            ])
            . self::block('dono/consent')
            . self::block('dono/payment-gateways', ['style' => 'cards'])
            . self::block('dono/submit-button', ['label' => __('Donate and request match', 'dono')])
        );

        return [
            'id'             => 'workplace-match',
            'name'           => __('Workplace Match', 'dono'),
            'description'    => __('Three-step form that captures employer info inline. Unlocks employer matching at the donation moment instead of post-hoc.', 'dono'),
            'icon'           => 'building',
            'category'       => 'Wizard',
            'thumbnail_hint' => 'Three-step wizard. Step 1 donation selection. Step 2 employer capture in a soft section. Step 3 donor details with full address.',
            'settings'       => [
                'layout'            => 'inline',
                'style'             => ['preset_id' => ''],
                'recurring'         => ['enabled' => true, 'frequencies' => ['monthly', 'yearly']],
                'gateways'          => ['allowed' => []],
                'anonymous_allowed' => false,
                'thank_you_message' => __("Donation received. We've emailed you the next step to submit your match. It takes about 2 minutes.", 'dono'),
                'redirect_url'      => '',
            ],
            'blocks'         => self::block(
                'dono/steps',
                ['progressStyle' => 'dots'],
                $step1 . $step2 . $step3
            ),
        ];
    }

    /**
     * Three-step donation wizard with the essentials only: amount, donor
     * details, confirm. The lean "first multi-step form" starting point.
     */
    private static function essentialsWizard(): array
    {
        $amountStep = self::block(
            'dono/step',
            ['title' => __('Your donation', 'dono')],
            self::block('dono/heading',   ['text' => __('Make your donation', 'dono'), 'level' => 2])
            . self::block('dono/donation-amount', [
                'presets'     => self::presets([25, 50, 100, 250], [], 1),
                'allowCustom' => true,
            ])
        );

        $detailsStep = self::block(
            'dono/step',
            ['title' => __('Your details', 'dono')],
            self::block('dono/name',  ['requireFirst' => true, 'requireLast' => true])
            . self::block('dono/email', ['required' => true])
            . self::block('dono/country', ['required' => false])
        );

        $confirmStep = self::block(
            'dono/step',
            ['title' => __('Confirm', 'dono')],
            self::block('dono/paragraph', ['text' => __('One last look before we charge your card.', 'dono')])
            . self::block('dono/payment-gateways', ['style' => 'cards'])
            . self::block('dono/cover-fees', [
                'percent'   => 2.9,
                'fixed'     => 30,
                'label'     => __('Cover the processing fee so 100% reaches us', 'dono'),
                'defaultOn' => true,
            ])
            . self::block('dono/submit-button', ['label' => __('Donate {amount}', 'dono')])
        );

        return [
            'id'             => 'essentials-wizard',
            'name'           => __('Essentials wizard', 'dono'),
            'description'    => __('Three-step donation flow with the basics: amount, donor details, confirm + pay. The cleanest starting point for a multi-step form.', 'dono'),
            'icon'           => 'forms',
            'category'       => 'Wizard',
            'thumbnail_hint' => 'Three-step wizard, dots progress indicator, amount tiles on step 1, name/email/country on step 2, payment options + cover-fees on step 3.',
            'settings'       => self::defaultSettings(),
            'blocks'         => self::block(
                'dono/steps',
                ['progressStyle' => 'dots'],
                $amountStep . $detailsStep . $confirmStep
            ),
        ];
    }
}
