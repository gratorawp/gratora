<?php

declare(strict_types=1);

namespace Gratora\Rest;

use Gratora\Donations\AntiSpamGuard;
use Gratora\Foundation\Hooks\HookProvider;
use WP_REST_Request;

/**
 * Take WordPress's reflected CORS headers off the gratora/v1 namespace for any
 * origin the donation endpoint would refuse.
 *
 * Core answers every REST request with Access-Control-Allow-Origin set to
 * whatever Origin arrived and Access-Control-Allow-Credentials: true, so a
 * stranger's page can read the body of anything in the namespace that does not
 * run its own origin check, the client_secret in a 201 included. Add-ons
 * register into the same namespace, so the surface is wider than core's own
 * controllers.
 *
 * The strip is not unconditional. An origin admitted by the documented
 * allowed_http_origins filter keeps its headers: that filter is how a
 * decoupled front end declares itself, and one takes donations through it.
 *
 * @since 1.0.0
 */
final class CorsPolicy extends HookProvider
{
    private const NAMESPACE = 'gratora/v1';

    /** @since 1.0.0 */
    protected function filters(): array
    {
        return [
            // Core registers rest_send_cors_headers at load, so 10 lands after it
            // and still ahead of the streaming callbacks controllers add mid-dispatch,
            // which echo a body and flush the headers this would remove.
            'rest_pre_serve_request' => ['stripForeignOrigin', 10, 3],
        ];
    }

    /** @since 1.0.0 */
    public function stripForeignOrigin(mixed $served, mixed $result = null, mixed $request = null): mixed
    {
        if (! $request instanceof WP_REST_Request || ! $this->inNamespace($request)) {
            return $served;
        }

        // A controller that streams its own body flushes the headers first, and
        // nothing can be removed after that.
        if (headers_sent()) {
            return $served;
        }

        $origin = get_http_origin();
        $origin = is_string($origin) ? $origin : '';

        header('Vary: Origin', false);

        if (! isset($this->headersFor($origin)['Access-Control-Allow-Origin'])) {
            header_remove('Access-Control-Allow-Origin');
            header_remove('Access-Control-Allow-Credentials');
        }

        return $served;
    }

    /**
     * What a response in this namespace ends up carrying, given core reflected
     * the origin at priority 10. The filter above reads its decision from here
     * so there is one answer, and a test can read it where headers_list() is
     * empty.
     *
     * @return array<string, string>
     * @since 1.0.0
     */
    public function headersFor(string $origin): array
    {
        $headers = ['Vary' => 'Origin'];

        if ($origin === '' || $this->wouldBeRefused($origin)) {
            return $headers;
        }

        $headers['Access-Control-Allow-Origin']      = $origin;
        $headers['Access-Control-Allow-Credentials'] = 'true';

        return $headers;
    }

    /**
     * AntiSpamGuard::checkOrigin() answers the same question for the POST and
     * the two must agree. An origin this refuses that the endpoint serves is a
     * donation lost; an origin this allows that the endpoint refuses is the
     * reflection back again.
     *
     * @since 1.0.0
     */
    public function wouldBeRefused(string $origin): bool
    {
        // No Origin is a server-side caller, which the POST gate allows.
        // headersFor() still sends nothing, there being nothing to reflect.
        if ($origin === '') {
            return false;
        }

        $mine = array_filter([AntiSpamGuard::originOf(home_url()), AntiSpamGuard::originOf(site_url())]);
        if (in_array(AntiSpamGuard::originOf($origin), $mine, true)) {
            return false;
        }

        return ! is_allowed_http_origin($origin);
    }

    /** @since 1.0.0 */
    private function inNamespace(WP_REST_Request $request): bool
    {
        $route = ltrim((string) $request->get_route(), '/');

        return $route === self::NAMESPACE || str_starts_with($route, self::NAMESPACE . '/');
    }
}
