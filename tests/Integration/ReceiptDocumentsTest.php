<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\Donation;
use FundKit\Donors\Donor;
use FundKit\Donors\DonorService;
use FundKit\Foundation\Plugin;
use FundKit\Receipts\Receipt;
use FundKit\Receipts\ReceiptContext;
use FundKit\Receipts\ReceiptIssuer;
use FundKit\Receipts\ReceiptRenderer;
use FundKit\Settings\SettingsService;
use WP_REST_Request;

/**
 * What a donor keeps: the receipt, the annual statement, and the copy they
 * fetch again from the emailed link. They are meant to be the same document
 * and the same organisation.
 */
final class ReceiptDocumentsTest extends IntegrationTestCase
{
    /** Captures the context each render is handed. */
    private array $rendered = [];

    private function captureRenders(string $rendererId): void
    {
        $sink = function (ReceiptContext $ctx): void {
            $this->rendered[] = $ctx;
        };

        add_filter('fundkit.receipt.renderers', static function () use ($rendererId, $sink): array {
            return [ new class ($rendererId, $sink) implements ReceiptRenderer {
                public function __construct(private string $rid, private $sink)
                {
                }

                public function id(): string { return $this->rid; }
                public function label(): string { return 'Capture'; }
                public function referenceScope(): string { return 'receipt'; }
                public function appliesTo(ReceiptContext $ctx): bool { return true; }

                public function render(ReceiptContext $ctx): string
                {
                    ($this->sink)($ctx);

                    return '%PDF-1.4';
                }
            } ];
        }, 99);
    }

    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    private function paidDonation(array $custom = []): Donation
    {
        $create = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $create->set_header('content-type', 'application/json');
        $create->set_body((string) wp_json_encode(array_filter([
            'email'        => 'doc-' . uniqid() . '@example.test',
            'amount_cents' => 5000,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Ida', 'last_name' => 'Kerr'],
            'custom'       => $custom ?: null,
        ])));
        $reference = (string) rest_do_request($create)->get_data()['reference'];

        $confirm = new WP_REST_Request('POST', "/fundkit/v1/donations/{$reference}/confirm");
        $confirm->set_header('content-type', 'application/json');
        $confirm->set_body('{}');
        rest_do_request($confirm);
        $this->runPendingAsyncJobs();

        return Donation::query()->find('reference', $reference);
    }


    public function test_a_thank_you_email_names_the_organisation_not_the_website(): void
    {
        update_option('fundkit_org_profile', ['name' => 'Acme Foundation']);
        update_option('blogname', 'Some WordPress Site');

        $mails = $this->captureMails();
        $this->paidDonation();

        $all = implode("\n", array_map(static fn ($m): string => (string) ($m['message'] ?? ''), (array) $mails));
        $this->assertStringContainsString('Acme Foundation', $all);
        $this->assertStringNotContainsString('Some WordPress Site', $all);
    }


    /**
     * A renderer that reports the context it was handed and stops the stream.
     * The report is written to the returned box rather than carried on the
     * throw: the controller answers a failed render now, so the exception no
     * longer reaches the caller.
     */
    private function captureAndStop(string $rendererId): \ArrayObject
    {
        $seen = new \ArrayObject();

        add_filter('fundkit.receipt.renderers', static function () use ($rendererId, $seen): array {
            return [ new class ($rendererId, $seen) implements ReceiptRenderer {
                public function __construct(private string $rid, private \ArrayObject $seen)
                {
                }

                public function id(): string { return $this->rid; }
                public function label(): string { return 'Capture'; }
                public function referenceScope(): string { return 'receipt'; }
                public function appliesTo(ReceiptContext $ctx): bool { return true; }

                public function render(ReceiptContext $ctx): string
                {
                    $this->seen->exchangeArray((array) ($ctx->extras['custom_data'] ?? []));

                    // Thrown rather than returned: stream() exits, which would
                    // take the test process with it.
                    throw new \RuntimeException('captured');
                }
            } ];
        }, 99);

        return $seen;
    }

    public function test_the_re_download_carries_the_answers_the_attached_copy_had(): void
    {
        $donation = $this->paidDonation([ 'dietary' => 'Vegetarian' ]);
        $receipt  = Receipt::query()->where('donation_id', (int) $donation->id)->get();
        $this->assertNotNull($receipt, 'fixture: the donation was receipted');

        $token = Plugin::instance()->container->get(\FundKit\Donors\MagicLinkService::class)
            ->issue((int) $receipt->donor_id, 'download_receipt', (int) $receipt->id);

        $seen = $this->captureAndStop((string) $receipt->renderer_id);

        $req = new WP_REST_Request('GET', '/fundkit/v1/receipts/' . (int) $receipt->id . '/download');
        $req->set_param('receipt_id', (int) $receipt->id);
        $req->set_param('token', $token);

        Plugin::instance()->container->get(\FundKit\Rest\ReceiptsController::class)->download($req);

        $this->assertSame(
            [ 'dietary' => 'Vegetarian' ],
            $seen->getArrayCopy(),
            'the emailed link handed the donor a document missing what the attached copy showed'
        );
    }


    private function statementFooter(): string
    {
        $donors = Plugin::instance()->container->get(DonorService::class);
        $donor  = $donors->findOrCreate('stmt-' . uniqid() . '@example.test', ['first_name' => 'Ada', 'last_name' => 'Lovelace']);

        $now = gmdate('Y-m-d H:i:s');
        $d   = Donation::make();
        $d->reference         = 'FUNDKIT-STMT-' . uniqid();
        $d->donor_id          = (int) $donor->id;
        $d->amount_cents      = 10_000;
        $d->net_cents         = 10_000;
        $d->currency          = 'USD';
        $d->base_amount_cents = 10_000;
        $d->base_currency     = 'USD';
        $d->fx_rate           = '1.00000000';
        $d->gateway           = 'offline';
        $d->status            = 'paid';
        $d->is_test           = false;
        $d->paid_at           = '2024-03-04 09:00:00';
        $d->created_at        = $now;
        $d->updated_at        = $now;
        $d->save();

        $seen = '';
        add_filter('fundkit.statement.pdf', static function ($pdf) { return $pdf; });

        $builder = Plugin::instance()->container->get(\FundKit\Reports\TaxStatementBuilder::class);

        $ref = new \ReflectionMethod($builder, 'orgDisclaimer');
        $ref->setAccessible(true);

        return (string) $ref->invoke($builder, 'Acme Foundation', 'Ada Lovelace');
    }

    public function test_an_untouched_receipts_panel_leaves_the_statement_footer_empty(): void
    {
        // Saving the panel without editing anything, which is what the screen
        // posts back on any change to any other field.
        $current = (array) $this->settings()->get('receipts');
        $this->settings()->update('receipts', $current);

        $this->assertSame(
            '',
            $this->statementFooter(),
            'the built-in receipt footer says the document is non-fiscal, which is the opposite of what a statement is for'
        );
    }

    public function test_a_footer_the_org_wrote_does_reach_the_statement(): void
    {
        $this->settings()->update('receipts', ['footer_note' => 'Registered charity 1234567.']);

        $this->assertSame('Registered charity 1234567.', $this->statementFooter());
    }

    public function test_a_merge_tag_the_statement_knows_is_filled_in(): void
    {
        $this->settings()->update('receipts', ['footer_note' => 'Issued by {organisation_name}.']);

        $this->assertSame('Issued by Acme Foundation.', $this->statementFooter());
    }

    public function test_a_tag_the_statement_cannot_answer_is_not_printed_raw(): void
    {
        $this->settings()->update('receipts', ['footer_note' => 'Receipt {receipt_number} issued.']);

        $footer = $this->statementFooter();
        $this->assertStringNotContainsString('{', $footer);
        $this->assertStringNotContainsString('}', $footer);
    }


    private function receiptTemplate(): array
    {
        $renderer = Plugin::instance()->container->get(\FundKit\Receipts\Renderers\GenericReceiptRenderer::class);
        $ref      = new \ReflectionMethod($renderer, 'loadTemplate');
        $ref->setAccessible(true);

        return (array) $ref->invoke($renderer);
    }

    public function test_an_untouched_footer_still_shows_the_built_in_text(): void
    {
        delete_option('fundkit_receipt_settings');

        $this->assertNotSame('', (string) $this->receiptTemplate()['footer_note']);
    }

    public function test_a_footer_the_admin_cleared_stays_cleared(): void
    {
        $this->settings()->update('receipts', ['footer_note' => '']);

        $this->assertSame(
            '',
            (string) $this->receiptTemplate()['footer_note'],
            'the admin deliberately emptied the field and the text came back'
        );
    }

    public function test_a_signoff_the_admin_cleared_stays_cleared(): void
    {
        $this->settings()->update('receipts', ['signoff' => '']);

        $this->assertSame('', (string) $this->receiptTemplate()['signoff']);
    }
}
