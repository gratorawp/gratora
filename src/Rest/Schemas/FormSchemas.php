<?php

declare(strict_types=1);

namespace Dono\Rest\Schemas;

/**
 * JSON-Schema arg specs for the admin forms endpoints. Settings is
 * open-shaped: known fields are described but additional properties are
 * allowed because modules can extend it.
 *
 * @version 1.0.0
 */
final class FormSchemas
{
    /** @return array<string, array<string,mixed>> */
    public static function create(): array
    {
        $props = self::properties();
        $props['title']['required']       = true;
        $props['campaign_id']['required'] = true;
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
            'title' => [
                'type'      => 'string',
                'minLength' => 1,
                'maxLength' => 200,
            ],
            'slug' => [
                'type'      => 'string',
                'maxLength' => 200,
                'pattern'   => '^[A-Za-z0-9 _\\-]+$',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'published', 'archived'],
            ],
            'campaign_id' => [
                'type'    => 'integer',
                'minimum' => 1,
            ],
            'default_fund_id' => [
                'type'    => ['integer', 'null'],
                'minimum' => 1,
            ],
            'blocks' => [
                'type' => ['string', 'null'],
            ],
            'settings' => [
                'type'                 => 'object',
                'additionalProperties' => true,
                'properties'           => [
                    // Recurring frequencies are configured per-block on
                    // dono/recurring-toggle; anonymity is per-block on
                    // dono/anonymous-toggle. Removed from settings to keep
                    // form.settings reflecting only top-level form options.
                    'gateways' => [
                        'type'       => 'object',
                        'properties' => [
                            'allowed' => [
                                'type'  => 'array',
                                'items' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'thank_you_message' => ['type' => 'string', 'maxLength' => 5000],
                    'redirect_url'      => ['type' => 'string', 'maxLength' => 2000, 'format' => 'uri'],
                ],
            ],

            // Read-only fields tolerated for core-data round-trips.
            'id' => ['type' => 'integer'],
            'campaign' => ['type' => ['object', 'null']],
            'published_at' => ['type' => ['string', 'null']],
            'updated_at' => ['type' => 'string'],
            'created_at' => ['type' => 'string'],
            'spec' => ['type' => ['object', 'null']],
            'spec_version' => ['type' => ['integer', 'null']],
        ];
    }
}
