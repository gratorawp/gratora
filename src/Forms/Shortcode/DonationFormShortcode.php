<?php

declare(strict_types=1);

namespace Dono\Forms\Shortcode;

use Dono\Campaigns\Campaign;
use Dono\Campaigns\CampaignRepository;
use Dono\Campaigns\Styling\CampaignStyleResolver;
use Dono\Donations\AntiSpamGuard;
use Dono\Forms\Blocks\ColumnsBlock;
use Dono\Donors\ConsentService;
use Dono\Forms\Blocks\ConsentBlock;
use Dono\Forms\Blocks\TermsBlock;
use Dono\Forms\Blocks\CurrencySwitcherBlock;
use Dono\Forms\Blocks\DateBlock;
use Dono\Forms\Blocks\DividerBlock;
use Dono\Forms\Blocks\DonationAmountBlock;
use Dono\Forms\Blocks\DropdownBlock;
use Dono\Forms\Blocks\FundPickerBlock;
use Dono\Forms\Blocks\HtmlBlock;
use Dono\Forms\Blocks\RecurringToggleBlock;
use Dono\Forms\Blocks\SectionBlock;
use Dono\Forms\Form;
use Dono\Forms\FormRepository;
use Dono\Foundation\Helpers\Money;
use Dono\Foundation\Hooks\HookProvider;
use Dono\Foundation\Plugin;
use Dono\Gateways\BrowserAware;
use Dono\Gateways\GatewayManager;
use Dono\Gateways\Stripe\StripeApi;
use Dono\Gateways\TestMode;
use Throwable;

/**
 * `[dono_donation_form]` shortcode. Renders a form's blocks plus a
 * data-dono-form-config script the Preact runtime reads.
 */
final class DonationFormShortcode extends HookProvider
{
    private const TAG    = 'dono_donation_form';
    private const HANDLE = 'dono-donation-form-runtime';

    private bool $cssLinkInlined = false;

    private bool $cloakEmitted = false;

    private ?string $cssVersion = null;

    public function __construct(
        private FormRepository $forms,
        private ?CampaignStyleResolver $styles = null,
        private ?CampaignRepository $campaigns = null,
        private ?AntiSpamGuard $spam = null,
        private ?GatewayManager $gateways = null,
        private ?TestMode $testMode = null,
    ) {
    }

    protected function actions(): array
    {
        return [
            'wp_enqueue_scripts' => 'maybeEnqueue',
        ];
    }

    /**
     * parent::register() wires the hook map; without it maybeEnqueue never runs
     * and every render falls back to the late enqueue path.
     */
    public function register(): void
    {
        add_shortcode(self::TAG, [$this, 'render']);
        parent::register();
    }

    public function maybeEnqueue(): void
    {
        if (! is_singular()) return;
        global $post;
        if (! $post || ! has_shortcode((string) $post->post_content, self::TAG)) return;
        $this->enqueue();
    }

    private function enqueue(): void
    {
        FormGatewayAssets::enqueue();
        FormFieldAssets::enqueue();

        $assetPath = DONO_DIR . 'build/donation-form/runtime/index.asset.php';
        if (file_exists($assetPath)) {
            $asset = require $assetPath;
            wp_register_script(
                self::HANDLE,
                DONO_URL . 'build/donation-form/runtime/index.js',
                array_merge($asset['dependencies'] ?? [], [FormGatewayAssets::HANDLE, FormFieldAssets::HANDLE]),
                $asset['version']      ?? DONO_VERSION,
                true
            );
            wp_enqueue_script(self::HANDLE);
        }

        $cssPath = DONO_DIR . 'build/donation-form/runtime.css';
        if (file_exists($cssPath)) {
            wp_register_style(
                self::HANDLE,
                DONO_URL . 'build/donation-form/runtime.css',
                [],
                $this->cssVersion()
            );
            wp_style_add_data(self::HANDLE, 'rtl', 'replace');
            wp_enqueue_style(self::HANDLE);
        }
    }

    private function cssFileName(): string
    {
        return is_rtl() ? 'runtime-rtl.css' : 'runtime.css';
    }

    public function render($atts = []): string
    {
        $atts = is_array($atts) ? $atts : [];
        $slug = trim((string) ($atts['slug'] ?? ''));
        if ($slug === '') {
            return $this->renderError(__('Specify a form slug: [dono_donation_form slug="..."].', 'dono'));
        }

        $form = $this->forms->findBySlug($slug);
        if (! $form) {
            return $this->renderError(sprintf(
                /* translators: %s: form slug */
                __('No donation form found for slug "%s".', 'dono'),
                $slug
            ));
        }

        // Rendering a form the submit gate will refuse is worse than rendering
        // nothing. The preview filter only takes effect for a user who can
        // edit, so the gate is never bypassed for a public visitor.
        $editorPreview = current_user_can('edit_posts')
            && (bool) apply_filters('dono.form.editor_preview', false, $form);
        if (! $editorPreview) {
            if ($form->status !== 'published') {
                return '';
            }
            $campaign = $this->campaigns ? $this->campaigns->findById($form->campaign_id) : null;
            if (! $campaign || ! $campaign->acceptsDonations()) {
                return '';
            }
        }

        if (! wp_script_is(self::HANDLE, 'enqueued')) {
            $this->enqueue();
        }

        $html = $this->renderBlocks($form);

        // Rendered after <head> (block, modal, or do_shortcode): wp_enqueue_style
        // would defer the stylesheet to the footer and the form paints unstyled
        // first. Once per request is enough; the rule set is global.
        if (
            ! $this->cssLinkInlined
            && did_action('wp_head')
            && wp_style_is(self::HANDLE, 'registered')
            && ! wp_style_is(self::HANDLE, 'done')
        ) {
            $this->cssLinkInlined = true;
            wp_dequeue_style(self::HANDLE);
            $href = DONO_URL . 'build/donation-form/' . $this->cssFileName() . '?ver=' . rawurlencode($this->cssVersion());
            $html = '<link rel="stylesheet" id="dono-runtime-css" href="' . esc_url($href) . '">' . $html;
        }

        // The server fallback markup is not styled by the runtime CSS, so it
        // flashes unstyled until the (footer) runtime mounts. The `dono-js`
        // class is only added when JS runs, so no-JS visitors keep the visible
        // fallback, and the timeout failsafe reveals the form if the runtime
        // never loads. Once per request.
        if (! $this->cloakEmitted) {
            $this->cloakEmitted = true;
            $html = "<style>.dono-js .dono-donation-form:not([data-dono-ready]){visibility:hidden}</style>"
                . "<script>document.documentElement.classList.add('dono-js');"
                . "setTimeout(function(){var n=document.querySelectorAll('.dono-donation-form:not([data-dono-ready])');"
                . "for(var i=0;i<n.length;i++)n[i].setAttribute('data-dono-ready','1')},4000)</script>"
                . $html;
        }

        return $html;
    }

    private function renderBlocks(Form $form): string
    {
        $formId = 'dono-form-' . wp_unique_id();

        // SSR fund-picker pre-selects the campaign default, matching the walker.
        $campDefaultFund = 0;
        if ($form->campaign_id) {
            $fpCampaign = Campaign::query()->find('id', (int) $form->campaign_id);
            if ($fpCampaign && $fpCampaign->default_fund_id) {
                $campDefaultFund = (int) $fpCampaign->default_fund_id;
            }
        }
        FundPickerBlock::$renderCampaignDefaultFundId = $campDefaultFund;
        $inner = do_blocks((string) $form->blocks);
        FundPickerBlock::$renderCampaignDefaultFundId = 0;

        $variant = apply_filters('dono.form.variant', null, $form, $this->visitorContext());
        $gateway = $this->pickGateway($form);
        $config  = $this->buildConfig($form, $gateway, $variant);

        [$containerClass, $containerDecls] = $this->containerAttrs($form);

        $tokens     = is_array($config['theme']['tokens'] ?? null) ? $config['theme']['tokens'] : [];
        $styleDecls = $this->tokenStyle($tokens) . $containerDecls;
        $styleAttr  = $styleDecls !== '' ? ' style="' . esc_attr($styleDecls) . '"' : '';

        // Only a no-JS visitor sees this: a dead form would GET their inputs
        // into the URL on submit.
        $noscript = '<noscript><div class="dono-donation-form__noscript">'
            . esc_html__('This donation form needs JavaScript enabled. Please turn it on and reload the page to donate.', 'dono')
            . '</div></noscript>';

        return sprintf(
            '<form class="dono-donation-form dono-donation-form--blocks%s" id="%s" data-form-slug="%s" data-gateway="%s" data-layout="%s"%s%s novalidate>%s<script type="application/json" data-dono-form-config>%s</script></form>',
            $containerClass,
            esc_attr($formId),
            esc_attr($form->slug),
            esc_attr($gateway),
            esc_attr((string) ($config['layout'] ?? 'inline')),
            $variant ? ' data-variant="' . esc_attr($variant) . '"' : '',
            $styleAttr,
            $noscript . $inner,
            // JSON_HEX_TAG so no config string (e.g. a thank-you message
            // containing </script>) can break out of this inline JSON block and
            // inject markup. The client JSON.parse decodes it back transparently.
            wp_json_encode($config, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE)
        );
    }

    /** @return array{0:string,1:string} [class-suffix, style-declarations] */
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
            ? ' dono-donation-form--plain'
            : ' dono-donation-form--framed';

        // Inline max-width (not just the CSS var) so host-theme selectors cannot out-specify it.
        $containerDecls = $width > 0
            ? '--dono-form-max-width:' . $width . 'px;max-width:' . $width . 'px'
            : '';

        return [$classSuffix, $containerDecls];
    }

    /** Inline custom properties, so the form is themed before the runtime mounts. */
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
        return $out;
    }


    /** @return array{html:string, cssUrl:string, jsUrl:string, jsDeps:list<string>} */
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

        $html = $this->renderBlocks($stub);

        $assetPath = DONO_DIR . 'build/donation-form/runtime/index.asset.php';
        $asset     = is_file($assetPath) ? include $assetPath : ['dependencies' => [], 'version' => DONO_VERSION];

        // Version CSS by mtime so SCSS-only rebuilds bust the iframe cache
        return [
            'html'   => $html,
            'cssUrl' => DONO_URL . 'build/donation-form/' . $this->cssFileName() . '?v=' . $this->cssVersion(),
            'jsUrl'  => DONO_URL . 'build/donation-form/runtime/index.js?v=' . ($asset['version'] ?? DONO_VERSION),
            'jsDeps' => (array) ($asset['dependencies'] ?? []),
        ];
    }

    /**
     * A self-contained document: the host editor never runs the runtime and the
     * iframe srcdoc cannot reach the editor's scripts, so both are inlined here.
     */
    public function buildPreviewDocument(array $preview, bool $autoResize = false, bool $transparent = false): string
    {
        $cssUrl   = esc_url($preview['cssUrl']);
        $jsUrl    = esc_url($preview['jsUrl']);
        $formHtml = $preview['html'];

        // Inline script-handle deps; the preview iframe is a standalone document.
        $depScripts = '';
        $scripts    = wp_scripts();
        foreach ($preview['jsDeps'] as $handle) {
            $reg = $scripts->registered[$handle] ?? null;
            $src = $reg ? (string) $reg->src : '';
            if ($src !== '') {
                $url = strpos($src, 'http') === 0 ? $src : site_url($src);
                $depScripts .= '<script src="' . esc_url($url) . '"></script>' . "\n";
            }
        }

        // Auto-resize hugs content, so the body must NOT stretch to the viewport:
        // min-height:100vh plus padding inflates the measured height and, fed back
        // into the frame height each observer tick, runs away.
        $bodyMinHeight = $autoResize ? '0' : '100vh';
        // White like a real page: the preview frame plays a browser window, and a
        // grey page inside it reads as a second window over the editor stage.
        $background    = $transparent ? 'transparent' : '#fff';
        $resize = $autoResize
            ? '<script>(function(){var l=0;function s(){try{if(!window.frameElement)return;var h=Math.min(document.documentElement.scrollHeight,4000);if(Math.abs(h-l)>2){l=h;window.frameElement.style.height=h+"px"}}catch(e){}}addEventListener("load",s);if(window.ResizeObserver){new ResizeObserver(s).observe(document.documentElement)}setTimeout(s,300);setTimeout(s,1200)})();</script>'
            : '';

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="stylesheet" href="{$cssUrl}">
    <style>
        html, body { margin: 0; padding: 0; background: {$background}; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen, Ubuntu, sans-serif; }
        body { padding: 32px 16px; min-height: {$bodyMinHeight}; }
    </style>
</head>
<body>
    {$formHtml}
    {$depScripts}
    <script src="{$jsUrl}"></script>
    {$resize}
</body>
</html>
HTML;
    }

    private function visitorContext(): array
    {
        $base = [
            'locale'  => determine_locale(),
            'country' => null,
            'user_id' => get_current_user_id() ?: null,
        ];
        return (array) apply_filters('dono.form.visitor_context', $base);
    }

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
                'one_time'
            );
            if ($opts !== []) {
                return $opts[0];
            }
        }

        // Offline is always registered, so it is the safe last resort.
        return 'offline';
    }

    private function buildConfig(Form $form, string $gateway, ?string $variant = null): array
    {
        $visitor = $this->visitorContext();
        $built    = $this->buildSteps($form, $variant, $visitor);
        $steps    = (array) apply_filters('dono.form.steps', $built['steps'], $form, $variant, $visitor);
        $pages    = $built['pages'];
        $pageNav  = $built['pageNav'];
        $preamble = $built['preamble'] ?? [];

        $layout = (string) ($form->settings['layout'] ?? 'inline');
        if (! in_array($layout, ['inline', 'modal'], true)) {
            $layout = 'inline';
        }

        $resolvedStyle = ['tokens' => [], 'accent' => '#1e8a4e', 'preset_id' => ''];
        if ($this->styles) {
            $campaign = ($this->campaigns && $form->campaign_id)
                ? $this->campaigns->findById($form->campaign_id)
                : null;
            $resolvedStyle = $this->styles->resolve($form, $campaign);
        }

        $thankYouMessage = trim((string) ($form->settings['thank_you_message'] ?? ''));
        if ($thankYouMessage === '') {
            $thankYouMessage = __('Thanks for your donation. A receipt is on the way to your inbox.', 'dono');
        }
        $redirectUrl = trim((string) ($form->settings['redirect_url'] ?? ''));

        $currency = $this->detectCurrency($form);

        $currencyCfg = get_option('dono_currency_locale', []);
        $fmtCfg      = is_array($currencyCfg['format'] ?? null) ? $currencyCfg['format'] : [];
        $numberFormat = [
            'decimalPlaces'  => (int) ($fmtCfg['decimal_places'] ?? 2),
            'decimalSep'     => (string) ($fmtCfg['decimal_sep']  ?? '.'),
            'thousandSep'    => (string) ($fmtCfg['thousand_sep'] ?? ','),
            'symbolPosition' => (string) ($fmtCfg['symbol_position'] ?? 'before'),
            'symbol'         => \Dono\Foundation\Helpers\Money::symbolFor($currency),
        ];

        $privacy = get_option('dono_privacy', []);
        $privacyUrl = is_array($privacy) ? trim((string) ($privacy['privacy_policy_url'] ?? '')) : '';

        $currencies = $this->detectCurrencies($form);
        $fxConfig   = $this->fxConfig($currency, $currencies);
        $swCfg      = $this->currencySwitcherConfig($form);

        // Rotated per render so a generic bot cannot denylist a fixed name.
        // Every name here is one no browser has a field type for: browsers
        // autofill by field name and label whatever autocomplete="off" says, and
        // a donor whose autofill writes into the trap has their donation refused
        // with no way to see or clear the value.
        $honeypotPool = ['form_ref', 'aux_code', 'extra_note', 'alt_ref', 'note_two', 'field_ref', 'checksum'];
        $honeypotName = $honeypotPool[random_int(0, count($honeypotPool) - 1)];

        $gatewaysCfg = null;
        if ($this->gateways !== null) {
            $allowedIds = is_array($form->settings['gateways']['allowed'] ?? null)
                ? $form->settings['gateways']['allowed']
                : [];
            $opts   = $this->gateways->optionsMetaFor($allowedIds);
            $ctxIds = $this->gateways->optionsFor(
                $allowedIds,
                $this->visitorContext()['country'] ?? null,
                $currency,
                'one_time'
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
            // this context; otherwise the first one they can, as before.
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

        $testModeOn = ( $this->testMode ?? new TestMode($this->forms) )->forForm($form);

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
            'rest'        => esc_url_raw(rest_url('dono/v1/donations')),
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
                'url'      => ( new \Dono\Donors\Portal\PortalPage() )->url(),
                // Published whole: the donations endpoint is a full URL, not a
                // base to append to.
                'sendLink' => esc_url_raw(rest_url('dono/v1/portal/send-link')),
                'token'    => $this->spam ? $this->spam->mintPortalToken() : '',
            ],
            // HMAC token (tied to render timestamp) echoed back on submit.
            'spam'        => [
                'formToken'   => $this->spam ? $this->spam->mintFormToken((int) $form->id) : '',
                'honeypotName' => $honeypotName,
                // Same filter as AntiSpamGuard, so the donor sees the minimum at
                // the amount field rather than only after submitting.
                'minAmountCents' => (int) apply_filters('dono.spam.min_amount_cents', 100),
            ],
            'steps'      => $steps,
            'pages'      => $pages,
            'pageNav'    => $pageNav,
            'preamble'   => $preamble,
            'i18n'     => [
                'chooseAmount'   => __('Choose an amount', 'dono'),
                'customAmount'   => __('Custom amount', 'dono'),
                'yourDetails'    => __('Your details', 'dono'),
                'firstName'      => __('First name', 'dono'),
                'lastName'       => __('Last name', 'dono'),
                'email'          => __('Email', 'dono'),
                'country'        => __('Country', 'dono'),
                'reviewDonation' => __('Review your donation', 'dono'),
                'amount'         => __('Amount', 'dono'),
                'frequency'      => __('Donation frequency', 'dono'),
                'fees'           => __('Processing fee', 'dono'),
                'total'          => __('Total', 'dono'),
                'manageGiving'   => __('Manage your giving', 'dono'),
                'portalLinkSent' => __('Check your email', 'dono'),
                'donor'          => __('Donor', 'dono'),
                'paymentMethod'  => __('Payment method', 'dono'),
                /* translators: %s: the selected currency code, e.g. INR. */
                'noGatewayForCurrency' => __('No payment method here accepts %s. Choose another currency to continue.', 'dono'),
                'noGatewayForFrequency' => __('No payment method here can take a recurring donation. Choose a one-time donation to continue.', 'dono'),
                // Not a currency problem: no allowed gateway is switched on.
                // Naming the currency sends donors hunting for a fix that is not
                // theirs to make.
                'noGatewayAvailable' => __('Online donations are unavailable right now. Please try again later.', 'dono'),
                'testModeNotice' => __('Test mode is on. No real payment is taken and this donation is excluded from reporting.', 'dono'),
                'back'           => __('Back', 'dono'),
                'next'           => __('Continue', 'dono'),
                'donateNow'      => __('Donate now', 'dono'),
                'processing'     => __('Processing…', 'dono'),
                'thanks'         => __('Thank you for your donation!', 'dono'),
                'pendingTitle'   => __('Your donation is pending', 'dono'),
                'pendingMessage' => __('Thank you. We have emailed you instructions to complete your payment.', 'dono'),
                // Bank debit: the donor has finished and nothing is expected of
                // them. The pending copy would tell someone who has already paid
                // that we are still waiting on them.
                'processingTitle'   => __('Thank you, your donation is on its way', 'dono'),
                'processingMessage' => __('Your bank is transferring your donation. It usually takes a few working days to arrive, and we will email you once it does.', 'dono'),
                'donateAgain'    => __('Donate again', 'dono'),
                'error'          => __('Sorry, something went wrong. Please try again.', 'dono'),
                'paymentTitle'   => __('Complete your donation', 'dono'),
                'paymentLoading' => __('Loading secure payment…', 'dono'),
                'payNow'         => __('Pay', 'dono'),
                'confirming'     => __('Confirming your payment…', 'dono'),
                'cancel'         => __('Cancel', 'dono'),
                'comment'        => __('Add a message', 'dono'),
                'notePublic'     => __('Show my message publicly on the supporter wall', 'dono'),
                'anonymous'      => __('Make this donation anonymous', 'dono'),
                'phone'          => __('Phone', 'dono'),
                'addressLine1'   => __('Address line 1', 'dono'),
                'addressLine2'   => __('Apartment, suite, etc.', 'dono'),
                'addressCity'    => __('City', 'dono'),
                'addressRegion'  => __('State / region', 'dono'),
                'addressPostal'  => __('Postal code', 'dono'),
                'addressCountry' => __('Country', 'dono'),
                'noSpecificFund' => __('No specific fund', 'dono'),
                'number'         => __('Number', 'dono'),
                'impact'         => __('Provides', 'dono'),
                'currency'       => __('Currency', 'dono'),
                'coverFees'      => __('I\'d like to help cover the transaction fee', 'dono'),
                'feesTotal'      => __('Total with fees:', 'dono'),
                'formTitle'      => __('Donation form', 'dono'),
                'close'          => __('Close', 'dono'),
                'required'       => __('Required', 'dono'),
                'freqOneTime'    => __('One-time', 'dono'),
                'freqWeekly'     => __('Weekly', 'dono'),
                'freqBiweekly'   => __('Every 2 weeks', 'dono'),
                'freqMonthly'    => __('Monthly', 'dono'),
                'freqQuarterly'  => __('Quarterly', 'dono'),
                'freqYearly'     => __('Yearly', 'dono'),
                'searchCountry'  => __('Search country…', 'dono'),
                'validation'     => [
                    'required'       => __('Required.', 'dono'),
                    'pickAmount'     => __('Pick or enter an amount.', 'dono'),
                    /* translators: %s: minimum donation amount formatted */
                    'minAmount'      => __('Minimum donation is %s.', 'dono'),
                    'invalidEmail'   => __('Enter a valid email.', 'dono'),
                    'enterName'      => __('Enter a name.', 'dono'),
                    'invalidNumber'  => __('Enter a number.', 'dono'),
                    /* translators: %s: minimum value */
                    'minNumber'      => __('Must be at least %s.', 'dono'),
                    /* translators: %s: maximum value */
                    'maxNumber'      => __('Must be at most %s.', 'dono'),
                    /* translators: %s: earliest allowed date */
                    'minDate'        => __('On or after %s.', 'dono'),
                    /* translators: %s: latest allowed date */
                    'maxDate'        => __('On or before %s.', 'dono'),
                    /* translators: %s: maximum length */
                    'tooLong'        => __('Too long (max %s).', 'dono'),
                    'invalidFormat'  => __('Invalid format.', 'dono'),
                    'pickAtLeastOne' => __('Pick at least one.', 'dono'),
                    /* translators: %s: minimum number of selections */
                    'pickAtLeast'    => __('Pick at least %s.', 'dono'),
                    /* translators: %s: maximum number of selections */
                    'pickNoMoreThan' => __('Pick no more than %s.', 'dono'),
                ],
            ],
        ];

        return (array) apply_filters('dono.form.config', $config, $form, $variant, $visitor);
    }

    /**
     * @return array{
     *   steps: list<array<string,mixed>>,
     *   pages: list<array{title:string}>,
     *   pageNav: array{prevLabel:string,nextLabel:string,progressStyle:string},
     *   preamble: list<array<string,mixed>>,
     * }
     */
    private function buildSteps(Form $form, ?string $variant = null, array $visitor = []): array
    {
        $blocks       = parse_blocks((string) $form->blocks);
        $steps        = [];
        // One ordered stream of fields and content, so the runtime renders them
        // interleaved in authored order rather than all content then all fields.
        $items        = [];
        // Root content before a dono/steps wizard: rendered once above it.
        $preamble     = [];
        $rowSeq       = 0;
        // The dono/step index a step sits in (0 = none).
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
                case 'dono/heading':
                    $level = (int) ($attrs['level'] ?? 2);
                    if ($level < 1 || $level > 6) $level = 2;
                    $items[] = $withCond([
                        'kind'  => 'heading',
                        'text'  => (string) ($attrs['text']  ?? ''),
                        'level' => $level,
                        'align' => (string) ($attrs['align'] ?? 'left'),
                    ], $attrs);
                    break;

                case 'dono/paragraph':
                    $items[] = $withCond([
                        'kind'  => 'paragraph',
                        'html'  => wp_kses_post((string) ($attrs['text'] ?? '')),
                        'align' => (string) ($attrs['align'] ?? 'left'),
                    ], $attrs);
                    break;

                case 'dono/divider':
                    $items[] = $withCond(
                        ['kind' => 'divider'] + DividerBlock::settings($attrs),
                        $attrs
                    );
                    break;

                case 'dono/html':
                    $items[] = $withCond([
                        'kind' => 'html',
                        'html' => HtmlBlock::sanitize((string) ($attrs['content'] ?? '')),
                    ], $attrs);
                    break;

                case 'dono/currency-switcher':
                    $sw = CurrencySwitcherBlock::settings($attrs);
                    $items[] = $withCond([
                        'kind'    => 'currency-switcher',
                        'variant' => $sw['style'],
                        'align'   => $sw['align'],
                        'label'   => $sw['label'],
                    ], $attrs);
                    break;

                case 'dono/payment-gateways':
                    $items[] = $withCond(['kind' => 'payment-gateways'], $attrs);
                    break;

                case 'dono/privacy-notice':
                    // Cast to object so empty attrs encode as {} not []; [] makes
                    // the block comment unparseable and the notice silently vanishes.
                    $privacyHtml = (string) do_blocks(
                        '<!-- wp:dono/privacy-notice ' . wp_json_encode((object) $attrs) . ' /-->'
                    );
                    $items[] = $withCond(['kind' => 'html', 'html' => $privacyHtml], $attrs);
                    break;

                case 'dono/hidden':
                    $items[] = $tagRow([
                        'kind'         => 'hidden',
                        'field'        => (string) ($attrs['field']        ?? ''),
                        'source'       => (string) ($attrs['source']       ?? 'fixed'),
                        'queryParam'   => (string) ($attrs['queryParam']   ?? ''),
                        'defaultValue' => (string) ($attrs['defaultValue'] ?? ''),
                    ], $row, $attrs);
                    break;

                case 'dono/goal':
                    $goalAttrs = $attrs;
                    $goalAttrs['campaignId'] = $form->campaign_id;
                    $goalAttrs['formId']     = $form->id;
                    $html = (string) do_blocks(
                        '<!-- wp:dono/goal ' . wp_json_encode($goalAttrs) . ' /-->'
                    );
                    $items[] = $withCond(['kind' => 'html', 'html' => $html], $attrs);
                    break;

                case 'dono/row':
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

                case 'dono/columns':
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
                        'classes'  => ['dono-block', 'dono-block--columns'],
                        'style'    => $columnsInlineStyle,
                        'children' => $columnsChildren,
                    ], $attrs);
                    break;

                case 'dono/steps':
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

                case 'dono/step':
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

                case 'dono/section':
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
                        'classes'  => ['dono-block', 'dono-block--section'],
                        'style'    => $sectionInlineStyle,
                        'children' => $sectionChildren,
                    ], $attrs);
                    break;

                case 'dono/name':
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

                case 'dono/email':
                    // Required block: never conditional (see dono/name).
                    $items[] = $tagRow([
                        'kind'        => 'email',
                        'label'       => (string) ($attrs['label'] ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'required'    => (bool) ($attrs['required'] ?? true),
                    ], $row, []);
                    break;

                case 'dono/country':
                    $items[] = $tagRow([
                        'kind'        => 'country',
                        'label'       => (string) ($attrs['label'] ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'required'    => (bool) ($attrs['required'] ?? false),
                    ], $row, $attrs);
                    break;

                case 'dono/phone':
                    $items[] = $tagRow([
                        'kind'        => 'phone',
                        'label'       => (string) ($attrs['label'] ?? ''),
                        'placeholder' => (string) ($attrs['placeholder'] ?? ''),
                        'required'    => (bool) ($attrs['required'] ?? false),
                    ], $row, $attrs);
                    break;

                case 'dono/comment':
                    $items[] = $tagRow([
                        'kind'        => 'comment',
                        'label'       => (string) ($attrs['label']       ?? __('Add a message', 'dono')),
                        'placeholder' => (string) ($attrs['placeholder'] ?? __('Anything you want to share?', 'dono')),
                        'required'    => (bool)   ($attrs['required']    ?? false),
                    ], $row, $attrs);
                    break;

                case 'dono/anonymous-toggle':
                    $privacyCfg     = get_option('dono_privacy', []);
                    $globalDefault  = is_array($privacyCfg) && ! empty($privacyCfg['always_anonymous_default']);
                    $items[] = $tagRow([
                        'kind'      => 'anonymous',
                        'label'     => (string) ($attrs['label']     ?? __('Make this donation anonymous', 'dono')),
                        'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false) || $globalDefault,
                    ], $row, $attrs);
                    break;

                case 'dono/cover-fees':
                    $items[] = $tagRow([
                        'kind'      => 'cover_fees',
                        'label'     => (string) ($attrs['label']     ?? __('Cover the processing fee so 100% of my donation reaches you', 'dono')),
                        'percent'   => (float)  ($attrs['percent']   ?? 2.9),
                        'fixed'     => (int)    ($attrs['fixed']     ?? 30),
                        'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
                    ], $row, $attrs);
                    break;

                case 'dono/fund-picker':
                    $fpAllow      = (bool) ($attrs['allowEmpty'] ?? false);
                    $fpRepo       = new \Dono\Funds\FundRepository();
                    $fpAllowedIds = array_values(array_filter(array_map('intval', (array) ($attrs['fundIds'] ?? []))));
                    $fpOptions    = $fpRepo->pickerOptions($fpAllowedIds !== [] ? $fpAllowedIds : null);

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
                        'empty_description' => trim((string) ($attrs['emptyDescription'] ?? '')),
                    ], $row, $attrs);
                    break;

                case 'dono/address':
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

                case 'dono/consent':
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
                            'checked'     => (bool) $cp['required'] || (bool) $cp['default'],
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

                case 'dono/donation-summary':
                    // A decoration, not a field: it reads state back rather than
                    // collecting anything, and only decorations reach
                    // renderDecorationItem.
                    $items[] = $withCond([
                        'kind'        => 'summary',
                        'showDonor'   => (bool) ($attrs['showDonor']   ?? true),
                        'showGateway' => (bool) ($attrs['showGateway'] ?? true),
                    ], $attrs);
                    break;

                case 'dono/terms':
                    if (TermsBlock::isConfigured($attrs)) {
                        $items[] = $tagRow([
                            'kind'     => 'terms',
                            'purpose'  => TermsBlock::PURPOSE,
                            'label'    => (string) ($attrs['label']    ?? ''),
                            'terms'    => (string) ($attrs['terms']    ?? ''),
                            'linkUrl'  => (string) ($attrs['linkUrl']  ?? ''),
                            'linkText' => (string) ($attrs['linkText'] ?? ''),
                        ], $row, $attrs);
                    }
                    break;

                case 'dono/donation-amount':
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
                    $presets = (array) apply_filters('dono.form.amounts', $presets, $form, $variant, $visitor);
                    $steps[] = [
                        'type'        => 'amount',
                        'page'        => $currentPage,
                        'presets'     => array_values($presets),
                        'allowCustom' => (bool) ($attrs['allowCustom'] ?? true),
                    ];
                    break;

                case 'dono/submit-button':
                    $flushItems();
                    $sbAlign = (string) ($attrs['align'] ?? 'left');
                    if (! in_array($sbAlign, ['left', 'center', 'right', 'full'], true)) {
                        $sbAlign = 'left';
                    }
                    $steps[] = [
                        'type'        => 'submit',
                        'page'        => $currentPage,
                        'label'       => (string) ($attrs['label'] ?? __('Donate now', 'dono')),
                        'align'       => $sbAlign,
                    ];
                    break;

                case 'dono/date':
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

                case 'dono/text-input':
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

                case 'dono/number-input':
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

                case 'dono/recurring-toggle':
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

                case 'dono/dropdown':
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

                case 'dono/radio':
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

                case 'dono/checkbox':
                    $items[] = $tagRow([
                        'kind'      => 'checkbox',
                        'label'     => (string) ($attrs['label']    ?? ''),
                        'helpText'  => (string) ($attrs['helpText'] ?? ''),
                        'required'  => (bool)   ($attrs['required'] ?? false),
                        'defaultOn' => (bool)   ($attrs['defaultOn'] ?? false),
                        'field'     => DropdownBlock::deriveField((string) ($attrs['field'] ?? ''), (string) ($attrs['label'] ?? '')),
                    ], $row, $attrs);
                    break;

                case 'dono/multi-select':
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
                        'minSelections' => max(0, (int) ($attrs['minSelections'] ?? 0)),
                        'maxSelections' => max(0, (int) ($attrs['maxSelections'] ?? 0)),
                        'defaults'      => $msDefaults,
                    ], $row, $attrs);
                    break;

                default:
                    // A field block shipped outside core answers with the runtime
                    // item its component renders from; a block nobody claims stays
                    // out of the config rather than reaching the donor half-built.
                    $extra = apply_filters('dono.form.block_field', null, $name, $attrs, $form);
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
            $steps[] = ['type' => 'submit', 'page' => $currentPage, 'label' => __('Donate now', 'dono')];
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

    /** Falls back to the org currency, never a hardcoded USD. */
    private function detectCurrency(Form $form): string
    {
        $found = null;
        $scan = function (array $list) use (&$scan, &$found): void {
            foreach ($list as $b) {
                if ($found !== null) return;
                if (($b['blockName'] ?? '') === 'dono/donation-amount') {
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

    private function detectCurrencies(Form $form): array
    {
        $codes  = [];
        $blocks = parse_blocks((string) $form->blocks);
        $scan = function (array $list) use (&$scan, &$codes): void {
            foreach ($list as $b) {
                if (($b['blockName'] ?? '') === 'dono/currency-switcher') {
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

    private function currencySwitcherConfig(Form $form): ?array
    {
        $found  = null;
        $blocks = parse_blocks((string) $form->blocks);
        $scan = function (array $list) use (&$scan, &$found): void {
            foreach ($list as $b) {
                if ($found !== null) return;
                if (($b['blockName'] ?? '') === 'dono/currency-switcher') {
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

    private function hasPaymentGatewaysBlock(Form $form): bool
    {
        return $this->findPaymentGatewaysBlockAttrs($form) !== null;
    }

    private function findPaymentGatewaysBlockAttrs(Form $form): ?array
    {
        $found  = null;
        $blocks = parse_blocks((string) $form->blocks);
        $scan = function (array $list) use (&$scan, &$found): void {
            foreach ($list as $b) {
                if ($found !== null) return;
                if (($b['blockName'] ?? '') === 'dono/payment-gateways') {
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
     */
    private function fxConfig(string $formCurrency, array $switcherCurrencies): array
    {
        $fx   = new \Dono\Currency\FxRates();
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

    private function renderError(string $message): string
    {
        if (! current_user_can('manage_options') && ! current_user_can('manage_dono')) {
            return '';
        }
        return sprintf(
            '<div class="dono-donation-form__error" style="padding:12px 16px;border:1px solid #c00;background:#fee;color:#900;font-size:13px;">%s</div>',
            esc_html($message)
        );
    }

    private function cssVersion(): string
    {
        if ($this->cssVersion === null) {
            $path = DONO_DIR . 'build/donation-form/runtime.css';
            $this->cssVersion = (string) (@filemtime($path) ?: DONO_VERSION);
        }
        return $this->cssVersion;
    }

    /** The publishable key for the mode the intent will be created in. */
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
     * The client id for the mode the order will be created in. It is public by
     * design, like a Stripe publishable key.
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
                ->get(\Dono\Gateways\PayPal\PayPalAccount::class)
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
}
