<?php

declare(strict_types=1);

namespace Dono\Mail;

use Dono\Settings\SettingsService;

/**
 * Wraps wp_mail with configured From, Reply-To, and BCC, and per-template
 * subject/body from dono_email_settings.
 *
 * @version 1.0.0
 */
final class Mailer
{
    /**
     * Templates that carry a login credential and must never be BCC'd to the
     * admin (the magic link is a sign-in URL; a BCC'd copy = account takeover).
     */
    private const NO_ADMIN_BCC = ['magic_link'];

    public function __construct(private SettingsService $settings)
    {
    }

    /**
     * @param array<string,string|int> $tokens
     * @param list<string> $attachments
     * @return bool false when the template is disabled or absent; otherwise wp_mail's result.
     */
    public function sendTemplate(string $key, string $to, array $tokens, array $attachments = []): bool
    {
        $cfg = $this->settings->get('email');
        $template = $cfg['templates'][$key] ?? null;
        if (! is_array($template) || empty($template['enabled'])) {
            return false;
        }

        $subject = $this->interpolate((string) ($template['subject'] ?? ''), $tokens);
        $body    = $this->interpolate((string) ($template['body']    ?? ''), $tokens);

        return $this->sendRaw($to, $subject, $body, [
            'attachments'  => $attachments,
            'no_admin_bcc' => in_array($key, self::NO_ADMIN_BCC, true),
        ]);
    }

    /**
     * @param array{
     *     html?:bool,
     *     headers?:list<string>,
     *     attachments?:list<string>,
     *     reply_to?:string,
     *     bcc?:list<string>,
     * } $opts
     */
    public function sendRaw(string $to, string $subject, string $body, array $opts = []): bool
    {
        $cfg = $this->settings->get('email');

        // Header values run through stripHeaderValue to block CRLF injection.
        $to        = $this->stripHeaderValue($to);
        $subject   = $this->stripHeaderValue($subject);
        $fromName  = $this->stripHeaderValue(trim((string) ($cfg['from_name']  ?? '')));
        $fromEmail = $this->stripHeaderValue(trim((string) ($cfg['from_email'] ?? '')));
        $replyTo   = $this->stripHeaderValue(trim((string) ($opts['reply_to'] ?? $cfg['reply_to'] ?? '')));
        $bcc       = $opts['bcc'] ?? [];
        if (! empty($cfg['bcc_admin']) && empty($opts['bcc']) && empty($opts['no_admin_bcc'])) {
            $adminEmail = (string) get_option('admin_email');
            if ($adminEmail !== '') $bcc[] = $adminEmail;
        }

        $headers = is_array($opts['headers'] ?? null) ? $opts['headers'] : [];
        if (! empty($opts['html'])) {
            $headers[] = 'Content-Type: text/html; charset=UTF-8';
        } else {
            // Plain text is the default, and nothing decodes entities in it.
            // Names arrive already HTML-encoded, both ours (sanitize_text_field
            // turns < into &lt;) and WordPress's own: sanitize_option() stores
            // blogname esc_html'd, so a site called "Cats & Dogs Trust" signed
            // off every email as "Cats &amp; Dogs Trust". Decode for the plain
            // text body only; in an HTML body the entities are already right.
            $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        if ($replyTo !== '' && is_email($replyTo)) {
            $headers[] = 'Reply-To: ' . $replyTo;
        }
        foreach ($bcc as $addr) {
            if (! is_string($addr) || $addr === '') continue;
            $addr = $this->stripHeaderValue($addr);
            if (is_email($addr)) $headers[] = 'Bcc: ' . $addr;
        }

        $applyFrom = $fromEmail !== '' && is_email($fromEmail);
        $fromEmailFilter = null;
        $fromNameFilter  = null;
        if ($applyFrom) {
            $fromEmailFilter = static fn () => $fromEmail;
            add_filter('wp_mail_from', $fromEmailFilter, 99);
            if ($fromName !== '') {
                $fromNameFilter = static fn () => $fromName;
                add_filter('wp_mail_from_name', $fromNameFilter, 99);
            }
        }

        try {
            return (bool) wp_mail(
                $to,
                $subject,
                $body,
                $headers,
                $opts['attachments'] ?? []
            );
        } finally {
            if ($fromEmailFilter) remove_filter('wp_mail_from', $fromEmailFilter, 99);
            if ($fromNameFilter)  remove_filter('wp_mail_from_name', $fromNameFilter, 99);
        }
    }

    /**
     * Strip CR / LF / control chars so a value is safe in an email header.
     */
    private function stripHeaderValue(string $value): string
    {
        // A subject is not HTML, and names reach it already HTML-encoded, so a
        // team called Team <3 & "Q" arrived as Team &lt;3 &amp; &quot;Q&quot;
        // in the inbox. On a page the browser decodes it and nobody notices;
        // in a header nothing does.
        //
        // Decode before stripping, never after: an encoded newline such as
        // &#13;&#10; would otherwise survive the strip and then decode into a
        // real CRLF, which is header injection.
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        $stripped = preg_replace('/[\r\n\0\x0B]/', '', $value);
        return is_string($stripped) ? trim($stripped) : '';
    }

    /**
     * @param array<string,string|int> $tokens
     */
    private function interpolate(string $source, array $tokens): string
    {
        if ($source === '' || empty($tokens)) return $source;
        $map = [];
        foreach ($tokens as $k => $v) {
            $map['{' . $k . '}'] = (string) $v;
        }
        return strtr($source, $map);
    }
}
