<?php

declare(strict_types=1);

namespace Dono\Tests\Integration;

use Dono\Admin\TestModeBadge;
use Dono\Forms\Form;

/**
 * Test mode is otherwise invisible: the donations list fills with rows, the
 * totals move, and nothing says the card was never charged.
 */
final class TestModeBadgeTest extends IntegrationTestCase
{
    private function bar(): \WP_Admin_Bar
    {
        require_once ABSPATH . 'wp-includes/class-wp-admin-bar.php';

        $bar = new \WP_Admin_Bar();
        // top-secondary has to exist or add_node parents onto nothing.
        $bar->add_group(['id' => 'top-secondary']);

        (new TestModeBadge())->addNode($bar);

        return $bar;
    }

    private function title(): ?string
    {
        $node = $this->bar()->get_node('dono-test-mode');

        return $node === null ? null : wp_strip_all_tags((string) $node->title);
    }

    private function publishedForm(bool $testMode): Form
    {
        $f = Form::make();
        $f->title      = 'Form ' . uniqid();
        $f->slug       = 'form-' . uniqid();
        $f->status     = 'published';
        $f->blocks     = '';
        $f->settings   = ['test_mode' => $testMode];
        $f->created_at = gmdate('Y-m-d H:i:s');
        $f->updated_at = gmdate('Y-m-d H:i:s');
        $f->save();

        return $f;
    }

    private function orgWide(bool $on): void
    {
        $cfg = get_option('dono_gateway_config', []);
        $cfg = is_array($cfg) ? $cfg : [];
        $cfg['test_mode'] = $on;
        update_option('dono_gateway_config', $cfg);
    }

    public function test_nothing_shows_when_every_donation_is_real(): void
    {
        $this->orgWide(false);
        $this->publishedForm(false);

        $this->assertNull($this->title(), 'a live site carries no badge');
    }

    public function test_the_org_wide_switch_shows_a_badge(): void
    {
        $this->orgWide(true);

        $this->assertSame('Dono Test Mode Active', $this->title());
    }

    public function test_a_single_form_left_in_test_mode_is_called_out_on_its_own(): void
    {
        // The dangerous case: the site is live, so nothing warns you, and one
        // form quietly takes no money.
        $this->orgWide(false);
        $this->publishedForm(true);

        $this->assertSame('1 Dono Form in Test Mode', $this->title());
    }

    /**
     * The flag is read as text, not as JSON, because MariaDB has no JSON type
     * and rejects CAST(x AS JSON) outright. This runs on wp_head, so getting
     * it wrong takes the front end down rather than the badge.
     *
     * Counting the string forms too is deliberate: whichever shape a writer
     * stores, a form in test mode should be reported as one.
     */
    public function test_every_shape_of_the_flag_is_counted(): void
    {
        foreach ([true, 1, 'true', '1'] as $on) {
            $f = $this->publishedForm(false);
            $f->settings = ['test_mode' => $on];
            $f->save();
        }
        foreach ([false, 0, 'false', '0'] as $off) {
            $f = $this->publishedForm(false);
            $f->settings = ['test_mode' => $off];
            $f->save();
        }

        $this->assertStringContainsString(
            '4',
            (string) $this->title(),
            'the four forms that are on are counted, whatever shape the flag is stored in'
        );
    }

    public function test_the_count_is_of_forms_not_of_anything_else(): void
    {
        $this->orgWide(false);
        $this->publishedForm(true);
        $this->publishedForm(true);
        $this->publishedForm(false);

        $this->assertSame('2 Dono Forms in Test Mode', $this->title());
    }

    public function test_a_draft_form_in_test_mode_is_not_worth_warning_about(): void
    {
        $this->orgWide(false);

        $draft = $this->publishedForm(true);
        Form::query()->where('id', (int) $draft->id)->update(['status' => 'draft']);

        $this->assertNull($this->title(), 'an unpublished form takes nothing either way');
    }

    public function test_the_org_wide_switch_outranks_the_per_form_count(): void
    {
        $this->orgWide(true);
        $this->publishedForm(true);

        // Both are true, but "some forms" understates a site where nothing is real.
        $this->assertSame('Dono Test Mode Active', $this->title());
    }

    /** The badge is the shortest route to the switch, so it has to land on it. */
    public function test_the_badge_links_to_the_tab_that_holds_the_switch(): void
    {
        $this->orgWide(true);

        $href = (string) $this->bar()->get_node('dono-test-mode')->href;

        $this->assertStringContainsString('page=dono-settings', $href);
        $this->assertStringContainsString('tab=gateways', $href);
    }

    public function test_the_badge_carries_an_icon_that_screen_readers_skip(): void
    {
        $this->orgWide(true);

        $title = (string) $this->bar()->get_node('dono-test-mode')->title;

        $this->assertStringContainsString('<svg', $title);
        // The words already say it; the icon repeating them is just noise.
        $this->assertStringContainsString('aria-hidden="true"', $title);
    }

    public function test_someone_who_cannot_see_donations_is_not_shown_the_till(): void
    {
        $this->orgWide(true);

        // Denied through user_has_cap rather than by picking a role: the
        // role-to-capability mapping is itself a Dono setting that other
        // suites rewrite, so "an editor" is not reliably unprivileged.
        // manage_options too: userCan() treats a full admin as holding every
        // Dono capability, so dropping the specific one proves nothing.
        $deny = static function (array $caps): array {
            unset($caps['dono_view_donations'], $caps['manage_options']);
            return $caps;
        };
        add_filter('user_has_cap', $deny, 99);

        try {
            $this->assertNull($this->title());
        } finally {
            remove_filter('user_has_cap', $deny, 99);
        }
    }
}
