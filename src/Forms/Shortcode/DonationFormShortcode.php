<?php

declare(strict_types=1);

namespace Gratora\Forms\Shortcode;

use Gratora\Campaigns\Campaign;
use Gratora\Campaigns\CampaignRepository;
use Gratora\Campaigns\Styling\CampaignStyleResolver;
use Gratora\Campaigns\Styling\Ink;
use Gratora\Campaigns\Styling\Tokens;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Donors\ConsentService;
use Gratora\Forms\Blocks\ColumnsBlock;
use Gratora\Forms\Blocks\ConsentBlock;
use Gratora\Forms\Blocks\CurrencySwitcherBlock;
use Gratora\Forms\Blocks\DateBlock;
use Gratora\Forms\Blocks\DividerBlock;
use Gratora\Forms\Blocks\DonationAmountBlock;
use Gratora\Forms\Blocks\DropdownBlock;
use Gratora\Forms\Blocks\FundPickerBlock;
use Gratora\Forms\Blocks\HtmlBlock;
use Gratora\Forms\Blocks\MultiSelectBlock;
use Gratora\Forms\Blocks\PaymentGatewaysBlock;
use Gratora\Forms\Blocks\RecurringToggleBlock;
use Gratora\Forms\Blocks\SectionBlock;
use Gratora\Forms\Blocks\TermsBlock;
use Gratora\Forms\Form;
use Gratora\Forms\FormRepository;
use Gratora\Forms\Rendering\FormDocument;
use Gratora\Forms\Rendering\FormMarkup;
use Gratora\Foundation\Helpers\Money;
use Gratora\Foundation\Hooks\HookProvider;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\BrowserAware;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Stripe\StripeApi;
use Gratora\Gateways\TestMode;
use Throwable;

/**
 * Emit shortcode blocks and the Preact runtime config.
 *
 * @since 1.0.0
 */
final class DonationFormShortcode extends HookProvider
{
    private const TAG            = 'gratora_donation_form';
    private const HANDLE         = 'gratora-donation-form-runtime';
    private const CLOAK_FAILSAFE = 'gratora-form-cloak-failsafe';
    private const PREVIEW_STYLE  = 'gratora-form-preview';
    private const PREVIEW_FLAG   = 'gratora-form-preview-flag';
    private const PREVIEW_RESIZE = 'gratora-form-preview-resize';

    private ?string $cssVersion = null;

    /** @since 1.0.0 */
    public function __construct(
        private FormRepository $forms,
        private ?CampaignStyleResolver $styles = null,
        private ?CampaignRepository $campaigns = null,
        private ?AntiSpamGuard $spam = null,
        private ?GatewayManager $gateways = null,
        private ?TestMode $testMode = null,
    ) {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        return [
            'wp_enqueue_scripts' => 'maybeEnqueue',
        ];
    }

    /**
     * parent::register() wires the hook map; without it maybeEnqueue never runs
     * and every render falls back to the late enqueue path.
     *
     * @since 1.0.0
     */
    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
        parent::register();
    }

    /** @since 1.0.0 */
    public function maybeEnqueue(): void
    {
        if (! is_singular()) return;
        global $post;
        if (! $post) return;

        if (has_shortcode((string) $post->post_content, self::TAG)) {
            $this->enqueue();
            return;
        }

        // A campaign block asks the gate when it renders, so the runtime waits
        // for that. The stylesheet carries the cloak and has to be in the head.
        if (has_block('gratora/donation-form', $post) || has_block('gratora/donate-button', $post)) {
            $this->registerRuntime();
            if (wp_style_is(self::HANDLE, 'registered')) {
                wp_enqueue_style(self::HANDLE);
            }
        }
    }

    /** @since 1.0.0 */
    private function enqueue(): void
    {
        FormGatewayAssets::enqueue();
        FormFieldAssets::enqueue();

        $this->registerRuntime();
        if (wp_script_is(self::HANDLE, 'registered')) {
            wp_enqueue_script(self::HANDLE);
        }
        if (wp_style_is(self::HANDLE, 'registered')) {
            wp_enqueue_style(self::HANDLE);
        }

        // runtime.css hides the form until the runtime marks it ready, and an
        // animation reveals it if the runtime never arrives. This covers a page
        // whose CSS switches animations off.
        if (! wp_script_is(self::CLOAK_FAILSAFE, 'registered')) {
            wp_register_script(self::CLOAK_FAILSAFE, false, [], GRATORA_VERSION, true);
            wp_add_inline_script(self::CLOAK_FAILSAFE, self::cloakFailsafeJs());
        }
        wp_enqueue_script(self::CLOAK_FAILSAFE);
    }

    /** @since 1.0.0 */
    private function registerRuntime(): void
    {
        $assetPath = GRATORA_DIR . 'build/donation-form/runtime/index.asset.php';
        if (! wp_script_is(self::HANDLE, 'registered') && file_exists($assetPath)) {
            $asset = require $assetPath;
            wp_register_script(
                self::HANDLE,
                GRATORA_URL . 'build/donation-form/runtime/index.js',
                array_merge($asset['dependencies'] ?? [], [FormGatewayAssets::HANDLE, FormFieldAssets::HANDLE]),
                $asset['version']      ?? GRATORA_VERSION,
                true
            );
        }

        if (! wp_style_is(self::HANDLE, 'registered') && file_exists(GRATORA_DIR . 'build/donation-form/runtime.css')) {
            wp_register_style(
                self::HANDLE,
                GRATORA_URL . 'build/donation-form/runtime.css',
                [],
                $this->cssVersion()
            );
            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
        }
    }

    /** @since 1.0.0 */
    private static function cloakFailsafeJs(): string
    {
        return <<<'JS'
setTimeout(function () {
    var forms = document.querySelectorAll('.gratora-donation-form:not([data-gratora-ready])');
    for (var i = 0; i < forms.length; i++) {
        forms[i].setAttribute('data-gratora-ready', '1');
    }
}, 4000);
JS;
    }

    /** @since 1.0.0 */
    public function render($atts = []): string
    {
        $atts = is_array($atts) ? $atts : [];
        $slug = trim((string) ($atts['slug'] ?? ''));
        if ($slug === '') {
            return $this->renderError(__('Specify a form slug: [gratora_donation_form slug="..."].', 'gratora-donation-platform'));
        }

        $form = $this->forms->findBySlug($slug);
        if (! $form) {
            return $this->renderError(sprintf(
                /* translators: %s: form slug */
                __('No donation form found for slug "%s".', 'gratora-donation-platform'),
                $slug
            ));
        }

        [$gate, $reason] = $this->standing($form);

        // renderError adds the reason for whoever can act on it, so a page that
        // has quietly lost its form does not depend on the admin thinking to
        // check the campaign screen. The equivalent block already explains itself.
        if ($gate !== 'render') {
            return match ($reason) {
                'form_unpublished' => $this->renderError(__('This form is not published, so it is hidden here.', 'gratora-donation-platform')),
                'campaign_missing' => $this->renderError(__('The campaign this form belongs to no longer exists, so the form is hidden.', 'gratora-donation-platform')),
                default            => $this->renderNotAccepting($reason),
            };
        }

        if (! wp_script_is(self::HANDLE, 'enqueued')) {
            $this->enqueue();
        }

        return $this->renderBlocks($form);
    }

    /**
     * What render() does with a form, decided without rendering it.
     *
     * @return 'render'|'closed'|'hidden'
     *
     * @since 1.0.0
     */
    public function gate(Form $form): string
    {
        return $this->standing($form)[0];
    }

    /**
     * Whether the current user is told why a form is hidden or closed.
     *
     * @since 1.0.0
     */
    public static function showsReasons(): bool
    {
        return current_user_can('manage_options') || current_user_can('manage_gratora');
    }

    /**
     * @return array{0: 'render'|'closed'|'hidden', 1: ?string}
     *
     * @since 1.0.0
     */
    private function standing(Form $form): array
    {
        // Rendering a form the submit gate will refuse is worse than rendering
        // nothing. The preview filter only takes effect for a user who can
        // edit, so the gate is never bypassed for a public visitor.
        if (current_user_can('edit_posts') && (bool) apply_filters('gratora.form.editor_preview', false, $form)) {
            return ['render', null];
        }

        if ($form->status !== 'published') {
            return ['hidden', 'form_unpublished'];
        }

        $campaign = $this->campaigns ? $this->campaigns->findById($form->campaign_id) : null;
        if (! $campaign) {
            return ['hidden', 'campaign_missing'];
        }

        $reason = $campaign->notAcceptingReason();
        if ($reason === null) {
            return ['render', null];
        }

        return [self::closedSentence($reason) !== null ? 'closed' : 'hidden', $reason];
    }

    /**
     * Markup only. Nothing here loads the runtime that hydrates it, so a caller
     * outside this class wants FormDocument, which carries the assets with it.
     *
     * @since 1.0.0
     */
    public function renderBlocks(Form $form): string
    {
        $formId = 'gratora-form-' . wp_unique_id();

        // SSR fund-picker pre-selects the campaign default, matching the walker.
        $campDefaultFund = 0;
        if ($form->campaign_id) {
            $fpCampaign = Campaign::query()->find('id', (int) $form->campaign_id);
            if ($fpCampaign && $fpCampaign->default_fund_id) {
                $campDefaultFund = (int) $fpCampaign->default_fund_id;
            }
        }
        FundPickerBlock::$renderCampaignDefaultFundId = $campDefaultFund;
        // The gateway block is rendered in here and has no form to resolve, so
        // it would answer for the site's mode while the config beside it
        // answers for the form's, and the two lists would disagree.
        PaymentGatewaysBlock::$renderTestMode = ($this->testMode ?? new TestMode($this->forms))->forForm($form);
        $inner = do_blocks((string) $form->blocks);
        PaymentGatewaysBlock::$renderTestMode = null;
        FundPickerBlock::$renderCampaignDefaultFundId = 0;

        $variant = apply_filters('gratora.form.variant', null, $form, $this->visitorContext());
        $gateway = $this->pickGateway($form);
        $config  = $this->buildConfig($form, $gateway, $variant);

        [$containerClass, $containerDecls] = $this->containerAttrs($form);

        $tokens     = is_array($config['theme']['tokens'] ?? null) ? $config['theme']['tokens'] : [];
        $styleDecls = $this->tokenStyle($tokens) . $containerDecls;

        return sprintf(
            '<form class="%s" id="%s" data-form-slug="%s" data-gateway="%s" data-layout="%s"%s%s novalidate><noscript><div class="gratora-donation-form__noscript">%s</div></noscript>%s%s</form>',
            esc_attr('gratora-donation-form gratora-donation-form--blocks' . $containerClass),
            esc_attr($formId),
            esc_attr($form->slug),
            esc_attr($gateway),
            esc_attr((string) ($config['layout'] ?? 'inline')),
            $variant ? ' data-variant="' . esc_attr($variant) . '"' : '',
            $styleDecls !== '' ? ' style="' . esc_attr($styleDecls) . '"' : '',
            // Only a no-JS visitor sees this: a dead form would GET their inputs
            // into the URL on submit.
            esc_html__('This donation form needs JavaScript enabled. Please turn it on and reload the page to donate.', 'gratora-donation-platform'),
            wp_kses($inner, FormMarkup::allowedHtml()),
            // JSON_HEX_TAG leaves no '<' in the payload, so no config string (a
            // thank-you message holding </script>) can close the element early.
            wp_get_inline_script_tag(
                (string) wp_json_encode($config, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE),
                ['type' => 'application/json', 'data-gratora-form-config' => true]
            )
        );
    }

    /**
     * @return array{0:string,1:string} [class-suffix, style-declarations]
     *
     * @since 1.0.0
     */
    private function containerAttrs(Form $form): array
    {
        $container = is_array($form->settings['container'] ?? null) ? $form->settings['container'] : [];

        // Plain by default: a form is nearly always dropped into a page that
        // already has its own card, and a second frame reads as a box in a box.
        $style = (string) ($container['style'] ?? 'plain');
        if (! in_array($style, ['frame', 'plain'], true)) {
            $style = 'plain';
        }

        $width = (int) ($container['width'] ?? 540);
        if ($width < 320 || $width > 1600) {
            $width = 0;
        }

        $classSuffix = $style === 'plain'
            ? ' gratora-donation-form--plain'
            : ' gratora-donation-form--framed';

        // Inline max-width (not just the CSS var) so host-theme selectors cannot out-specify it.
        $containerDecls = $width > 0
            ? '--gratora-form-max-width:' . $width . 'px;max-width:' . $width . 'px'
            : '';

        return [$classSuffix, $containerDecls];
    }

    /**
     * Inline custom properties, so the form is themed before the runtime mounts.
     *
     * @since 1.0.0
     */
    private function tokenStyle(array $tokens): string
    {
        $out = '';
        foreach ($tokens as $key => $value) {
            $k = strtolower((string) $key);
            if (! preg_match('/^[a-z0-9-]+$/', $k)) continue;
            $v = trim((string) $value);
            if ($v === '') continue;
            $v = str_replace([';', '"', '<', '>', '{', '}'], '', $v);
            $out .= '--' . $k . ':' . $v . ';';
        }

        // Derived, not authored: the accent and the soft ground are the
        // org's to choose, so what is drawn on them cannot assume a colour.
        $out .= Ink::declarationsFor((string) ($tokens['gratora-accent'] ?? ''));
        $out .= Ink::softDeclarations($tokens);
        $out .= Ink::fieldDeclarations($tokens);
        $out .= Ink::groundDeclarations($tokens);
        $out .= Ink::ringDeclarations($tokens);
        $out .= Ink::requiredDeclarations($tokens);

        // A pass-through token is unset so it inherits, which is right until the
        // form sits on a campaign page that has already declared it for its own
        // preset. Stating the fall-through keeps the form reading its own map.
        foreach (Tokens::inherited() as $key => $value) {
            if (! isset($tokens[$key])) {
                $out .= '--' . $key . ':' . $value . ';';
            }
        }

        // A colour left to the accent is unset on a page of its own, so it is
        // stated rather than taken from the campaign page the form sits on. The
        // rest of the catalogue the wrapper's stylesheet declares itself.
        foreach (Tokens::defaults() as $key => $default) {
            if ($default === '' && trim((string) ($tokens[$key] ?? '')) === '') {
                $out .= '--' . $key . ':initial;';
            }
        }

        return $out;
    }


    /**
     * @return array{html:string, cssUrl:string, jsUrl:string, jsDeps:list<string>}
     *
     * @since 1.0.0
     */
    public function renderPreview(string $blocks, ?array $settings = null, ?int $campaignId = null): array
    {
        $stub = Form::make();
        $stub->id          = 0;
        $stub->title       = '';
        $stub->slug        = 'preview-' . substr(md5($blocks), 0, 8);
        $stub->status      = 'draft';
        $stub->blocks      = $blocks;
        $stub->settings    = is_array($settings) ? $settings : null;
        // Preview can be invoked before a campaign is bound; the gate only
        // applies to public render(), so 0 is safe here.
        $stub->campaign_id = $campaignId !== null && $campaignId > 0 ? $campaignId : 0;
        $stub->created_at  = current_time('mysql');
        $stub->updated_at  = current_time('mysql');

        return (new FormDocument($this))->forForm($stub);
    }

    /**
     * A whole document for a frame with no queue of its own: the editor that
     * frames it never runs the runtime, and srcdoc cannot reach its scripts.
     *
     * @since 1.0.0
     */
    public function buildPreviewDocument(array $preview, bool $autoResize = false, bool $transparent = false): string
    {
        $this->registerRuntime();
        self::registerPreviewAssets();

        // Printed item by item: a queue marks what it prints as done, and a
        // second document built in the same request would then lose it.
        $head = self::printed(static function (): void {
            wp_styles()->do_item(self::HANDLE);
            wp_styles()->do_item(self::PREVIEW_STYLE);
            wp_scripts()->do_item(self::PREVIEW_FLAG);
        });

        $scripts   = FormDocument::withDependencies((array) $preview['jsDeps']);
        $scripts[] = self::HANDLE;
        if ($autoResize) {
            $scripts[] = self::PREVIEW_RESIZE;
        }
        $body = self::printed(static function () use ($scripts): void {
            foreach ($scripts as $handle) {
                wp_scripts()->do_item($handle);
            }
        });

        $classes = 'gratora-form-preview' . ($autoResize ? ' is-fit' : '') . ($transparent ? ' is-transparent' : '');

        return implode("\n", [
            '<!DOCTYPE html>',
            // The iframe is a document of its own, so it inherits nothing from
            // the admin around it: without these an Arabic author previews their
            // form left to right and in the wrong language.
            '<html class="' . esc_attr($classes) . '" lang="' . esc_attr(str_replace('_', '-', determine_locale())) . '"' . (is_rtl() ? ' dir="rtl"' : '') . '>',
            '<head>',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            $head,
            '</head>',
            '<body>',
            (string) $preview['html'],
            $body,
            '</body>',
            '</html>',
        ]);
    }

    /** @since 1.0.0 */
    private static function registerPreviewAssets(): void
    {
        if (! wp_style_is(self::PREVIEW_STYLE, 'registered')) {
            wp_register_style(self::PREVIEW_STYLE, false, [], GRATORA_VERSION);
            wp_add_inline_style(self::PREVIEW_STYLE, self::previewCss());
        }

        // The admin previews are sandboxed without allow-same-origin, so the
        // document has an opaque origin and the runtime's frame guard cannot
        // tell it from a hostile embed. Only a document this server built
        // prints the flag: a site framing the real form cannot script into it.
        // Never attached to the runtime handle, or a real form printed later in
        // the same request would carry it.
        if (! wp_script_is(self::PREVIEW_FLAG, 'registered')) {
            wp_register_script(self::PREVIEW_FLAG, false, [], GRATORA_VERSION);
            wp_add_inline_script(self::PREVIEW_FLAG, 'window.gratoraFormPreview = true;');
        }

        if (! wp_script_is(self::PREVIEW_RESIZE, 'registered')) {
            wp_register_script(self::PREVIEW_RESIZE, false, [], GRATORA_VERSION, true);
            wp_add_inline_script(self::PREVIEW_RESIZE, self::previewResizeJs());
        }
    }

    /** @since 1.0.0 */
    private static function previewCss(): string
    {
        // White like a real page: the preview frame plays a browser window, and
        // a grey page inside it reads as a second window over the editor stage.
        // A fitted frame must not stretch to the viewport: min-height plus
        // padding inflates the measured height, and fed back into the frame
        // height each observer tick it runs away.
        return <<<'CSS'
html.gratora-form-preview, html.gratora-form-preview body { margin: 0; padding: 0; background: #fff; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif; }
html.gratora-form-preview body { padding: 32px 16px; min-height: 100vh; }
html.gratora-form-preview.is-fit body { min-height: 0; }
html.gratora-form-preview.is-transparent, html.gratora-form-preview.is-transparent body { background: transparent; }
CSS;
    }

    /** @since 1.0.0 */
    private static function previewResizeJs(): string
    {
        return <<<'JS'
(function () {
    var last = 0;
    function fit() {
        try {
            if (!window.frameElement) return;
            var height = Math.min(document.documentElement.scrollHeight, 4000);
            if (Math.abs(height - last) > 2) {
                last = height;
                window.frameElement.style.height = height + 'px';
            }
        } catch (e) {}
    }
    addEventListener('load', fit);
    if (window.ResizeObserver) {
        new ResizeObserver(fit).observe(document.documentElement);
    }
    setTimeout(fit, 300);
    setTimeout(fit, 1200);
})();
JS;
    }

    /** @since 1.0.0 */
    private static function printed(callable $print): string
    {
        ob_start();
        try {
            $print();
        } finally {
            $printed = (string) ob_get_clean();
        }

        return $printed;
    }

    /** @since 1.0.0 */
    private function visitorContext(): array
    {
        $base = [
            'locale'  => determine_locale(),
            'country' => null,
            'user_id' => get_current_user_id() ?: null,
        ];
        return (array) apply_filters('gratora.form.visitor_context', $base);
    }

    /** @since 1.0.0 */
    private function pickGateway(Form $form): string
    {
        $allowed = is_array($form->settings['gateways']['allowed'] ?? null)
            ? $form->settings['gateways']['allowed']
            : [];

        if ($this->gateways !== null) {
            $opts = $this->gateways->optionsFor(
                $allowed,
                $this->visitorContext()['country'] ?? null,
                $this->detectCurrency($form),
                'one_time',
                ($this->testMode ?? new TestMode($this->forms))->forForm($form)
            );
            if ($opts !== []) {
                return $opts[0];
            }
        }

        // Nothing this form can take. The config still needs a named gateway to
        // be shaped, and the runtime refuses to submit against an empty option
        // list, so this names the one gateway that is always registered rather
        // than claiming a processor the org has not connected.
        return 'offline';
    }

    /** @since 1.0.0 */
    private function buildConfig(Form $form, string $gateway, ?string $variant = null): array
    {
        $visitor = $this->visitorContext();
        $built    = $this->buildSteps($form, $variant, $visitor);
        $steps    = (array) apply_filters('gratora.form.steps', $built['steps'], $form, $variant, $visitor);
        $pages    = $built['pages'];
        $pageNav  = $built['pageNav'];
        $preamble = $built['preamble'] ?? [];

        $layout = (string) ($form->settings['layout'] ?? 'inline');
        if (! in_array($layout, ['inline', 'modal'], true)) {
            $layout = 'inline';
        }

        $resolvedStyle = ['tokens' => [], 'accent' => '#211d3f', 'preset_id' => ''];
        if ($this->styles) {
            $campaign = ($this->campaigns && $form->campaign_id)
                ? $this->campaigns->findById($form->campaign_id)
                : null;
            $resolvedStyle = $this->styles->resolve($form, $campaign);
        }

        $thankYouMessage = trim((string) ($form->settings['thank_you_message'] ?? ''));
        if ($thankYouMessage === '') {
            $thankYouMessage = __('Thanks for your donation. A receipt is on the way to your inbox.', 'gratora-donation-platform');
        }
        $redirectUrl = trim((string) ($form->settings['redirect_url'] ?? ''));

        $currency = $this->detectCurrency($form);

        $currencyCfg = get_option('gratora_currency_locale', []);
        $fmtCfg      = is_array($currencyCfg['format'] ?? null) ? $currencyCfg['format'] : [];
        $numberFormat = [
            'decimalPlaces'  => (int) ($fmtCfg['decimal_places'] ?? 2),
            'decimalSep'     => (string) ($fmtCfg['decimal_sep']  ?? '.'),
            'thousandSep'    => (string) ($fmtCfg['thousand_sep'] ?? ','),
            'symbolPosition' => (string) ($fmtCfg['symbol_position'] ?? 'before'),
            'symbol'         => \Gratora\Foundation\Helpers\Money::symbolFor($currency),
        ];

        $privacy = get_option('gratora_privacy', []);
        $privacyUrl = is_array($privacy) ? trim((string) ($privacy['privacy_policy_url'] ?? '')) : '';

        $currencies = $this->detectCurrencies($form);
        $fxConfig   = $this->fxConfig($currency, $currencies);
        $swCfg      = $this->currencySwitcherConfig($form);

        // Rotate honeypot names per render and avoid browser-autofill field names.
        $honeypotPool = ['form_ref', 'aux_code', 'extra_note', 'alt_ref', 'note_two', 'field_ref', 'checksum'];
        $honeypotName = $honeypotPool[random_int(0, count($honeypotPool) - 1)];

        // Resolved before the picker rather than after it: a form can be in
        // test mode while the org switch is off, and a gateway holding only the
        // other mode's keys must not be offered.
        $testModeOn = ($this->testMode ?? new TestMode($this->forms))->forForm($form);

        $gatewaysCfg = null;
        if ($this->gateways !== null) {
            $allowedIds = is_array($form->settings['gateways']['allowed'] ?? null)
                ? $form->settings['gateways']['allowed']
                : [];
            $opts   = $this->gateways->optionsMetaFor($allowedIds, $testModeOn);
            $ctxIds = $this->gateways->optionsFor(
                $allowedIds,
                $this->visitorContext()['country'] ?? null,
                $currency,
                'one_time',
                $testModeOn
            );

            $blockAttrs   = $this->findPaymentGatewaysBlockAttrs($form) ?? [];
            $blockStyle   = (string) ($blockAttrs['style'] ?? 'cards');
            $descriptions = is_array($blockAttrs['descriptions'] ?? null) ? $blockAttrs['descriptions'] : [];
            if ($descriptions) {
                $opts = array_map(static function (array $o) use ($descriptions): array {
                    $id = (string) ($o['id'] ?? '');
                    if ($id !== '' && isset($descriptions[$id]) && $descriptions[$id] !== '') {
                        $o['description'] = (string) $descriptions[$id];
                    }
                    return $o;
                }, $opts);
            }

            // The author's choice only when the donor can actually use it in
            // this context; otherwise the first one they can.
            $preselected = (string) ($blockAttrs['preselected'] ?? '');
            $default     = in_array($preselected, $ctxIds, true)
                ? $preselected
                : ($ctxIds[0] ?? ($opts[0]['id'] ?? $gateway));

            $gatewaysCfg = [
                'options' => $opts,
                'default' => $default,
                'style'   => in_array($blockStyle, ['cards', 'list'], true) ? $blockStyle : 'cards',
            ];
        }

        $config = [
            'slug'        => $form->slug,
            'form_id'     => (int) $form->id,
            'campaign_id' => (int) $form->campaign_id,
            'variant'     => $variant,
            'gateway'     => $gateway,
            'gateways'    => $gatewaysCfg,
            'stripe'      => $this->stripePublicConfig($gatewaysCfg['options'] ?? [], $gateway, $testModeOn),
            'paypal'      => $this->payPalPublicConfig($gatewaysCfg['options'] ?? [], $gateway, $testModeOn, (string) $currency),
            ...$this->browserAwareConfig($testModeOn, (string) $currency),
            'testMode'    => $testModeOn,
            'currency'    => $currency,
            'currencies'  => $currencies,
            'fx'          => $fxConfig,
            'currencySwitcher' => $swCfg,
            'currencySwitcherPositioned' => $swCfg !== null,
            'numberFormat' => $numberFormat,
            'layout'      => $layout,
            'theme'      => [
                'tokens' => $resolvedStyle['tokens'],
                'accent' => (string) $resolvedStyle['accent'],
            ],
            'rest'        => esc_url_raw(rest_url('gratora/v1/donations')),
            // Anonymous donors send none, so a page-cached form never carries a
            // stale nonce the REST layer would 403. The create route is public;
            // spam and rate-limit gates protect it.
            'nonce'       => is_user_logged_in() ? wp_create_nonce('wp_rest') : '',
            'thanks'      => [
                'message'  => $thankYouMessage,
                'redirect' => $redirectUrl !== '' ? esc_url_raw($redirectUrl) : '',
            ],
            'privacyPolicyUrl' => $privacyUrl !== '' ? esc_url_raw($privacyUrl) : '',
            // Sending the link is what proves the address; the donation did not,
            // because the form takes an address on trust and a card need not
            // match it.
            'portal'      => [
                'url'      => ( new \Gratora\Donors\Portal\PortalPage() )->url(),
                // Published whole: the donations endpoint is a full URL, not a
                // base to append to.
                'sendLink' => esc_url_raw(rest_url('gratora/v1/portal/send-link')),
                'token'    => $this->spam ? $this->spam->mintPortalToken() : '',
            ],
            // HMAC token (tied to render timestamp) echoed back on submit.
            'spam'        => [
                // A preview stub has no row, so its id is 0, which is also the
                // scope the donations endpoint verifies when a submission names
                // no form. Minting there would hand out a token that unlocks the
                // form-less path, so a preview carries none and stays unsubmittable.
                'formToken'   => ($this->spam && (int) $form->id > 0) ? $this->spam->mintFormToken((int) $form->id) : '',
                'honeypotName' => $honeypotName,
                // Same filter as AntiSpamGuard, so the donor sees the minimum at
                // the amount field rather than only after submitting. A minimum
                // set on the amount block raises it for this form; the org-wide
                // floor still applies underneath, so take the larger.
                'minAmountCents' => max(
                    (int) apply_filters('gratora.spam.min_amount_cents', 100),
                    self::amountBlockMinCents($form)
                ),
            ],
            'steps'      => $steps,
            'pages'      => $pages,
            'pageNav'    => $pageNav,
            'preamble'   => $preamble,
            'i18n'     => [
                'chooseAmount'   => __('Choose an amount', 'gratora-donation-platform'),
                'customAmount'   => __('Custom amount', 'gratora-donation-platform'),
                'yourDetails'    => __('Your details', 'gratora-donation-platform'),
                'firstName'      => __('First name', 'gratora-donation-platform'),
                'lastName'       => __('Last name', 'gratora-donation-platform'),
                'email'          => __('Email', 'gratora-donation-platform'),
                'country'        => __('Country', 'gratora-donation-platform'),
                'reviewDonation' => __('Review your donation', 'gratora-donation-platform'),
                'amount'         => __('Amount', 'gratora-donation-platform'),
                'frequency'      => __('Donation frequency', 'gratora-donation-platform'),
                'fees'           => __('Processing fee', 'gratora-donation-platform'),
                'total'          => __('Total', 'gratora-donation-platform'),
                'manageGiving'   => __('Manage your giving', 'gratora-donation-platform'),
                'portalLinkSent' => __('Check your email', 'gratora-donation-platform'),
                'donor'          => __('Donor', 'gratora-donation-platform'),
                'paymentMethod'  => __('Payment method', 'gratora-donation-platform'),
                /* translators: %s: the selected currency code, e.g. INR. */
                'noGatewayForCurrency' => __('No payment method here accepts %s. Choose another currency to continue.', 'gratora-donation-platform'),
                'noGatewayForFrequency' => __('No payment method here can take a recurring donation. Choose a one-time donation to continue.', 'gratora-donation-platform'),
                // Not a currency problem: no allowed gateway is switched on.
                // Naming the currency sends donors hunting for a fix that is not
                // theirs to make.
                'noGatewayAvailable' => __('Online donations are unavailable right now. Please try again later.', 'gratora-donation-platform'),
                'testModeNotice' => __('Test mode is on. No real payment is taken and this donation is excluded from reporting.', 'gratora-donation-platform'),
                'back'           => __('Back', 'gratora-donation-platform'),
                'next'           => __('Continue', 'gratora-donation-platform'),
                'donateNow'      => __('Donate now', 'gratora-donation-platform'),
                'processing'     => __('Processing…', 'gratora-donation-platform'),
                'thanks'         => __('Thank you for your donation!', 'gratora-donation-platform'),
                'pendingTitle'   => __('Your donation is pending', 'gratora-donation-platform'),
                'pendingMessage' => __('Thank you. We have emailed you instructions to complete your payment.', 'gratora-donation-platform'),
                // The donor has finished and nothing is expected of them. The
                // pending copy would tell someone who has already paid that we
                // are still waiting on them.
                //
                // It says nothing about how the money is moving, because the
                // form does not know: this screen is reached by a bank debit
                // clearing, and by a card PayPal has held for review, and those
                // owe the donor different explanations. Naming a bank told a
                // card donor something untrue about their own payment.
                'processingTitle'   => __('Thank you, your donation is on its way', 'gratora-donation-platform'),
                'processingMessage' => __('Your payment is being processed. This can take a few working days, and we will email you once it completes.', 'gratora-donation-platform'),
                'donateAgain'    => __('Donate again', 'gratora-donation-platform'),
                'error'          => __('Sorry, something went wrong. Please try again.', 'gratora-donation-platform'),
                // A donor who cancelled at their bank, or whose bank refused
                // the debit, comes back to the same page as a donor whose
                // payment broke. Only this one can promise the money stayed
                // where it was, and the generic copy sends them to check a
                // statement with nothing on it.
                'notCompleted'   => __('Your payment was not completed, so nothing has been charged. Please try again when you are ready.', 'gratora-donation-platform'),
                // The other half of that pair: the browser could not find out
                // what happened, which is not the same as knowing nothing
                // happened. A donor whose bank has taken the money must not be
                // sent back to the form to pay a second time.
                'unresolvedTitle'  => __('We could not confirm your payment', 'gratora-donation-platform'),
                'returnUnresolved' => __('We could not check on your payment, and your bank may still have taken it. Please do not pay again yet. Check again in a moment, or contact us with your reference and we will look it up.', 'gratora-donation-platform'),
                'checkAgain'       => __('Check again', 'gratora-donation-platform'),
                'paymentTitle'   => __('Complete your donation', 'gratora-donation-platform'),
                'paymentLoading' => __('Loading secure payment…', 'gratora-donation-platform'),
                'payNow'         => __('Pay', 'gratora-donation-platform'),
                'confirming'     => __('Confirming your payment…', 'gratora-donation-platform'),
                'cancel'         => __('Cancel', 'gratora-donation-platform'),
                'comment'        => __('Add a message', 'gratora-donation-platform'),
                'notePublic'     => __('Show my message publicly on the supporter wall', 'gratora-donation-platform'),
                'anonymous'      => __('Make this donation anonymous', 'gratora-donation-platform'),
                'phone'          => __('Phone', 'gratora-donation-platform'),
                'addressLine1'   => __('Address line 1', 'gratora-donation-platform'),
                'addressLine2'   => __('Apartment, suite, etc.', 'gratora-donation-platform'),
                'addressCity'    => __('City', 'gratora-donation-platform'),
                'addressRegion'  => __('State / region', 'gratora-donation-platform'),
                'addressPostal'  => __('Postal code', 'gratora-donation-platform'),
                'addressCountry' => __('Country', 'gratora-donation-platform'),
                'noSpecificFund' => __('No specific fund', 'gratora-donation-platform'),
                'number'         => __('Number', 'gratora-donation-platform'),
                'impact'         => __('Provides', 'gratora-donation-platform'),
                'currency'       => __('Currency', 'gratora-donation-platform'),
                'coverFees'      => __('I\'d like to help cover the transaction fee', 'gratora-donation-platform'),
                'feesTotal'      => __('Total with fees:', 'gratora-donation-platform'),
                'formTitle'      => __('Donation form', 'gratora-donation-platform'),
                'close'          => __('Close', 'gratora-donation-platform'),
                'required'       => __('Required', 'gratora-donation-platform'),
                'freqOneTime'    => __('One-time', 'gratora-donation-platform'),
                'freqWeekly'     => __('Weekly', 'gratora-donation-platform'),
                'freqBiweekly'   => __('Every 2 weeks', 'gratora-donation-platform'),
                'freqMonthly'    => __('Monthly', 'gratora-donation-platform'),
                'freqQuarterly'  => __('Quarterly', 'gratora-donation-platform'),
                'freqYearly'     => __('Yearly', 'gratora-donation-platform'),
                'searchCountry'  => __('Search country…', 'gratora-donation-platform'),
                'framedTitle'    => __('This donation form is being shown inside another website.', 'gratora-donation-platform'),
                'framedAction'   => __('Open the donation page', 'gratora-donation-platform'),
                // The same source strings the server-rendered terms field
                // uses, so one translation covers both render paths.
                'agreeToTerms'   => __('I agree to the terms', 'gratora-donation-platform'),
                'readTerms'      => __('Read the terms', 'gratora-donation-platform'),
                'validation'     => [
                    'required'       => __('Required.', 'gratora-donation-platform'),
                    'termsRequired'  => __('Please agree to continue.', 'gratora-donation-platform'),
                    'pickAmount'     => __('Pick or enter an amount.', 'gratora-donation-platform'),
                    /* translators: %s: minimum donation amount formatted */
                    'minAmount'      => __('Minimum donation is %s.', 'gratora-donation-platform'),
                    'invalidEmail'   => __('Enter a valid email.', 'gratora-donation-platform'),
                    'enterName'      => __('Enter a name.', 'gratora-donation-platform'),
                    'invalidNumber'  => __('Enter a number.', 'gratora-donation-platform'),
                    /* translators: %s: minimum value */
                    'minNumber'      => __('Must be at least %s.', 'gratora-donation-platform'),
                    /* translators: %s: maximum value */
                    'maxNumber'      => __('Must be at most %s.', 'gratora-donation-platform'),
                    /* translators: %s: earliest allowed date */
                    'minDate'        => __('On or after %s.', 'gratora-donation-platform'),
                    /* translators: %s: latest allowed date */
                    'maxDate'        => __('On or before %s.', 'gratora-donation-platform'),
                    /* translators: %s: maximum length */
                    'tooLong'        => __('Too long (max %s).', 'gratora-donation-platform'),
                    'invalidFormat'  => __('Invalid format.', 'gratora-donation-platform'),
                    'pickAtLeastOne' => __('Pick at least one.', 'gratora-donation-platform'),
                    /* translators: %s: minimum number of selections */
                    'pickAtLeast'    => __('Pick at least %s.', 'gratora-donation-platform'),
                    /* translators: %s: maximum number of selections */
                    'pickNoMoreThan' => __('Pick no more than %s.', 'gratora-donation-platform'),
                ],
            ],
        ];

        return (array) apply_filters('gratora.form.config', $config, $form, $variant, $visitor);
    }

    /**
     * @return array{
     *   steps: list<array<string,mixed>>,
     *   pages: list<array{title:string}>,
     *   pageNav: array{prevLabel:string,nextLabel:string,progressStyle:string},
     *   preamble: list<array<string,mixed>>,
     * }
     *
     * @since 1.0.0
     */
    private function buildSteps(Form $form, ?string $variant = null, array $visitor = []): array
    {
        $blocks       = parse_blocks((string) $form->blocks);
        $steps        = [];
        // One ordered stream of fields and content, so the runtime renders them
        // interleaved in authored order rather than all content then all fields.
        $items        = [];
        // Root content before a gratora/steps wizard: rendered once above it.
        $preamble     = [];
        $rowSeq       = 0;
        // The gratora/step index a step sits in (0 = none).
        $currentPage  = 0;
        $stepDefs     = [];
        $pageNav      = [
            'prevLabel'     => '',
            'nextLabel'     => '',
            'progressStyle' => 'dots',
        ];

        // A content-only run is valid: an intro paragraph on its own wizard page.
        $flushItems = static function () use (&$steps, &$items, &$currentPage): void {
            if (empty($items)) return;
            $steps[] = ['type' => 'donor', 'page' => $currentPage, 'items' => array_values($items)];
            $items = [];
        };

        $tagRow = static function (array $field, ?array $row, array $attrs = []): array {
            $field['t'] = 'field';
            if ($row !== null) {
                $field['row'] = $row;
            }
            $cond = $attrs['condition'] ?? null;
            if (is_array($cond) && ! empty($cond['field'])) {
                $field['condition'] = [
                    'field' => (string) ($cond['field'] ?? ''),
                    'op'    => (string) ($cond['op']    ?? '='),
                    'value' => (string) ($cond['value'] ?? ''),
                ];
            }
            return $field;
        };

        $withCond = static function (array $deco, array $attrs): array {
            $deco['t'] = 'deco';
            $cond = $attrs['condition'] ?? null;
            if (is_array($cond) && ! empty($cond['field'])) {
                $deco['condition'] = [
                    'field' => (string) ($cond['field'] ?? ''),
                    'op'    => (string) ($cond['op']    ?? '='),
                    'value' => (string) ($cond['value'] ?? ''),
                ];
            }
            return $deco;
        };

        $walk = function (array $blockList, ?array $row = null) use (
            &$walk, &$steps, &$items, &$preamble, &$rowSeq,
            &$currentPage, &$stepDefs, &$pageNav,
            $flushItems, $tagRow, $withCond,
            $form, $variant, $visitor
        ): void {
        foreach ($blockList as $block) {
            $name  = (string) ($block['blockName'] ?? '');
            $attrs = (array) ($block['attrs'] ?? []);

            switch ($name) {
                case 'gratora/heading':
                    $level = (int) ($attrs['level'] ?? 2);
                    if ($level < 1 || $level > 6) $level = 2;
                    $items[] = $withCond([
                        'kind'  => 'heading',
                        'text'  => (string) ($attrs['text']  ?? ''),
                        'level' => $level,
                        'align' => (string) ($attrs['align'] ?? 'left'),
                    ], $attrs);
                    break;

                case 'gratora/paragraph':
                    $items[] = $withCond([
                        'kind'  => 'paragraph',
                        'html'  => wp_kses_post((string) ($attrs['text'] ?? '')),
                        'align' => (string) ($attrs['align'] ?? 'left'),
                    ], $attrs);
                    break;

                case 'gratora/divider':
                    $items[] = $withCond(
                        ['kind' => 'divider'] + DividerBlock::settings($attrs),
                        $attrs
                    );
                    break;

                case 'gratora/html':
                    $items[] = $withCond([
                        'kind' => 'html',
                        'html' => HtmlBlock::sanitize((string) ($attrs['content'] ?? '')),
                    ], $attrs);
                    break;

                case 'gratora/currency-switcher':
                    $sw = CurrencySwitcherBlock::settings($attrs);
                    $items[] = $withCond([
                        'kind'    => 'currency-switcher',
                        'variant' => $sw['style'],
                        'align'   => $sw['align'],
                        'label'   => $sw['label'],
                    ], $attrs);
                    break;

                case 'gratora/payment-gateways':
                    $items[] = $withCond(['kind' => 'payment-gateways'], $attrs);
                    break;

                case 'gratora/privacy-notice':
                    // Cast to object so empty attrs encode as {} not []; [] makes
                    // the block comment unparseable and the notice silently vanishes.
                    $privacyHtml = (string) do_blocks(
                        '<!-- wp:gratora/privacy-notice ' . wp_json_encode((object) $attrs) . ' /-->'
                    );
                    $items[] = $withCond(['kind' => 'html', 'html' => $privacyHtml], $attrs);
                    break;

                case 'gratora/hidden':
                    $items[] = $tagRow([
                        'kind'         => 'hidden',
                        'field'        => (string) ($attrs['field']        ?? ''),
                        'source'       => (string) ($attrs['source']       ?? 'fixed'),
                        'queryParam'   => (string) ($attrs['queryParam']   ?? ''),
                        'defaultValue' => (string) ($attrs['defaultValue'] ?? ''),
                    ], $row, $attrs);
                    break;

                case 'gratora/goal':
                    $goalAttrs = $attrs;
                    $goalAttrs['campaignId'] = $form->campaign_id;
                    $goalAttrs['formId']     = $form->id;
                    $html = (string) do_blocks(
                        '<!-- wp:gratora/goal ' . wp_json_encode($goalAttrs) . ' /-->'
                    );
                    $items[] = $withCond(['kind' => 'html', 'html' => $html], $attrs);
                    break;

                case 'gratora/row':
                    $columns = (int) ($attrs['columns'] ?? 2);
                    if ($columns < 1 || $columns > 4) $columns = 2;
                    $gap = (int) ($attrs['gap'] ?? 12);
                    if ($gap < 0 || $gap > 40) $gap = 12;
                    $gapUnit = (string) ($attrs['gapUnit'] ?? 'px');
                    if (! in_array($gapUnit, ['px', 'em', 'rem', '%'], true)) {
                        $gapUnit = 'px';
                    }
                    $rowSeq++;
                    $childRow = [
                        'id'      => $rowSeq,
                        'columns' => $columns,
                        'gap'     => $gap,
                        'gapUnit' => $gapUnit,
                    ];
                    $children = (array) ($block['innerBlocks'] ?? []);
                    $walk($children, $childRow);
                    break;

                case 'gratora/columns':
                    $columnsInlineStyle = ColumnsBlock::columnsStyle($attrs);
                    $outerItems         = $items;
                    $items              = [];
                    $children           = (array) ($block['innerBlocks'] ?? []);
                    $walk($children, $row);
                    // Columns are content-only: a stray field bubbles back out to
                    // the step, decorations nest.
                    $columnsChildren = [];
                    $bubbled         = [];
                    foreach ($items as $it) {
                        if (($it['t'] ?? '') === 'field') { $bubbled[] = $it; } else { $columnsChildren[] = $it; }
                    }
                    $items   = array_merge($outerItems, $bubbled);
                    $items[] = $withCond([
                        'kind'     => 'columns',
                        'classes'  => ['gratora-block', 'gratora-block--columns'],
                        'style'    => $columnsInlineStyle,
                        'children' => $columnsChildren,
                    ], $attrs);
                    break;

                case 'gratora/steps':
                    $progressStyle = (string) ($attrs['progressStyle'] ?? 'dots');
                    if (! in_array($progressStyle, ['dots', 'bar', 'none'], true)) {
                        $progressStyle = 'dots';
                    }
                    $pageNav = [
                        'prevLabel'     => (string) ($attrs['prevLabel'] ?? ''),
                        'nextLabel'     => (string) ($attrs['nextLabel'] ?? ''),
                        'progressStyle' => $progressStyle,
                    ];
                    // Root content authored before the wizard renders above it,
                    // instead of collapsing onto the first page.
                    if (! empty($items)) {
                        $preamble = array_merge($preamble, array_values($items));
                        $items    = [];
                    }
                    $children = (array) ($block['innerBlocks'] ?? []);
                    $walk($children, $row);
                    break;

                case 'gratora/step':
                    $flushItems();
                    $currentPage++;
                    $stepDefs[$currentPage] = [
                        'title'     => (string) ($attrs['title']     ?? ''),
                        'showTitle' => (bool)   ($attrs['showTitle'] ?? true),
                    ];
                    $children = (array) ($block['innerBlocks'] ?? []);
                    $walk($children, $row);
                    $flushItems();
                    // A step left empty in the builder would publish a blank
                    // wizard page; drop it so donors never click through one.
                    $pageHasContent = false;
                    foreach ($steps as $emitted) {
                        if (($emitted['page'] ?? null) === $currentPage) {
                            $pageHasContent = true;
                            break;
                        }
                    }
                    if (! $pageHasContent) {
                        unset($stepDefs[$currentPage]);
                        $currentPage--;
                    }
                    break;

                case 'gratora/section':
                    $sectionInlineStyle = SectionBlock::sectionStyle($attrs);
                    $outerItems       = $items;
                    $items            = [];
                    $children         = (array) ($block['innerBlocks'] ?? []);
                    $walk($children, $row);
                    $sectionChildren = [];
                    $bubbled         = [];
                    foreach ($items as $it) {
                        if (($it['t'] ?? '') === 'field') { $bubbled[] = $it; } else { $sectionChildren[] = $it; }
                    }
                    $items   = array_merge($outerItems, $bubbled);
                    $items[] = $withCond([
                        'kind'     => 'section',
                        'classes'  => ['gratora-block', 'gratora-block--section'],
                        'style'    => $sectionInlineStyle,
                        'children' => $sectionChildren,
                    ], $attrs);
                    break;

                case 'gratora/name':
                    // Required block: never conditional. Pass no attrs so a stale condition can't hide it.
                    $items[] = $tagRow([
                        'kind'             => 'name',
                        'firstLabel'       => (string) ($attrs['firstLabel'] ?? ''),
                        'lastLabel'        => (string) ($attrs['lastLabel'] ?? ''),
                        'firstPlaceholder' => (string) ($attrs['firstPlaceholder'] ?? ''),
                        'lastPlaceholder'  => (string) ($attrs['lastPlaceholder'] ?? ''),
                        'requireFirst'     => (bool) ($attrs['requireFirst'] ?? true),
                        'requireLast'      => (bool) ($attrs['requireLast']  ?? true),
                    ], $row, []);
                    break;

                case 'gratora/email':
                    // Required block: never conditional (see gratora/name).
                    $items[] = $tagRow([
                        'kind'        => 'email',
                        'label'       => (string) ($attrs['label'] ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'required'    => (bool) ($attrs['required'] ?? true),
                    ], $row, []);
                    break;

                case 'gratora/country':
                    $items[] = $tagRow([
                        'kind'        => 'country',
                        'label'       => (string) ($attrs['label'] ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'required'    => (bool) ($attrs['required'] ?? false),
                    ], $row, $attrs);
                    break;

                case 'gratora/phone':
                    $items[] = $tagRow([
                        'kind'        => 'phone',
                        'label'       => (string) ($attrs['label'] ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'required'    => (bool) ($attrs['required'] ?? false),
                    ], $row, $attrs);
                    break;

                case 'gratora/comment':
                    $items[] = $tagRow([
                        'kind'        => 'comment',
                        'label'       => (string) ($attrs['label']       ?? __('Add a message', 'gratora-donation-platform')),
                        'placeholder' => (string) ($attrs['placeholder'] ?? __('Anything you want to share?', 'gratora-donation-platform')),
                        'required'    => (bool)   ($attrs['required']    ?? false),
                    ], $row, $attrs);
                    break;

                case 'gratora/anonymous-toggle':
                    $privacyCfg     = get_option('gratora_privacy', []);
                    $globalDefault  = is_array($privacyCfg) && ! empty($privacyCfg['always_anonymous_default']);
                    $items[] = $tagRow([
                        'kind'      => 'anonymous',
                        'label'     => (string) ($attrs['label']     ?? __('Make this donation anonymous', 'gratora-donation-platform')),
                        'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false) || $globalDefault,
                    ], $row, $attrs);
                    break;

                case 'gratora/cover-fees':
                    $items[] = $tagRow([
                        'kind'      => 'cover_fees',
                        'label'     => (string) ($attrs['label']     ?? __('Cover the processing fee so 100% of my donation reaches you', 'gratora-donation-platform')),
                        'percent'   => (float)  ($attrs['percent']   ?? 2.9),
                        'fixed'     => (int)    ($attrs['fixed']     ?? 30),
                        'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
                    ], $row, $attrs);
                    break;

                case 'gratora/fund-picker':
                    $fpAllow      = (bool) ($attrs['allowEmpty'] ?? false);
                    $fpRepo       = new \Gratora\Funds\FundRepository();
                    $fpAllowedIds = array_values(array_filter(array_map('intval', (array) ($attrs['fundIds'] ?? []))));
                    $fpDescriptions = (bool) ($attrs['showDescriptions'] ?? true);
                    $fpOptions    = $fpRepo->pickerOptions(
                        $fpAllowedIds !== [] ? $fpAllowedIds : null,
                        true,
                        $fpDescriptions
                    );

                    $fpSelectable = array_values(array_map(
                        static fn ($o) => $o['id'],
                        array_filter($fpOptions, static fn ($o) => $o['selectable'])
                    ));

                    // Pre-select: '__none__' picks the no-fund tile, '' resolves
                    // campaign default then org default then first selectable, else a fund id.
                    $fpRequested = (string) ($attrs['defaultId'] ?? '');
                    if ($fpRequested === '__none__' && $fpAllow) {
                        $fpDefault = '';
                    } else {
                        $fpDefault = $fpRequested;
                        if (! in_array($fpDefault, $fpSelectable, true)) {
                            $fpDefault = '';
                            if ($form->campaign_id) {
                                $fpCamp = Campaign::query()->find('id', (int) $form->campaign_id);
                                if ($fpCamp && $fpCamp->default_fund_id
                                    && in_array((string) (int) $fpCamp->default_fund_id, $fpSelectable, true)) {
                                    $fpDefault = (string) (int) $fpCamp->default_fund_id;
                                }
                            }
                            if ($fpDefault === '') {
                                $fpOrg = $fpRepo->default();
                                if ($fpOrg && in_array((string) (int) $fpOrg->id, $fpSelectable, true)) {
                                    $fpDefault = (string) (int) $fpOrg->id;
                                }
                            }
                            if ($fpDefault === '' && ! $fpAllow && $fpSelectable !== []) {
                                $fpDefault = (string) $fpSelectable[0];
                            }
                        }
                    }

                    // No selectable funds and no explicit no-fund tile: emitting
                    // the block would show donors an empty picker. Drop it; the
                    // donation still resolves to the campaign/org default fund.
                    if ($fpSelectable === [] && ! $fpAllow) {
                        break;
                    }

                    $items[] = $tagRow([
                        'kind'              => 'fund',
                        'label'             => (string) ($attrs['label'] ?? ''),
                        'options'           => $fpOptions,
                        'default_id'        => $fpDefault,
                        'allow_empty'       => $fpAllow,
                        'empty_label'       => trim((string) ($attrs['emptyLabel'] ?? '')),
                        'empty_description' => $fpDescriptions ? trim((string) ($attrs['emptyDescription'] ?? '')) : '',
                    ], $row, $attrs);
                    break;

                case 'gratora/address':
                    $items[] = $tagRow([
                        'kind'           => 'address',
                        'label'          => (string) ($attrs['label']          ?? ''),
                        'showLine1'      => (bool)   ($attrs['showLine1']      ?? true),
                        'showLine2'      => (bool)   ($attrs['showLine2']      ?? true),
                        'showCity'       => (bool)   ($attrs['showCity']       ?? true),
                        'showRegion'     => (bool)   ($attrs['showRegion']     ?? true),
                        'showPostal'     => (bool)   ($attrs['showPostal']     ?? true),
                        'showCountry'    => (bool)   ($attrs['showCountry']    ?? true),
                        'requireLine1'   => (bool)   ($attrs['requireLine1']   ?? true),
                        'requireCity'    => (bool)   ($attrs['requireCity']    ?? true),
                        'requireRegion'  => (bool)   ($attrs['requireRegion']  ?? false),
                        'requirePostal'  => (bool)   ($attrs['requirePostal']  ?? true),
                        'requireCountry' => (bool)   ($attrs['requireCountry'] ?? true),
                        'line1Label'     => (string) ($attrs['line1Label']     ?? ''),
                        'line2Label'     => (string) ($attrs['line2Label']     ?? ''),
                        'cityLabel'      => (string) ($attrs['cityLabel']      ?? ''),
                        'regionLabel'    => (string) ($attrs['regionLabel']    ?? ''),
                        'postalLabel'    => (string) ($attrs['postalLabel']    ?? ''),
                        'countryLabel'   => (string) ($attrs['countryLabel']   ?? ''),
                    ], $row, $attrs);
                    break;

                case 'gratora/consent':
                    // Resolved from the org registry, same as the server render:
                    // the block names purposes, it does not define them, so a
                    // key the org deleted drops out rather than being invented.
                    $consentRegistry = Plugin::instance()->container->get(ConsentService::class);
                    $cPurposes = [];
                    foreach (ConsentBlock::purposeKeys($attrs) as $cKey) {
                        $cp = $consentRegistry->findPurpose($cKey);
                        if ($cp === null) continue;
                        $cPurposes[] = [
                            'id'          => (string) $cp['key'],
                            'label'       => (string) $cp['label'],
                            'description' => (string) $cp['description'],
                            'required'    => (bool) $cp['required'],
                            // A required purpose never starts granted: the
                            // consent row is evidence of something the donor
                            // did, and a box they could not move is not it.
                            'checked'     => ! (bool) $cp['required'] && (bool) $cp['default'],
                        ];
                    }
                    if ($cPurposes === []) break;
                    $items[] = $tagRow([
                        'kind'     => 'consent',
                        'label'    => (string) ($attrs['label']    ?? ''),
                        'helpText' => (string) ($attrs['helpText'] ?? ''),
                        'purposes' => $cPurposes,
                    ], $row, $attrs);
                    break;

                case 'gratora/donation-summary':
                    // A decoration, not a field: it reads state back rather than
                    // collecting anything, and only decorations reach
                    // renderDecorationItem.
                    $items[] = $withCond([
                        'kind'        => 'summary',
                        'showDonor'   => (bool) ($attrs['showDonor']   ?? true),
                        'showGateway' => (bool) ($attrs['showGateway'] ?? true),
                    ], $attrs);
                    break;

                case 'gratora/terms':
                    if (TermsBlock::isConfigured($attrs)) {
                        $items[] = $tagRow([
                            'kind'     => 'terms',
                            'purpose'  => TermsBlock::PURPOSE,
                            'label'    => (string) ($attrs['label']    ?? ''),
                            'terms'    => (string) ($attrs['terms']    ?? ''),
                            // esc_url_raw, not a bare cast: block attributes are not
                            // run through kses (sanitizeBlocks only reaches
                            // innerContent), so a javascript: URL saved here would
                            // travel to the donor's browser intact. The server-
                            // rendered fallback for this block has always escaped
                            // it; the hydrated path that replaces that fallback is
                            // what a donor actually clicks.
                            'linkUrl'  => esc_url_raw((string) ($attrs['linkUrl'] ?? '')),
                            'linkText' => (string) ($attrs['linkText'] ?? ''),
                        ], $row, $attrs);
                    }
                    break;

                case 'gratora/donation-amount':
                    // The amount UI is a fixed step, so preceding content leads
                    // it as its own step rather than nesting inside it.
                    $flushItems();
                    if ((string) ($attrs['donationType'] ?? 'multi') === 'fixed') {
                        $steps[] = [
                            'type'        => 'amount',
                            'page'        => $currentPage,
                            'presets'     => [],
                            'allowCustom' => true,
                        ];
                        break;
                    }
                    $raw = $attrs['presets'] ?? null;
                    if ((! is_array($raw) || empty($raw)) && $form->campaign_id) {
                        $campaign = Campaign::query()->find('id', $form->campaign_id);
                        if ($campaign && is_array($campaign->default_amount_presets) && ! empty($campaign->default_amount_presets)) {
                            $raw = $campaign->default_amount_presets;
                        }
                    }
                    $presets = DonationAmountBlock::normalizePresets($raw);
                    $presets = (array) apply_filters('gratora.form.amounts', $presets, $form, $variant, $visitor);
                    $steps[] = [
                        'type'        => 'amount',
                        'page'        => $currentPage,
                        'presets'     => array_values($presets),
                        'allowCustom' => (bool) ($attrs['allowCustom'] ?? true),
                    ];
                    break;

                case 'gratora/submit-button':
                    $flushItems();
                    $sbAlign = (string) ($attrs['align'] ?? 'left');
                    if (! in_array($sbAlign, ['left', 'center', 'right', 'full'], true)) {
                        $sbAlign = 'left';
                    }
                    $steps[] = [
                        'type'        => 'submit',
                        'page'        => $currentPage,
                        'label'       => (string) ($attrs['label'] ?? __('Donate now', 'gratora-donation-platform')),
                        'align'       => $sbAlign,
                    ];
                    break;

                case 'gratora/date':
                    $items[] = $tagRow([
                        'kind'     => 'date',
                        'label'    => (string) ($attrs['label']    ?? ''),
                        'helpText' => (string) ($attrs['helpText'] ?? ''),
                        'required' => (bool)   ($attrs['required'] ?? false),
                        'minDate'  => DateBlock::normalizeDate((string) ($attrs['minDate'] ?? '')),
                        'maxDate'  => DateBlock::normalizeDate((string) ($attrs['maxDate'] ?? '')),
                        'field'    => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                    ], $row, $attrs);
                    break;

                case 'gratora/text-input':
                    $items[] = $tagRow([
                        'kind'        => 'text',
                        'label'       => (string) ($attrs['label']       ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'helpText'    => (string) ($attrs['helpText']    ?? ''),
                        'required'    => (bool)   ($attrs['required']    ?? false),
                        'maxLength'   => max(0, (int) ($attrs['maxLength'] ?? 0)),
                        'pattern'     => (string) ($attrs['pattern']     ?? ''),
                        'field'       => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                    ], $row, $attrs);
                    break;

                case 'gratora/number-input':
                    $nMin  = $attrs['min']  ?? null;
                    $nMax  = $attrs['max']  ?? null;
                    $nStep = $attrs['step'] ?? 1;
                    $items[] = $tagRow([
                        'kind'        => 'number',
                        'label'       => (string) ($attrs['label']       ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'helpText'    => (string) ($attrs['helpText']    ?? ''),
                        'required'    => (bool)   ($attrs['required']    ?? false),
                        'min'         => is_numeric($nMin) ? (float) $nMin : null,
                        'max'         => is_numeric($nMax) ? (float) $nMax : null,
                        'step'        => is_numeric($nStep) ? (float) $nStep : 1.0,
                        'field'       => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                    ], $row, $attrs);
                    break;

                case 'gratora/recurring-toggle':
                    $rFreqs = RecurringToggleBlock::normalizeFrequencies($attrs['frequencies'] ?? RecurringToggleBlock::DEFAULT_FREQUENCIES);
                    if (! in_array('one-time', $rFreqs, true) && ! empty($rFreqs)) {
                        array_unshift($rFreqs, 'one-time');
                    }
                    if (count($rFreqs) < 2) {
                        break;
                    }
                    $rDefault = (string) ($attrs['defaultFrequency'] ?? 'one-time');
                    if (! in_array($rDefault, $rFreqs, true)) {
                        $rDefault = $rFreqs[0];
                    }
                    $rStyle = (string) ($attrs['style'] ?? 'pills');
                    if (! in_array($rStyle, ['pills', 'tabs'], true)) $rStyle = 'pills';
                    $items[] = $tagRow([
                        'kind'        => 'frequency',
                        'label'       => (string) ($attrs['label']    ?? ''),
                        'helpText'    => (string) ($attrs['helpText'] ?? ''),
                        'style'       => $rStyle,
                        'frequencies' => array_values($rFreqs),
                        'default'     => $rDefault,
                    ], $row, $attrs);
                    break;

                case 'gratora/dropdown':
                    $dOptions = DropdownBlock::normalizeOptions($attrs['options'] ?? null);
                    $dDefault = '';
                    foreach ($dOptions as $opt) {
                        if (! empty($opt['isDefault'])) { $dDefault = $opt['value']; break; }
                    }
                    $items[] = $tagRow([
                        'kind'        => 'dropdown',
                        'label'       => (string) ($attrs['label']       ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'options'     => $dOptions,
                        'required'    => (bool)   ($attrs['required']    ?? false),
                        'field'       => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                        'default'     => $dDefault,
                    ], $row, $attrs);
                    break;

                case 'gratora/radio':
                    $rOptions = DropdownBlock::normalizeOptions($attrs['options'] ?? null);
                    $rrDefault = '';
                    foreach ($rOptions as $opt) {
                        if (! empty($opt['isDefault'])) { $rrDefault = $opt['value']; break; }
                    }
                    $rLayout = (string) ($attrs['layout'] ?? 'vertical');
                    if (! in_array($rLayout, ['vertical', 'horizontal'], true)) $rLayout = 'vertical';
                    $items[] = $tagRow([
                        'kind'     => 'radio',
                        'label'    => (string) ($attrs['label'] ?? ''),
                        'options'  => $rOptions,
                        'required' => (bool)   ($attrs['required'] ?? false),
                        'field'    => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                        'layout'   => $rLayout,
                        'default'  => $rrDefault,
                    ], $row, $attrs);
                    break;

                case 'gratora/checkbox':
                    $items[] = $tagRow([
                        'kind'      => 'checkbox',
                        'label'     => (string) ($attrs['label']    ?? ''),
                        'helpText'  => (string) ($attrs['helpText'] ?? ''),
                        'required'  => (bool)   ($attrs['required'] ?? false),
                        'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
                        'field'     => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                    ], $row, $attrs);
                    break;

                case 'gratora/multi-select':
                    $msOptions = DropdownBlock::normalizeOptions($attrs['options'] ?? null);
                    $msDefaults = [];
                    foreach ($msOptions as $opt) {
                        if (! empty($opt['isDefault'])) $msDefaults[] = $opt['value'];
                    }
                    $items[] = $tagRow([
                        'kind'          => 'multi-select',
                        'label'         => (string) ($attrs['label']    ?? ''),
                        'options'       => $msOptions,
                        'required'      => (bool)   ($attrs['required'] ?? false),
                        'field'         => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                        'minSelections' => MultiSelectBlock::limits($attrs, count($msOptions))[0],
                        'maxSelections' => MultiSelectBlock::limits($attrs, count($msOptions))[1],
                        'defaults'      => $msDefaults,
                    ], $row, $attrs);
                    break;

                default:
                    // A field block shipped outside core answers with the runtime
                    // item its component renders from; a block nobody claims stays
                    // out of the config rather than reaching the donor half-built.
                    $extra = apply_filters('gratora.form.block_field', null, $name, $attrs, $form);
                    if (is_array($extra) && isset($extra['kind'])) {
                        $items[] = $tagRow($extra, $row, $attrs);
                    }
                    break;
            }
        }
        };

        $walk($blocks);
        $flushItems();

        $hasSubmit = false;
        foreach ($steps as $s) {
            if ($s['type'] === 'submit') { $hasSubmit = true; break; }
        }
        if (! $hasSubmit) {
            $steps[] = ['type' => 'submit', 'page' => $currentPage, 'label' => __('Donate now', 'gratora-donation-platform')];
        }

        // Walker pages are 1-indexed; runtime wants dense 0-indexed.
        $pages = [];
        if (! empty($stepDefs)) {
            ksort($stepDefs);
            $pages = array_values($stepDefs);
            foreach ($steps as &$s) {
                if (isset($s['page']) && $s['page'] > 0) {
                    $s['page'] = $s['page'] - 1;
                }
            }
            unset($s);
        } else {
            foreach ($steps as &$s) {
                $s['page'] = 0;
            }
            unset($s);
        }

        return [
            'steps'    => $steps,
            'pages'    => $pages,
            'pageNav'  => $pageNav,
            'preamble' => $preamble,
        ];
    }

    /**
     * Falls back to the org currency, never a hardcoded USD.
     *
     * @since 1.0.0
     */
    private function detectCurrency(Form $form): string
    {
        $found = null;
        $scan = function (array $list) use (&$scan, &$found): void {
            foreach ($list as $b) {
                if ($found !== null) return;
                if (($b['blockName'] ?? '') === 'gratora/donation-amount') {
                    $c = strtoupper((string) ($b['attrs']['currency'] ?? ''));
                    if ($c !== '') { $found = $c; return; }
                }
                $kids = (array) ($b['innerBlocks'] ?? []);
                if ($kids) $scan($kids);
            }
        };
        $scan(parse_blocks((string) $form->blocks));

        return $found ?? Money::defaultCurrency();
    }

    /** @since 1.0.0 */
    private function detectCurrencies(Form $form): array
    {
        $codes  = [];
        $blocks = parse_blocks((string) $form->blocks);
        $scan = function (array $list) use (&$scan, &$codes): void {
            foreach ($list as $b) {
                if (($b['blockName'] ?? '') === 'gratora/currency-switcher') {
                    foreach (CurrencySwitcherBlock::resolve($b['attrs']['currencies'] ?? []) as $c) {
                        if (! in_array($c, $codes, true)) $codes[] = $c;
                    }
                }
                if (! empty($b['innerBlocks'])) $scan((array) $b['innerBlocks']);
            }
        };
        $scan($blocks);
        return $codes;
    }

    /** @since 1.0.0 */
    private function currencySwitcherConfig(Form $form): ?array
    {
        $found  = null;
        $blocks = parse_blocks((string) $form->blocks);
        $scan = function (array $list) use (&$scan, &$found): void {
            foreach ($list as $b) {
                if ($found !== null) return;
                if (($b['blockName'] ?? '') === 'gratora/currency-switcher') {
                    $found = CurrencySwitcherBlock::settings(
                        (array) ($b['attrs'] ?? [])
                    );
                    return;
                }
                if (! empty($b['innerBlocks'])) $scan((array) $b['innerBlocks']);
            }
        };
        $scan($blocks);
        return $found;
    }

    /** @since 1.0.0 */
    private function hasPaymentGatewaysBlock(Form $form): bool
    {
        return $this->findPaymentGatewaysBlockAttrs($form) !== null;
    }

    /** @since 1.0.0 */
    private function findPaymentGatewaysBlockAttrs(Form $form): ?array
    {
        $found  = null;
        $blocks = parse_blocks((string) $form->blocks);
        $scan = function (array $list) use (&$scan, &$found): void {
            foreach ($list as $b) {
                if ($found !== null) return;
                if (($b['blockName'] ?? '') === 'gratora/payment-gateways') {
                    $found = is_array($b['attrs'] ?? null) ? $b['attrs'] : [];
                    return;
                }
                if (! empty($b['innerBlocks'])) $scan((array) $b['innerBlocks']);
            }
        };
        $scan($blocks);
        return $found;
    }

    /**
     * rates[CCY] = units of CCY per 1 base; a missing rate is omitted so the
     * runtime does not guess.
     *
     * @since 1.0.0
     */
    private function fxConfig(string $formCurrency, array $switcherCurrencies): array
    {
        $fx   = new \Gratora\Currency\FxRates();
        $base = $fx->base() ?: strtoupper($formCurrency);

        $codes = array_values(array_unique(array_merge(
            [$base, strtoupper($formCurrency)],
            array_map('strtoupper', $switcherCurrencies)
        )));

        $rates = [];
        foreach ($codes as $code) {
            $r = $code === $base ? 1.0 : $fx->effectiveRate($code);
            if ($r !== null && $r > 0) {
                $rates[$code] = $r;
            }
        }

        return ['base' => $base, 'rates' => $rates];
    }

    /**
     * A campaign that closed on its schedule or its goal is not a
     * misconfiguration, and a visitor who followed a link to it is owed a
     * sentence rather than an empty page. A draft or archived campaign still
     * says nothing publicly: there the form is missing because someone has not
     * finished, and that is for whoever can act on it.
     *
     * @since 1.0.0
     */
    private function renderNotAccepting(?string $reason): string
    {
        $public = self::closedSentence($reason);

        if ($public === null) {
            return $this->renderError(__('This campaign is not accepting donations, so the form is hidden. Publish the campaign to show it.', 'gratora-donation-platform'));
        }

        // The visitor's sentence explains the situation; it does not say what to
        // change. Whoever can act gets that as a second line in the same notice:
        // a closed campaign is not an error, and two stacked boxes read as one
        // thing having gone wrong twice.
        $for = '';
        if (self::showsReasons()) {
            $for = match ($reason) {
                'ended'     => __('The end date on this campaign has passed. Change the schedule to reopen it.', 'gratora-donation-platform'),
                'goal_met'  => __('This campaign is set to close when it meets its goal, and it has. Raise the target or turn that setting off to reopen it.', 'gratora-donation-platform'),
                'scheduled' => __('It opens on its start date. Only you can see this note.', 'gratora-donation-platform'),
                default     => '',
            };
        }

        return sprintf(
            '<div class="gratora-donation-form__closed" style="padding:16px 20px;border:1px solid #e5e0d8;border-radius:10px;background:#faf8f4;color:#3f3a33;font-size:15px;line-height:1.55;">%s%s</div>',
            esc_html($public),
            $for !== ''
                ? '<span class="gratora-donation-form__closed-note" style="display:block;margin-top:8px;font-size:13px;color:#6b6558;">' . esc_html($for) . '</span>'
                : ''
        );
    }

    /**
     * What a visitor reads when the campaign closed on its schedule or its
     * goal, or null when the reason is not one to tell the public.
     *
     * @since 1.0.0
     */
    private static function closedSentence(?string $reason): ?string
    {
        return match ($reason) {
            'ended'     => __('This campaign has finished accepting donations. Thank you to everyone who gave.', 'gratora-donation-platform'),
            'goal_met'  => __('This campaign has reached its goal. Thank you to everyone who gave.', 'gratora-donation-platform'),
            'scheduled' => __('This campaign is not open for donations yet. Please check back soon.', 'gratora-donation-platform'),
            default     => null,
        };
    }

    /** @since 1.0.0 */
    private function renderError(string $message): string
    {
        if (! self::showsReasons()) {
            return '';
        }
        return sprintf(
            '<div class="gratora-donation-form__error" style="padding:12px 16px;border:1px solid #c00;background:#fee;color:#900;font-size:13px;">%s</div>',
            esc_html($message)
        );
    }

    /** @since 1.0.0 */
    private function cssVersion(): string
    {
        if ($this->cssVersion === null) {
            $this->cssVersion = FormDocument::cssVersion();
        }
        return $this->cssVersion;
    }

    /**
     * The publishable key for the mode the intent will be created in.
     *
     * @since 1.0.0
     */
    private function stripePublicConfig(array $options, string $defaultGateway, bool $testMode): ?array
    {
        $ids   = array_column($options, 'id');
        $ids[] = $defaultGateway;
        if (! in_array('stripe', $ids, true)) {
            return null;
        }

        try {
            $key = Plugin::instance()->container->get(StripeApi::class)->publishableKeyFor($testMode);
        } catch (Throwable) {
            return null;
        }

        return $key !== '' ? ['publishableKey' => $key] : null;
    }

    /**
     * PayPal client IDs are public and mode-specific.
     *
     * @since 1.0.0
     */
    private function payPalPublicConfig(array $options, string $defaultGateway, bool $testMode, string $currency): ?array
    {
        $ids   = array_column($options, 'id');
        $ids[] = $defaultGateway;
        if (! in_array('paypal', $ids, true)) {
            return null;
        }

        try {
            $clientId = Plugin::instance()->container
                ->get(\Gratora\Gateways\PayPal\PayPalAccount::class)
                ->clientIdFor($testMode);
        } catch (Throwable) {
            return null;
        }

        return $clientId !== ''
            ? ['clientId' => $clientId, 'currency' => strtoupper($currency), 'intent' => 'capture']
            : null;
    }

    /**
     * How a gateway that ships in an add-on reaches the page. A throwing gateway
     * is skipped: a misconfigured payment method must not stop donations through
     * the others.
     *
     * @since 1.0.0
     */
    private function browserAwareConfig(bool $testMode, string $currency): array
    {
        if ($this->gateways === null) {
            return [];
        }

        $out = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            if (! $gateway instanceof BrowserAware) {
                continue;
            }
            try {
                $config = $gateway->publicConfig($testMode, $currency);
            } catch (Throwable) {
                continue;
            }
            if ($config !== []) {
                $out[$id] = $config;
            }
        }

        return $out;
    }

    /**
     * The minimum an admin set on this form's amount block, or 0 for none.
     *
     * A minimum bounds what a donor types, so a block that lists preset amounts
     * and nothing else is not carrying one: the presets are the whole menu, and
     * the editor shows no minimum to see or clear there either.
     *
     * @since 1.0.0
     */
    private static function amountBlockMinCents($form): int
    {
        if (! preg_match_all('/<!--\s+wp:gratora\/donation-amount\s+(\{.*?\})\s+\/?-->/s', (string) $form->blocks, $m)) {
            return 0;
        }

        $min = 0;
        foreach ($m[1] as $json) {
            $attrs = json_decode($json, true);
            if (! is_array($attrs)) {
                continue;
            }
            if (! DonationAmountBlock::acceptsTypedAmount($attrs)) {
                continue;
            }
            $min = max($min, (int) ($attrs['minCents'] ?? 0));
        }

        return $min;
    }
}
