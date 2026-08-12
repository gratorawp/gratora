<?php

declare(strict_types=1);

namespace Dono\Gateways\Stripe;

use Dono\Foundation\Config\SystemSetting;
use Dono\Foundation\Hooks\HookProvider;
use RuntimeException;

/**
 * Apple Pay domain verification for the Stripe Payment Element.
 *
 * Google Pay needs nothing beyond being enabled on the Stripe account, but
 * Apple requires the domain to be verified before its button will render. That
 * means two things must both be true:
 *
 *   1. the site serves Apple's association file at
 *      /.well-known/apple-developer-merchantid-domain-association, and
 *   2. the domain is registered with Stripe, which fetches that file to verify.
 *
 * The file contents come from the Stripe dashboard and are pasted in by the
 * admin: they are not secret, but they do change, so Dono stores whatever the
 * admin provides rather than shipping a copy that would silently go stale.
 *
 * @since 1.0.0
 */
final class ApplePayDomain extends HookProvider
{
    public const WELL_KNOWN_PATH = '/.well-known/apple-developer-merchantid-domain-association';

    private const FILE_KEY   = 'apple_pay_domain_file';
    private const STATUS_KEY = 'apple_pay_domain_status';

    /** @since 1.0.0 */
    public function __construct(private StripeApi $api, private StripeAccount $account)
    {
    }

    /** @since 1.0.0 */
    protected function actions(): array
    {
        // Bound early and matched on the raw path: the file has to be reachable
        // whether or not WordPress owns a rewrite rule for .well-known, and
        // before any redirect or 404 handling can swallow it.
        return ['init' => 'maybeServeAssociationFile'];
    }

    /**
     * The domain Apple and Stripe will verify, taken from the site address.
     *
     * @since 1.0.0
     */
    public function domain(): string
    {
        return (string) (wp_parse_url(home_url(), PHP_URL_HOST) ?: '');
    }

    /** @since 1.0.0 */
    public function associationFile(): string
    {
        $stored = SystemSetting::read(self::FILE_KEY);
        return is_string($stored) ? $stored : '';
    }

    /** @since 1.0.0 */
    public function storeAssociationFile(string $contents): void
    {
        SystemSetting::write(self::FILE_KEY, trim($contents));
    }

    /** @since 1.0.0 */
    public function forgetAssociationFile(): void
    {
        SystemSetting::forget(self::FILE_KEY);
        SystemSetting::forget(self::STATUS_KEY);
    }

    /**
     * True when the file is in place, which is the precondition for Stripe's check.
     *
     * @since 1.0.0
     */
    public function isFileReady(): bool
    {
        return $this->associationFile() !== '';
    }

    /**
     * Serve the association file. Plain text, no theme, no trailing newline
     * games: Apple compares the body byte for byte.
     *
     * @since 1.0.0
     */
    public function maybeServeAssociationFile(): void
    {
        $body = $this->bodyForRequest((string) ($_SERVER['REQUEST_URI'] ?? ''));
        if ($body === null) {
            return;
        }

        if (! headers_sent()) {
            header('Content-Type: text/plain; charset=utf-8');
            header('Content-Length: ' . strlen($body));
            header('Cache-Control: public, max-age=3600');
        }
        echo $body; // phpcs:ignore WordPress.Security.EscapeOutput -- verbatim file body
        exit;
    }

    /**
     * What this request should be answered with, or null to let WordPress carry
     * on. Split out from the emit above so the whole decision is testable
     * without the exit.
     *
     * @since 1.0.0
     */
    public function bodyForRequest(string $requestUri): ?string
    {
        $path = (string) (wp_parse_url($requestUri, PHP_URL_PATH) ?: '');
        if (rtrim($path, '/') !== self::WELL_KNOWN_PATH) {
            return null;
        }

        // Nothing stored: stay out of the way so the host can still serve a
        // file placed on disk by other means.
        $file = $this->associationFile();
        return $file === '' ? null : $file;
    }

    /**
     * Register the domain with Stripe and record what Stripe reports back.
     * Named registerDomain, not register: HookProvider owns register().
     *
     * @return array{status:string,message:string} status is 'active',
     *         'inactive' or 'unknown'.
     * @throws RuntimeException when Stripe rejects the call outright.
     *
     * @since 1.0.0
     */
    public function registerDomain(bool $test): array
    {
        $domain = $this->domain();
        if ($domain === '') {
            throw new RuntimeException('Could not determine this site\'s domain.');
        }

        $this->account->useTestMode($test);

        $result = $this->api->post('/payment_method_domains', ['domain_name' => $domain]);

        return $this->recordStatus($test, $result);
    }

    /**
     * Re-check a domain Stripe already knows about.
     *
     * @since 1.0.0
     */
    public function refresh(bool $test): array
    {
        $this->account->useTestMode($test);
        $domain = $this->domain();

        $list = $this->api->get('/payment_method_domains?domain_name=' . rawurlencode($domain));
        $first = $list['data'][0] ?? null;

        if (! is_array($first)) {
            return ['status' => 'unknown', 'message' => __('This domain is not registered with Stripe yet.', 'dono-fundraising-platform')];
        }

        // Asking Stripe to validate again is what turns a freshly-served file
        // into an active status without waiting for its own retry.
        $id = (string) ($first['id'] ?? '');
        if ($id !== '') {
            try {
                $first = $this->api->post('/payment_method_domains/' . rawurlencode($id) . '/validate', []);
            } catch (RuntimeException $e) {
                // Keep the listed record; the status below still reports it.
            }
        }

        return $this->recordStatus($test, $first);
    }

    /**
     * @return array{status:string,message:string}
     *
     * @since 1.0.0
     */
    public function status(bool $test): array
    {
        $all = $this->storedStatus();
        $key = $test ? 'test' : 'live';
        $row = $all[$key] ?? null;

        return is_array($row)
            ? ['status' => (string) ($row['status'] ?? 'unknown'), 'message' => (string) ($row['message'] ?? '')]
            : ['status' => 'unknown', 'message' => ''];
    }

    /**
     * @param array<string,mixed> $domainObject
     * @return array{status:string,message:string}
     *
     * @since 1.0.0
     */
    private function recordStatus(bool $test, array $domainObject): array
    {
        $apple  = $domainObject['apple_pay'] ?? [];
        $status = is_array($apple) ? (string) ($apple['status'] ?? 'unknown') : 'unknown';

        $message = '';
        if (is_array($apple) && isset($apple['status_details']) && is_array($apple['status_details'])) {
            $message = (string) ($apple['status_details']['error_message'] ?? '');
        }

        $all = $this->storedStatus();
        $all[$test ? 'test' : 'live'] = [
            'status'     => $status,
            'message'    => $message,
            'checked_at' => gmdate('Y-m-d H:i:s'),
        ];
        SystemSetting::write(self::STATUS_KEY, (string) wp_json_encode($all));

        return ['status' => $status, 'message' => $message];
    }

    /**
     * @return array<string,mixed>
     *
     * @since 1.0.0
     */
    private function storedStatus(): array
    {
        $json = SystemSetting::read(self::STATUS_KEY);
        if (! is_string($json) || $json === '') return [];
        $data = json_decode($json, true);
        return is_array($data) ? $data : [];
    }
}
