<?php

// The Add-ons screen asks gratora.net for its lists. A test site shows the built-in one.
add_filter('gratora.addons.remote', '__return_false');

// Nor does a test site tell gratora.net why it was switched off, or anything else. The reason it would
// have passed on is named in a header, where a browser test can read that the plugin got as far as sending it.
add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    if (! str_starts_with($url, 'https://gratora.net/')) {
        return $pre;
    }

    if (str_ends_with($url, '/deactivations') && ! headers_sent()) {
        $answer = json_decode((string) ($args['body'] ?? ''), true);
        header('X-Gratora-E2E-Passed-On: ' . rawurlencode((string) ($answer['reason'] ?? '')));
    }

    return new WP_Error('gratora_e2e', 'A test site does not call gratora.net.');
}, 10, 3);

// A browser test reads this before it lets the plugin try a call a site without this file would really make.
add_action('admin_init', static function (): void {
    if (! headers_sent()) {
        header('X-Gratora-E2E: calls-to-gratora-refused');
    }
});

// And asks for the deactivation dialog as a site that switched its question off shows it.
add_filter('gratora.deactivation.ask_why', static fn (bool $asks): bool => isset($_GET['gratora_e2e_no_question']) ? false : $asks);
