<?php

declare(strict_types=1);

namespace Gratora\Admin;

use DateTimeImmutable;
use Exception;
use Gratora\Core\Activator;
use Gratora\Dashboard\FirstRun;
use Gratora\Foundation\Time\Clock;

/**
 * Why someone is switching Gratora off, told to gratora.net when they choose
 * to say.
 *
 * @unreleased
 */
final class DeactivationSurvey
{
    /** @unreleased */
    public const ENDPOINT = 'https://gratora.net/wp-json/gratora-license/v1/deactivations';

    private const VERSION        = 1;
    private const COMMENT_LENGTH = 500;

    /** @unreleased */
    public function __construct(private FirstRun $firstRun, private Clock $clock)
    {
    }

    /**
     * A reason with no prompt asks for nothing more.
     *
     * @unreleased
     *
     * @return array<string,array{label:string,prompt:string}>
     */
    public static function reasons(): array
    {
        return [
            'temporary' => [
                'label'  => __('Only for a while, I am testing or fixing something', 'gratora-donation-platform'),
                'prompt' => '',
            ],
            'setup' => [
                'label'  => __('I could not get it set up', 'gratora-donation-platform'),
                'prompt' => __('Where did you get stuck?', 'gratora-donation-platform'),
            ],
            'broken' => [
                'label'  => __('Something did not work', 'gratora-donation-platform'),
                'prompt' => __('What went wrong?', 'gratora-donation-platform'),
            ],
            'missing' => [
                'label'  => __('It is missing something I need', 'gratora-donation-platform'),
                'prompt' => __('What is missing?', 'gratora-donation-platform'),
            ],
            'another_plugin' => [
                'label'  => __('I am going with another plugin', 'gratora-donation-platform'),
                'prompt' => __('Which one, and what does it do better?', 'gratora-donation-platform'),
            ],
            'not_needed' => [
                'label'  => __('I no longer need it', 'gratora-donation-platform'),
                'prompt' => '',
            ],
            'other' => [
                'label'  => __('Something else', 'gratora-donation-platform'),
                'prompt' => __('What is the reason?', 'gratora-donation-platform'),
            ],
        ];
    }

    /** @unreleased */
    public function send(string $reason, string $comment): void
    {
        $asked = self::reasons()[$reason] ?? null;
        if ($asked === null) {
            return;
        }

        // WordPress would name the site's address in the user agent.
        wp_safe_remote_post(self::ENDPOINT, [
            'timeout'     => 3,
            'redirection' => 0,
            'user-agent'  => 'Gratora/' . GRATORA_VERSION,
            'headers'     => ['Content-Type' => 'application/json'],
            'body'        => (string) wp_json_encode([
                'version'     => self::VERSION,
                'reason'      => $reason,
                'comment'     => $asked['prompt'] === '' ? '' : mb_substr($comment, 0, self::COMMENT_LENGTH),
                'gratora'     => GRATORA_VERSION,
                'wordpress'   => wp_get_wp_version(),
                'php'         => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'days_active' => $this->daysActive(),
                'setup'       => $this->firstRun->progress(),
            ]),
        ]);
    }

    private function daysActive(): int
    {
        $since = get_option(Activator::OPT_ACTIVATED_AT, false);
        if (! is_string($since) || $since === '') {
            return 0;
        }

        try {
            $activated = new DateTimeImmutable($since);
        } catch (Exception) {
            return 0;
        }

        return max(0, (int) floor(($this->clock->now()->getTimestamp() - $activated->getTimestamp()) / DAY_IN_SECONDS));
    }
}
