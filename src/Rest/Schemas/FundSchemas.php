<?php

declare(strict_types=1);

namespace Dono\Rest\Schemas;

/**
 * JSON-Schema arg specs for the admin funds endpoints. update() reuses
 * properties() with everything optional for partial PATCH updates.
 *
 * @version 1.0.0
 */
final class FundSchemas
{
    /** @return array<string, array<string,mixed>> */
    public static function create(): array
    {
        $props = self::properties();
        $props['code']['required'] = true;
        $props['name']['required'] = true;
        return $props;
    }

    /** @return array<string, array<string,mixed>> */
    public static function update(): array
    {
        return self::properties();
    }

    /** @return array<string, array<string,mixed>> */
    private static function properties(): array
    {
        return [
            'code' => [
                'type'      => 'string',
                'minLength' => 1,
                'maxLength' => 64,
                'pattern'   => '^[A-Za-z0-9_\\-]+$',
            ],
            'name' => [
                'type'      => 'string',
                'minLength' => 1,
                'maxLength' => 150,
            ],
            'description' => [
                'type'      => ['string', 'null'],
                'maxLength' => 5000,
            ],
            'is_restricted' => ['type' => ['boolean', 'null']],
            'is_default'    => ['type' => ['boolean', 'null']],
            'is_active'     => ['type' => ['boolean', 'null']],
            'sort_order' => [
                'type'    => ['integer', 'null'],
                'minimum' => 0,
            ],
            'parent_fund_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'goal_cents' => [
                'type'    => ['integer', 'null'],
                'minimum' => 0,
            ],
            'starts_at'       => ['type' => ['string', 'null']],
            'ends_at'         => ['type' => ['string', 'null']],
            'accounting_code' => [
                'type'      => ['string', 'null'],
                'maxLength' => 64,
            ],

            // Whole-record round-trips send derived/read-only fields back;
            // accept-but-ignore rather than reject.
            'id'           => ['type' => 'integer'],
            'raised_cents' => ['type' => 'integer'],
            'created_at'   => ['type' => 'string'],
            'updated_at'   => ['type' => 'string'],
        ];
    }
}
