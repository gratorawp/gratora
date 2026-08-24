<?php

declare(strict_types=1);

namespace Dono\Foundation\License;

/**
 * Turns refused add-ons into what an admin should actually be told.
 *
 * The licence server answers with one of several refusals and they do not mean
 * the same thing to the person reading the screen. A key that was mistyped is
 * not a plan that excludes the add-on, and a site that has run out of seats is
 * a customer who did buy the add-on and needs to hear that freeing a seat is
 * all it takes. Collapsing them into one sentence tells most of those people
 * something untrue about what they own.
 *
 * Both the admin notice and the readiness list read from here so they cannot
 * drift apart.
 *
 * @since 1.0.0
 */
final class LicenseRefusals
{
    /**
     * @param array<int,array{id:string,name:string,status:string,entitled:bool}> $refused
     * @return array<int,array{status:string,names:string,headline:string,detail:string}>
     * @since 1.0.0
     */
    public static function group(array $refused): array
    {
        $byStatus = [];
        foreach ($refused as $addon) {
            $byStatus[(string) $addon['status']][] = (string) $addon['name'];
        }

        // Most actionable first, so a site with several kinds of refusal leads
        // with the one the admin can fix.
        $order = ['invalid', 'over_limit', 'not_entitled', 'revoked'];
        uksort($byStatus, static function (string $a, string $b) use ($order): int {
            $ai = array_search($a, $order, true);
            $bi = array_search($b, $order, true);

            return ($ai === false ? PHP_INT_MAX : $ai) <=> ($bi === false ? PHP_INT_MAX : $bi);
        });

        $out = [];
        foreach ($byStatus as $status => $names) {
            sort($names);
            $out[] = self::copy((string) $status, implode(', ', $names));
        }

        return $out;
    }

    /**
     * @return array{status:string,names:string,headline:string,detail:string}
     * @since 1.0.0
     */
    private static function copy(string $status, string $names): array
    {
        $keepRunning = __('They keep running for now, but they will not receive updates or security fixes.', 'dono-fundraising-platform');

        switch ($status) {
            case 'invalid':
                return [
                    'status'   => $status,
                    'names'    => $names,
                    'headline' => sprintf(
                        /* translators: %s: comma-separated add-on names */
                        __('The license key on this site was not recognised, so %s could not be checked', 'dono-fundraising-platform'),
                        $names
                    ),
                    'detail'   => __('Check the key against your purchase email. Until it is accepted they keep running, but they will not receive updates or security fixes.', 'dono-fundraising-platform'),
                ];

            case 'over_limit':
                return [
                    'status'   => $status,
                    'names'    => $names,
                    'headline' => sprintf(
                        /* translators: %s: comma-separated add-on names */
                        __('Your license has no sites left for %s', 'dono-fundraising-platform'),
                        $names
                    ),
                    // Deactivating elsewhere is enough: the client re-activates
                    // on its own next check, with no need to re-enter the key.
                    'detail'   => __('Deactivate the license on a site you no longer use, or move to a larger plan, and this site picks it up on its own.', 'dono-fundraising-platform'),
                ];

            case 'revoked':
                return [
                    'status'   => $status,
                    'names'    => $names,
                    'headline' => sprintf(
                        /* translators: %s: comma-separated add-on names */
                        __('The license for %s has been revoked', 'dono-fundraising-platform'),
                        $names
                    ),
                    'detail'   => $keepRunning,
                ];

            case 'not_entitled':
            default:
                return [
                    'status'   => $status,
                    'names'    => $names,
                    'headline' => sprintf(
                        /* translators: %s: comma-separated add-on names */
                        __('Your license does not cover %s', 'dono-fundraising-platform'),
                        $names
                    ),
                    'detail'   => $keepRunning,
                ];
        }
    }
}
