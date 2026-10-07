<?php

declare(strict_types=1);

namespace Gratora\Admin;

use DateTimeImmutable;
use Exception;
use Gratora\Core\Activator;
use Gratora\Dashboard\FirstRun;
use Gratora\Foundation\Time\Clock;
use Throwable;

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

    /** @unreleased */
    public const COMMENT_LENGTH = 500;

    private const VERSION  = 1;
    private const MAX_DAYS = 36500;

    /** @unreleased */
    public function __construct(private FirstRun $firstRun, private Clock $clock)
    {
    }

    /**
     * Whether the dialog asks at all. The network's Plugins screen does not:
     * the setup and the days sent are one site's, and a network holds many.
     *
     * @unreleased
     */
    public static function asks(): bool
    {
        /**
         * Whether the deactivation dialog asks why and may tell gratora.net.
         *
         * @unreleased
         *
         * @param bool $asks
         */
        return ! is_network_admin() && (bool) apply_filters('gratora.deactivation.ask_why', true);
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
        if ($asked === null || ! self::asks()) {
            return;
        }

        // WordPress would name the site's address in the user agent.
        wp_safe_remote_post(self::ENDPOINT, [
            'timeout'             => 3,
            'redirection'         => 0,
            'user-agent'          => 'Gratora/' . GRATORA_VERSION,
            'limit_response_size' => 1024,
            'headers'             => ['Content-Type' => 'application/json'],
            // Unescaped, or five hundred emoji outgrow what the store accepts.
            'body'                => (string) wp_json_encode([
                'version'     => self::VERSION,
                'reason'      => $reason,
                'comment'     => $asked['prompt'] === '' ? '' : self::words($comment),
                'gratora'     => GRATORA_VERSION,
                'wordpress'   => wp_get_wp_version(),
                'php'         => PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
                'days_active' => $this->daysSinceFirstOn(),
                'setup'       => $this->setup(),
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
    }

    /**
     * The words as typed. They are text for the store to escape where it
     * shows them, so nothing that reads like markup is taken out here.
     *
     * @unreleased
     */
    private static function words(string $typed): string
    {
        $text = str_replace(["\r\n", "\r"], "\n", wp_scrub_utf8($typed));
        $text = (string) preg_replace('/[^\P{Cc}\n\t]/u', '', $text);

        return mb_substr(trim($text), 0, self::COMMENT_LENGTH, 'UTF-8');
    }

    /**
     * A site with a broken table is the one whose answer matters most, so a
     * failure here leaves the steps unknown and the answer still goes.
     *
     * @unreleased
     *
     * @return array{page:bool,test_donation:bool,payments:bool,donation:bool}|null
     */
    private function setup(): ?array
    {
        try {
            return $this->firstRun->progress();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Whole days since Gratora was first switched on, which is not the time
     * it spent on: the moment is written once and kept through later switches.
     *
     * @unreleased
     */
    private function daysSinceFirstOn(): int
    {
        $since = get_option(Activator::OPT_ACTIVATED_AT, false);
        if (! is_string($since) || $since === '') {
            return 0;
        }

        try {
            $first = new DateTimeImmutable($since);
        } catch (Exception) {
            return 0;
        }

        $days = (int) floor(($this->clock->now()->getTimestamp() - $first->getTimestamp()) / DAY_IN_SECONDS);

        return $days < 0 || $days > self::MAX_DAYS ? 0 : $days;
    }
}
