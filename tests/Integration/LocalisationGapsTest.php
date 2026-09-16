<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donations\Donation;
use Gratora\Foundation\Plugin;
use Gratora\Receipts\Receipt;
use Gratora\Receipts\ReceiptIssuer;
use WP_REST_Request;

/**
 * Places the plugin knew a donor's language and did not use it.
 */
final class LocalisationGapsTest extends IntegrationTestCase
{

    private function receiptedDonation(string $locale): Receipt
    {
        $create = new WP_REST_Request('POST', '/gratora/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode([
            'email'        => 'locale-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'locale'       => $locale,
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
        ]));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/gratora/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);
        $this->runPendingAsyncJobs();

        $donation = Donation::query()->find('reference', $reference);
        $receipt  = Receipt::query()->where('donation_id', (int) $donation->id)->get();
        $this->assertNotNull($receipt, 'fixture: the donation was receipted');

        return $receipt;
    }

    /** A renderer that answers only "what language was I called in". */
    private function recordingRenderer(string $id, array &$seen): object
    {
        return new class ($id, $seen) implements \Gratora\Receipts\ReceiptRenderer {
            /** @param list<string> $seen */
            public function __construct(private string $rid, private array &$seen)
            {
            }

            public function id(): string { return $this->rid; }
            public function label(): string { return 'Recording'; }
            public function referenceScope(): string { return 'receipt'; }
            public function appliesTo(\Gratora\Receipts\ReceiptContext $ctx): bool { return true; }

            public function render(\Gratora\Receipts\ReceiptContext $ctx): string
            {
                $this->seen[] = get_locale();

                return '%PDF-1.4';
            }
        };
    }

    public function test_re_rendering_a_receipt_uses_the_donor_s_language(): void
    {
        $receipt = $this->receiptedDonation('de_DE');
        $seen    = [];
        $mine    = $this->recordingRenderer((string) $receipt->renderer_id, $seen);

        add_filter('gratora.receipt.renderers', static fn (): array => [$mine], 99);

        Plugin::instance()->container->get(ReceiptIssuer::class)->renderReceiptPdf((int) $receipt->id);

        $this->assertSame(
            ['de_DE'],
            $seen,
            'the admin copy of a receipt is written in a different language than the donor was sent'
        );
    }

    public function test_the_locale_is_put_back_afterwards(): void
    {
        $receipt = $this->receiptedDonation('de_DE');
        $before  = get_locale();

        Plugin::instance()->container->get(ReceiptIssuer::class)->renderReceiptPdf((int) $receipt->id);

        $this->assertSame($before, get_locale(), 'the request carries on in whatever language it started in');
    }


    /** @return array<string,mixed> */
    private function formConfig(): array
    {
        $shortcode = Plugin::instance()->container->get(\Gratora\Forms\Shortcode\DonationFormShortcode::class);

        return $this->formConfigIn((string) $shortcode->renderPreview('')['html']);
    }

    public function test_the_terms_checkbox_labels_come_from_the_server(): void
    {
        $i18n = (array) ($this->formConfig()['i18n'] ?? []);

        $this->assertArrayHasKey('agreeToTerms', $i18n, 'the field falls back to hardcoded English');
        $this->assertArrayHasKey('readTerms', $i18n);
        $this->assertNotSame('', (string) $i18n['agreeToTerms']);
    }

    public function test_the_blocking_terms_message_comes_from_the_server(): void
    {
        $validation = (array) (($this->formConfig()['i18n'] ?? [])['validation'] ?? []);

        $this->assertArrayHasKey(
            'termsRequired',
            $validation,
            'a donor is stopped from giving by a sentence nothing translates'
        );
    }


    /**
     * The build emits an -rtl.css beside each admin stylesheet and nothing
     * asked WordPress to use them, so every admin screen laid itself out
     * left-to-right in Arabic or Hebrew.
     *
     * Asked of WordPress rather than of the source: what matters is that the
     * registered style carries the flag by the time the screen renders.
     *
     * @dataProvider adminScreens
     */
    public function test_an_admin_screen_registers_the_rtl_variant_of_its_stylesheet(string $page, string $handle): void
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        // Only this handle, so the shared registry the rest of the suite runs
        // against is left as it was.
        wp_deregister_style($handle);

        ob_start();
        (new $page())->render();
        ob_end_clean();

        $this->assertArrayHasKey($handle, wp_styles()->registered, 'fixture: the screen enqueues its stylesheet');
        $this->assertSame(
            'replace',
            wp_styles()->get_data($handle, 'rtl'),
            "{$handle} lays out left-to-right in a right-to-left language"
        );
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function adminScreens(): array
    {
        return [
            'donations'     => [\Gratora\Admin\Pages\DonationsPage::class, 'gratora-admin-donations'],
            'donors'        => [\Gratora\Admin\Pages\DonorsPage::class, 'gratora-admin-donors'],
            'campaigns'     => [\Gratora\Admin\Pages\CampaignsPage::class, 'gratora-admin-campaigns'],
            'funds'         => [\Gratora\Admin\Pages\FundsPage::class, 'gratora-admin-funds'],
            'subscriptions' => [\Gratora\Admin\Pages\SubscriptionsPage::class, 'gratora-admin-subscriptions'],
            'tools'         => [\Gratora\Admin\Pages\ToolsPage::class, 'gratora-admin-tools'],
        ];
    }
}
