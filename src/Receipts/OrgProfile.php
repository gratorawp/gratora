<?php

declare(strict_types=1);

namespace GiveFlow\Receipts;

/**
 * The organisation as it appears on anything a donor keeps.
 *
 * Legal name is the field the settings screen requires, calling it the entity
 * that legally receives donations; display name is optional. An organisation
 * that filled in the one it was asked for must not end up with a receipt from
 * nobody, so the name resolves through both before falling back to the site.
 *
 * @since 1.0.0
 */
final class OrgProfile
{
    /**
     * @return array{name:string, address_lines:array<int,string>, tax_id:string, vat_id:string, email:string}
     * @since 1.0.0
     */
    public static function load(): array
    {
        $defaults = [
            'name'          => (string) get_bloginfo('name'),
            'address_lines' => [],
            'tax_id'        => '',
            'vat_id'        => '',
            'email'         => (string) get_option('admin_email'),
        ];

        $stored = get_option('giveflow_org_profile', []);
        $org    = is_array($stored) ? array_merge($defaults, $stored) : $defaults;

        $org['name'] = self::displayName($org);

        return $org;
    }

    /**
     * @param array<string,mixed> $org
     * @since 1.0.0
     */
    public static function displayName(array $org): string
    {
        $name = trim((string) ($org['name'] ?? ''));
        if ($name !== '') {
            return $name;
        }

        return trim((string) ($org['legal_name'] ?? '')) ?: (string) get_bloginfo('name');
    }
}
