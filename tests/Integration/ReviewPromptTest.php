<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Dashboard\ReviewPrompt;
use Gratora\Donations\Donation;
use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use WP_REST_Request;

/**
 * The dashboard asks for a review once real donations have come in, asks only
 * the people who manage the plugin, and takes their answer as final.
 */
final class ReviewPromptTest extends IntegrationTestCase
{
    private int $seq = 0;

    public function test_it_waits_for_the_first_five_paid_donations(): void
    {
        $this->donations(ReviewPrompt::AFTER_DONATIONS - 1);
        $this->assertFalse($this->dashboard()['review_prompt']);

        $this->donations(1);
        $this->assertTrue($this->dashboard()['review_prompt']);
    }

    public function test_test_and_unpaid_donations_do_not_count(): void
    {
        $this->donations(ReviewPrompt::AFTER_DONATIONS - 1);
        $this->donations(3, ['is_test' => true]);
        $this->donations(3, ['status' => 'pending']);

        $this->assertFalse($this->dashboard()['review_prompt']);
    }

    /** @return array<string, array{0:string}> */
    public function finalAnswers(): array
    {
        return ['a review' => ['reviewed'], 'a refusal' => ['never']];
    }

    /** @dataProvider finalAnswers */
    public function test_an_answer_ends_it_for_that_person_only(string $answer): void
    {
        $this->donations(ReviewPrompt::AFTER_DONATIONS);

        $this->answer($answer);
        $this->assertFalse($this->dashboard()['review_prompt']);

        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));
        $this->assertTrue($this->dashboard()['review_prompt'], 'A colleague has not been asked yet.');
    }

    public function test_later_postpones_it_for_a_month(): void
    {
        $this->donations(ReviewPrompt::AFTER_DONATIONS);

        $this->answer('later');
        $this->assertFalse($this->dashboard()['review_prompt']);

        $until = (int) substr((string) get_user_meta(get_current_user_id(), 'gratora_review_prompt', true), 6);
        $this->assertEqualsWithDelta(time() + 30 * DAY_IN_SECONDS, $until, 5);

        update_user_meta(get_current_user_id(), 'gratora_review_prompt', 'later:' . (time() - 1));
        $this->assertTrue($this->dashboard()['review_prompt']);
    }

    public function test_someone_who_only_reads_the_reports_is_not_asked(): void
    {
        $this->donations(ReviewPrompt::AFTER_DONATIONS);
        $reader = self::factory()->user->create(['role' => 'editor']);
        get_userdata($reader)->add_cap('gratora_view_reports');
        wp_set_current_user($reader);

        $this->assertFalse($this->dashboard()['review_prompt']);
    }

    public function test_an_answer_outside_the_three_is_refused(): void
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/admin/me/review-prompt');
        $request->set_param('answer', 'five-stars');

        $this->assertSame(400, rest_do_request($request)->get_status());
    }

    /** @return array<string, mixed> */
    private function dashboard(): array
    {
        $request = new WP_REST_Request('GET', '/gratora/v1/admin/dashboard');
        $request->set_param('include', '');

        return rest_do_request($request)->get_data();
    }

    private function answer(string $answer): void
    {
        $request = new WP_REST_Request('POST', '/gratora/v1/admin/me/review-prompt');
        $request->set_param('answer', $answer);

        $this->assertSame(200, rest_do_request($request)->get_status());
    }

    /** @param array<string, mixed> $with */
    private function donations(int $count, array $with = []): void
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate('review-prompt@example.test', ['first_name' => 'Ada']);

        for ($i = 0; $i < $count; $i++) {
            $now = gmdate('Y-m-d H:i:s');
            $d   = Donation::make();
            $d->reference         = 'DN-REVIEW-' . ++$this->seq;
            $d->donor_id          = (int) $donor->id;
            $d->amount_cents      = 2500;
            $d->net_cents         = 2500;
            $d->currency          = 'USD';
            $d->base_amount_cents = 2500;
            $d->base_currency     = 'USD';
            $d->fx_rate           = '1.00000000';
            $d->gateway           = 'offline';
            $d->status            = (string) ($with['status'] ?? 'paid');
            $d->is_test           = (bool) ($with['is_test'] ?? false);
            $d->paid_at           = $now;
            $d->created_at        = $now;
            $d->updated_at        = $now;
            $d->save();
        }
    }
}
