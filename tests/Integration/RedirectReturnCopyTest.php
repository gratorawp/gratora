<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Forms\Form;
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

        $req = new WP_REST_Request('POST', '/dono/v1/admin/campaigns');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(['title' => 'Return copy campaign', 'status' => 'published']));
        $campaignId = (int) rest_do_request($req)->get_data()['id'];

        $req = new WP_REST_Request('POST', '/dono/v1/admin/forms');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'title'       => 'Return copy form',
            'campaign_id' => $campaignId,
            'blocks'      => '<!-- wp:dono/donation-amount {"presets":[1000],"currency":"EUR"} /-->'
                . '<!-- wp:dono/email /-->'
                . '<!-- wp:dono/submit-button /-->',
        ]));
        $form = Form::query()->find('id', (int) rest_do_request($req)->get_data()['id']);
        $form->status = 'published';
        $form->save();

        $this->slug = (string) $form->slug;
    }

    /** @return array<string,mixed> */
    private function renderedI18n(): array
    {
        $html = do_shortcode('[dono_donation_form slug="' . $this->slug . '"]');

        $this->assertMatchesRegularExpression(
            '#<script type="application/json" data-dono-form-config>(.*?)</script>#s',
            $html
        );
        preg_match('#<script type="application/json" data-dono-form-config>(.*?)</script>#s', $html, $m);

        $config = json_decode(html_entity_decode($m[1], ENT_QUOTES), true);
        $this->assertIsArray($config);

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
}
