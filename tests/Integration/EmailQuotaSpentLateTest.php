<?php

declare(strict_types=1);

namespace FundKit\Tests\Integration;

use FundKit\Donations\AntiSpamGuard;
use FundKit\Forms\Form;
use FundKit\Foundation\Plugin;
use WP_Error;
use WP_REST_Request;

/**
 * Whose budget a doomed submission spends.
 *
 * The email quota is addressable by a stranger: anyone can type anyone's
 * address, and spending a slot refuses the person who owns it. So a submission
 * refused on the form, the campaign or the payload must not spend one, or an
 * outsider holds a named donor out of donating with requests that create
 * nothing, leave no row, and cost them one of their own attempts at most.
 */
final class EmailQuotaSpentLateTest extends IntegrationTestCase
{
    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    private function draftForm(): Form
    {
        $form = Form::make();
        $form->campaign_id = 1;
        $form->slug        = 'quota-late-' . bin2hex(random_bytes(3));
        $form->title       = 'Quota ordering probe';
        // Not published, so the public create path refuses it at the form gate.
        $form->status      = 'draft';
        $form->blocks      = '';
        $form->settings    = [];
        $form->save();

        return $form;
    }

    private function submit(string $email, int $formId): int
    {
        $req = new WP_REST_Request('POST', '/fundkit/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode([
            'email'        => $email,
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'form_id'      => $formId,
        ]));

        return rest_do_request($req)->get_status();
    }

    /** How many of the mailbox's three attempts are still unspent. */
    private function remaining(string $email): int
    {
        $left = 0;
        for ($i = 0; $i < 3; $i++) {
            if ($this->guard()->consumeEmailQuota($email) instanceof WP_Error) {
                return $left;
            }
            $left++;
        }

        return $left;
    }

    public function test_a_submission_refused_on_the_form_spends_none_of_the_donors_budget(): void
    {
        $victim = 'victim-' . uniqid() . '@example.test';
        $form   = $this->draftForm();

        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(403, $this->submit($victim, (int) $form->id), 'the draft form must refuse');
        }

        $this->assertSame(
            3,
            $this->remaining($victim),
            'a stranger must not be able to spend a named donor\'s attempts on submissions that create nothing'
        );
    }

    public function test_a_submission_naming_no_form_at_all_spends_nothing(): void
    {
        $victim = 'victim-' . uniqid() . '@example.test';

        for ($i = 0; $i < 6; $i++) {
            $this->assertSame(403, $this->submit($victim, 999999), 'an unresolvable form must refuse');
        }

        $this->assertSame(3, $this->remaining($victim));
    }

    /**
     * The quota still has to bite, or moving it would have traded one hole for
     * a larger one.
     */
    public function test_the_quota_still_bounds_a_donor_who_reaches_the_gateway(): void
    {
        $email = 'real-' . uniqid() . '@example.test';

        $this->assertSame(3, $this->remaining($email));
        $this->assertInstanceOf(
            WP_Error::class,
            $this->guard()->consumeEmailQuota($email),
            'the fourth attempt in the window is refused'
        );
    }
}
