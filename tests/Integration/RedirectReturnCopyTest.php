<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Forms\Form;
use WP_REST_Request;

/**
 * A bank redirect ends in one of three places, and only one of them is a
 * payment. A donor who pressed cancel at their bank, or whose bank refused the
 * debit, comes back to the same page as a donor whose payment broke, and the
 * generic apology sends them to check a statement with nothing on it.
 *
 * The sentence that tells them their money stayed put has to be rendered into
 * the form config, because the runtime has no strings of its own.
 */
final class RedirectReturnCopyTest extends IntegrationTestCase
{
    private string $slug = '';

    protected function setUp(): void
    {
        parent::setUp();

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Return copy campaign', 'status' => 'published']));
        $campaignId = (int) rest_do_request($req)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/gratora/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Return copy form',
            'campaign_id' => $campaignId,
            'blocks'      => '<!-- wp:gratora/donation-amount {"presets":[1000],"currency":"EUR"} /-->'
                . '<!-- wp:gratora/email /-->'
                . '<!-- wp:gratora/submit-button /-->',
        ]));
        $form = Form::query()->find('id', (int) rest_do_request($req)->get_data()['id']);
        $form->status = 'published';
        $form->save();

        $this->slug = (string) $form->slug;
    }

    /** @return array<string,mixed> */
    private function renderedI18n(): array
    {
        $config = $this->formConfigIn(do_shortcode('[gratora_donation_form slug="' . $this->slug . '"]'));

        return (array) ($config['i18n'] ?? []);
    }

    public function test_the_form_carries_a_sentence_for_a_payment_that_never_happened(): void
    {
        $i18n = $this->renderedI18n();

        $this->assertArrayHasKey('notCompleted', $i18n);
        $this->assertNotSame(
            $i18n['error'] ?? '',
            $i18n['notCompleted'],
            'a donor who cancelled is not a donor whose payment broke'
        );
    }

    public function test_that_sentence_says_the_money_stayed_where_it_was(): void
    {
        $i18n = $this->renderedI18n();

        // The whole point of separating it: "something went wrong" leaves a
        // donor wondering whether they paid twice.
        $this->assertStringContainsString('nothing has been charged', (string) $i18n['notCompleted']);
    }

    public function test_the_form_carries_a_sentence_for_a_return_it_could_not_resolve(): void
    {
        $i18n = $this->renderedI18n();

        // The third place a redirect ends: the browser could not find out what
        // happened, which is neither of the other two.
        $this->assertArrayHasKey('returnUnresolved', $i18n);
        $this->assertNotSame($i18n['error'] ?? '', $i18n['returnUnresolved']);
        $this->assertNotSame($i18n['notCompleted'] ?? '', $i18n['returnUnresolved']);
    }

    public function test_that_sentence_does_not_ask_for_a_second_payment(): void
    {
        $i18n = $this->renderedI18n();
        $copy = (string) ($i18n['returnUnresolved'] ?? '');

        $this->assertStringNotContainsString('try again', $copy);
        $this->assertStringContainsString('do not pay again', $copy);

        // A screen with nothing to press is a dead end, and the check is the
        // one thing left that can still settle this donation in the browser.
        $this->assertArrayHasKey('checkAgain', $i18n);
        $this->assertNotSame('', (string) $i18n['checkAgain']);
    }
}
