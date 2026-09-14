<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Foundation\Plugin;
use Gratora\Settings\SettingsService;
use WP_REST_Request;

/**
 * The Setup checklist's receipt warning lists what is missing and then appended
 * a fixed sentence saying receipts print those very details, so the card read
 * "Receipts do not carry your organization name. Receipts do not carry a postal
 * address. Receipts print your organization details at the top."
 *
 * The last sentence is there to say why the missing details matter. In the
 * present tense it says they are not missing.
 *
 * The gap this leaves: a future rewording could contradict the finding in words
 * these assertions do not know about. What is pinned is that the check's own
 * verdict and its explanation agree about whether the details are there.
 */
final class AWarningDoesNotDenyItselfTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    /**
     * Read through the route, which is what the screen reads, and is also the
     * only way in: ReadinessService is built inside its controller rather than
     * bound in the container.
     *
     * @return array<string,mixed>|null the org-identity check, whatever its state
     */
    private function orgIdentityCheck(): ?array
    {
        wp_set_current_user(self::factory()->user->create(['role' => 'administrator']));

        $res = rest_do_request(new WP_REST_Request('GET', '/gratora/v1/admin/readiness'));
        $this->assertSame(200, $res->get_status(), (string) wp_json_encode($res->get_data()));

        foreach ($this->flatten((array) $res->get_data()) as $check) {
            if (($check['id'] ?? '') === 'org-identity') {
                return $check;
            }
        }

        return null;
    }

    /**
     * Every associative array in the payload that looks like a check, wherever
     * the response happens to group them.
     *
     * @param array<array-key,mixed> $node
     * @return list<array<string,mixed>>
     */
    private function flatten(array $node): array
    {
        $out = [];

        foreach ($node as $value) {
            if (! is_array($value)) {
                continue;
            }
            if (isset($value['id'])) {
                $out[] = $value;
            }
            $out = array_merge($out, $this->flatten($value));
        }

        return $out;
    }

    public function test_the_warning_does_not_say_receipts_print_what_it_says_they_lack(): void
    {
        $this->settings()->update('org-profile', ['legal_name' => '', 'name' => '', 'address_lines' => []]);

        $check = $this->orgIdentityCheck();
        $this->assertNotNull($check, 'the org identity check is not in the report');

        $detail = (string) ($check['detail'] ?? '');

        $this->assertStringContainsString('do not carry', $detail, 'it still says what is missing');
        $this->assertStringNotContainsString(
            'Receipts print your organization details',
            $detail,
            'the explanation asserted the details are printed, which is what the finding denies'
        );
    }

    /** The reason the details matter survives, or the warning is a bare list. */
    public function test_it_still_says_why_the_details_matter(): void
    {
        $this->settings()->update('org-profile', ['legal_name' => '', 'name' => '', 'address_lines' => []]);

        $detail = (string) ($this->orgIdentityCheck()['detail'] ?? '');

        $this->assertStringContainsString('tax relief', $detail);
    }

    /** And a filled-in profile passes rather than warning at all. */
    public function test_a_filled_in_profile_passes(): void
    {
        $this->settings()->update('org-profile', [
            'legal_name'    => 'Wildwater Trust',
            'name'          => 'Wildwater Trust',
            'address_lines' => ['1 Riverbank Way'],
            'tax_id'        => '1234567',
        ]);

        $check = $this->orgIdentityCheck();

        $this->assertNotNull($check);
        $this->assertSame('pass', (string) ($check['status'] ?? ''), (string) wp_json_encode($check));
    }
}
