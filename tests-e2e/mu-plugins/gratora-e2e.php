<?php

// The Add-ons screen asks gratora.net for its lists. A test site shows the built-in one.
add_filter('gratora.addons.remote', '__return_false');

// Nor does a test site tell gratora.net why it was switched off, or anything else.
add_filter('pre_http_request', static function ($pre, array $args, string $url) {
    return str_starts_with($url, 'https://gratora.net/')
        ? new WP_Error('gratora_e2e', 'A test site does not call gratora.net.')
        : $pre;
}, 10, 3);
