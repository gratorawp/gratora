<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Closure;
use Gratora\Donations\AntiSpamGuard;
use Gratora\Donations\SubmissionProof;
use Gratora\Foundation\Plugin;
use WP_Error;
use WP_REST_Request;

/**
 * The seam that lets something other than core's own form token vouch for a
 * submission.
 *
 * It is a seam onto the one gate standing between the open internet and a
 * donation row, so what it must not do is the whole of what is pinned here: it
 * must not accept anything but core's own final class, must not run before the
 * origin check or the per-IP budget, and must not exist at all for a site with
 * nothing listening.
 */
final class SubmissionProofSeamTest extends IntegrationTestCase
{
    private const HOOK = 'gratora.donation.submission_proof';

    private ?string $remoteAddr = null;
    private ?string $origin     = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->remoteAddr = $_SERVER['REMOTE_ADDR'] ?? null;
        $this->origin     = $_SERVER['HTTP_ORIGIN'] ?? null;

        // Quotas are counted per caller, so every test starts with its own ten.
        $_SERVER['REMOTE_ADDR'] = $this->freshIp();
        unset($_SERVER['HTTP_ORIGIN']);
    }

    protected function tearDown(): void
    {
        remove_all_filters(self::HOOK);

        foreach (['REMOTE_ADDR' => $this->remoteAddr, 'HTTP_ORIGIN' => $this->origin] as $key => $was) {
            if ($was === null) {
                unset($_SERVER[$key]);
            } else {
                $_SERVER[$key] = $was;
            }
        }

        parent::tearDown();
    }

    private function freshIp(): string
    {
        return '198.51.' . random_int(1, 254) . '.' . random_int(2, 254);
    }

    private function guard(): AntiSpamGuard
    {
        return Plugin::instance()->container->get(AntiSpamGuard::class);
    }

    /** @param array<string,mixed> $overrides */
    private function post(array $overrides): \WP_REST_Response
    {
        $req = new WP_REST_Request('POST', '/gratora/v1/donations');
        $req->set_header('content-type', 'application/json');
        $req->set_body((string) wp_json_encode(array_merge([
            'email'        => 'proof-' . uniqid() . '@example.test',
            'amount_cents' => 2500,
            'currency'     => 'USD',
            'gateway'      => 'offline',
            'profile'      => ['first_name' => 'Pro', 'last_name' => 'Of'],
            '_ft'          => 'not-a-token',
        ], $overrides)));

        return rest_do_request($req);
    }

    private function listen(Closure $answer): void
    {
        add_filter(self::HOOK, static fn ($proof, string $scheme, array $body) => $answer($proof, $scheme, $body), 10, 3);
    }

    private function accept(): void
    {
        $this->listen(static fn () => new SubmissionProof());
    }

    /** How many more submissions this caller's per-IP budget would allow. */
    private function remainingIpBudget(): int
    {
        $left = 0;
        for ($i = 0; $i < 40; $i++) {
            if ($this->guard()->consumeIpQuota() instanceof WP_Error) {
                return $left;
            }
            $left++;
        }

        return $left;
    }

    /**
     * @dataProvider notAProof
     */
    public function test_anything_but_the_class_leaves_the_refusal_standing(Closure $answer): void
    {
        $this->listen($answer);

        $res = $this->post(['_proof' => 'embed']);

        $this->assertSame(400, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertSame('gratora_invalid_submission', (string) ($res->get_data()['code'] ?? ''));
    }

    /** @return array<string,array{0:Closure}> */
    public static function notAProof(): array
    {
        return [
            'true'         => [static fn () => true],
            'null'         => [static fn () => null],
            'false'        => [static fn () => false],
            'empty string' => [static fn () => ''],
            'WP_Error'     => [static fn () => new WP_Error('addon_proof_failed', 'No.', ['status' => 400])],
        ];
    }

    public function test_the_class_accepts_a_submission_the_form_token_refused(): void
    {
        $this->accept();

        $res = $this->post(['_proof' => 'embed']);

        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));
    }

    public function test_a_proof_cannot_substitute_the_origin_check(): void
    {
        $this->accept();
        $_SERVER['HTTP_ORIGIN'] = 'https://partner.example';

        $res = $this->post(['_proof' => 'embed']);

        $this->assertSame(403, $res->get_status(), (string) wp_json_encode($res->get_data()));
    }

    public function test_an_accepted_proof_still_spends_the_ip_budget(): void
    {
        $this->accept();

        $this->assertSame(201, $this->post(['_proof' => 'embed'])->get_status());
        $spent = $this->remainingIpBudget();

        $_SERVER['REMOTE_ADDR'] = $this->freshIp();
        $untouched = $this->remainingIpBudget();

        $this->assertSame($untouched - 1, $spent, 'the accepted submission spent one of this caller\'s own allowance');
    }

    /**
     * The listener resolves the scheme against the database and every key such
     * a scheme carries is public, so a garbage proof in a loop has to cost the
     * caller something.
     */
    public function test_a_refused_proof_still_spends_the_ip_budget(): void
    {
        $this->listen(static fn () => null);

        $this->assertSame(400, $this->post(['_proof' => 'embed'])->get_status());
        $spent = $this->remainingIpBudget();

        $_SERVER['REMOTE_ADDR'] = $this->freshIp();
        $untouched = $this->remainingIpBudget();

        $this->assertSame($untouched - 1, $spent);
    }

    /**
     * @dataProvider proofValues
     */
    public function test_with_nothing_listening_a_proof_changes_nothing(string $proof): void
    {
        $body = ['email' => 'silent-' . uniqid() . '@example.test'];

        $bare     = $this->post($body);
        $carrying = $this->post($body + ['_proof' => $proof]);

        $this->assertSame($bare->get_status(), $carrying->get_status());
        $this->assertSame($bare->get_data(), $carrying->get_data());
        $this->assertSame(400, $bare->get_status(), (string) wp_json_encode($bare->get_data()));
    }

    /** @return array<string,array{0:string}> */
    public static function proofValues(): array
    {
        return [
            'the word core will use' => ['embed'],
            'a scheme nobody serves' => ['whatever'],
            'an empty scheme'        => [''],
        ];
    }

    public function test_a_valid_form_token_never_consults_the_seam(): void
    {
        $asked = false;
        $this->listen(static function ($proof) use (&$asked) {
            $asked = true;

            return $proof;
        });

        $res = $this->post(['_ft' => $this->guard()->mintFormToken(0), '_proof' => 'embed']);

        $this->assertSame(201, $res->get_status(), (string) wp_json_encode($res->get_data()));
        $this->assertFalse($asked, 'core resolved the submission itself and had no question to ask');
    }
}
