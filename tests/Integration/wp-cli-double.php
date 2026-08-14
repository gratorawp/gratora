<?php
/**
 * A stand-in for the WP-CLI runtime, so the seeding commands can be driven
 * from the suite. WP-CLI is not loaded under PHPUnit and the commands are not
 * worth testing through their output, only through what they refuse to do.
 *
 * error() and confirm() throw, which is how the real ones end a command:
 * WP_CLI::error halts with an ExitException, and confirm() halts unless the
 * operator says yes. Anything a test asserts happened after one of those has
 * happened on a live site too.
 *
 * Not autoloaded: the class name has no namespace, so a test requires this
 * file for itself.
 */

declare(strict_types=1);

if (! class_exists('DonoCliHalt')) {
    class DonoCliHalt extends \RuntimeException
    {
    }
}

if (! class_exists('WP_CLI')) {
    class WP_CLI
    {
        /** @var list<string> */
        public static array $log = [];

        public static function error(string $message): void
        {
            throw new DonoCliHalt('error: ' . $message);
        }

        /** @param array<string,mixed> $assoc */
        public static function confirm(string $question, array $assoc = []): void
        {
            if (! empty($assoc['yes'])) {
                return;
            }

            throw new DonoCliHalt('confirm: ' . $question);
        }

        public static function log(string $message): void
        {
            self::$log[] = $message;
        }

        public static function success(string $message): void
        {
            self::$log[] = $message;
        }

        public static function warning(string $message): void
        {
            self::$log[] = $message;
        }
    }
}
