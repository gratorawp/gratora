<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\DonorService;
use Gratora\Foundation\Plugin;
use Gratora\Gateways\GatewayManager;
use Gratora\Gateways\Sandbox\SandboxGateway;
use Gratora\Recurring\RecurringPlan;
use Gratora\Recurring\RecurringPlanActions;
use Gratora\Recurring\RecurringPlanChange;

/**
 * The admin dialog offers "Notify donor" on a schedule change, defaulted on,
 * and the route answers notified: true. A donor moved from monthly to weekly
 * is agreeing to give four times as much, so being told is the point.
 */
final class IntervalChangeEmailTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $c       = Plugin::instance()->container;
        $manager = $c->get(GatewayManager::class);
        if ($manager->get('sandbox') === null) {
            $manager->register(new SandboxGateway(
                $c->get(\Gratora\Foundation\Time\Clock::class),
                $c->get(\Gratora\Recurring\RecurringPlanRepository::class)
            ));
        }
    }

    private function planFor(string $email): RecurringPlan
    {
        $donor = Plugin::instance()->container->get(DonorService::class)
            ->findOrCreate($email, ['first_name' => 'Ada', 'last_name' => 'Okoye']);

        $now = gmdate('Y-m-d H:i:s');
        $p = RecurringPlan::make();
        $p->donor_id                = (int) $donor->id;
        $p->gateway                 = 'sandbox';
        $p->gateway_subscription_id = 'sub_' . uniqid();
        $p->amount_cents            = 2500;
        $p->currency                = 'EUR';
        $p->interval_unit           = 'month';
        $p->interval_count          = 1;
        $p->status                  = 'active';
        $p->is_test                 = false;
        $p->next_payment_at         = gmdate('Y-m-d H:i:s', time() + 10 * DAY_IN_SECONDS);
        $p->created_at              = $now;
        $p->updated_at              = $now;
        $p->save();

        return $p;
    }

    private function change(bool $notify): RecurringPlanChange
    {
        return RecurringPlanChange::byAdmin('change_interval', $notify);
    }

    private function actions(): RecurringPlanActions
    {
        return Plugin::instance()->container->get(RecurringPlanActions::class);
    }

    public function test_the_donor_is_told_what_their_schedule_became(): void
    {
        $plan  = $this->planFor('ada@example.test');
        $mails = $this->captureMails();

        $this->actions()->changeInterval($plan, 'weekly', $this->change(true));

        $this->assertCount(1, $mails, 'the switch says the donor is notified, so something has to be sent');

        $mail = $mails[0];
        $this->assertContains('ada@example.test', (array) $mail['to']);
        $this->assertStringContainsString('schedule', strtolower((string) $mail['subject']));

        $body = (string) $mail['message'];
        $this->assertStringContainsString('every week', $body, 'the donor is told the schedule they are on now');
        $this->assertStringContainsString('every month', $body, 'and the one they were on');
        $this->assertStringNotContainsString('{', $body, 'every merge tag resolved');
    }

    public function test_a_donor_changing_their_own_is_not_emailed_about_it(): void
    {
        $plan  = $this->planFor('quiet@example.test');
        $mails = $this->captureMails();

        $this->actions()->changeInterval($plan, 'yearly', $this->change(false));

        $this->assertCount(0, $mails, 'they are looking at the screen that did it');
    }

    public function test_the_template_is_one_the_admin_can_find_and_edit(): void
    {
        // A template that is sent but undescribed is invisible in the editor,
        // so nobody can change wording that goes out over their name.
        $descriptors = (string) file_get_contents(
            __DIR__ . '/../../assets/admin/_shared/emailTemplates.js'
        );

        $this->assertStringContainsString("'recurring_interval_changed'", $descriptors);

        $templates = (array) (Plugin::instance()->container
            ->get(\Gratora\Settings\SettingsService::class)->get('email')['templates'] ?? []);

        $this->assertArrayHasKey('recurring_interval_changed', $templates);
        $this->assertTrue((bool) $templates['recurring_interval_changed']['enabled']);
    }
}
