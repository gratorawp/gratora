<?php

declare(strict_types=1);

namespace Gratora\Tests\Integration;

use Gratora\Donors\ConsentService;
use Gratora\Foundation\Plugin;
use Gratora\Settings\SettingsService;
use InvalidArgumentException;

/**
 * The key is what every reader keys on and what the audit row records. A
 * purpose saved without one is dropped by the picker, the live form, the
 * portal and the donor profile while the Consents panel still lists its card
 * and reports nothing. Two purposes sharing a key collapse to one checkbox and
 * both read from the same audit record.
 */
final class ConsentPurposeKeysTest extends IntegrationTestCase
{
    private function settings(): SettingsService
    {
        return Plugin::instance()->container->get(SettingsService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function purpose(string $key, string $label): array
    {
        return ['key' => $key, 'label' => $label, 'required' => false, 'default' => false, 'version' => 1];
    }

    public function test_a_purpose_without_a_key_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->update('consents', ['purposes' => [$this->purpose('', 'Newsletter')]]);
    }

    public function test_two_purposes_cannot_share_a_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->settings()->update('consents', ['purposes' => [
            $this->purpose('newsletter', 'Newsletter'),
            $this->purpose('newsletter', 'Updates'),
        ]]);
    }

    public function test_a_refused_save_leaves_the_registry_as_it_was(): void
    {
        $this->settings()->update('consents', ['purposes' => [$this->purpose('newsletter', 'Newsletter')]]);

        try {
            $this->settings()->update('consents', ['purposes' => [$this->purpose('', 'Newsletter')]]);
            $this->fail('a purpose without a key should be refused');
        } catch (InvalidArgumentException $e) {
            $purposes = Plugin::instance()->container->get(ConsentService::class)->purposes();

            $this->assertCount(1, $purposes);
            $this->assertSame('newsletter', (string) array_values($purposes)[0]['key']);
        }
    }

    public function test_two_distinct_purposes_still_save(): void
    {
        $this->settings()->update('consents', ['purposes' => [
            $this->purpose('newsletter', 'Newsletter'),
            $this->purpose('post', 'Post'),
        ]]);

        $this->assertCount(2, Plugin::instance()->container->get(ConsentService::class)->purposes());
    }
}
