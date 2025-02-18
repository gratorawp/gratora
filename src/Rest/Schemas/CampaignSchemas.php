<?php

declare(strict_types=1);

namespace Dono\Rest\Schemas;

/**
 * JSON-Schema arg specs for the admin campaigns endpoints. update() reuses
 * properties() with everything optional for partial PATCH updates.
 *
 * @version 1.0.0
 */
final class CampaignSchemas
{
    /** @return array<string, array<string,mixed>> */
    public static function create(): array
    {
        $props = self::properties();
        $props['title']['required'] = true;
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
                // sanitize_title() would normalize anyway; only flag obvious garbage
                'pattern'   => '^[A-Za-z0-9 _\\-]+$',
            ],
            'status' => [
                'type' => 'string',
                'enum' => ['draft', 'published', 'archived'],
            ],
            'campaign_type' => [
                'type' => 'string',
            ],
            'description' => [
                'type'      => ['string', 'null'],
                'maxLength' => 5000,
            ],
            'currency' => [
                'type'    => 'string',
                'pattern' => '^[A-Za-z]{3}$',
            ],
            'goal_type' => [
                'type' => 'string',
                'enum' => ['amount', 'donations', 'donors'],
            ],
            'goal_cents' => [
                'type'    => ['integer', 'null'],
                'minimum' => 0,
            ],
            'goal_count' => [
                'type'    => ['integer', 'null'],
                'minimum' => 0,
            ],
            'starts_at' => [
                'type' => ['string', 'null'],
                // ISO date or datetime, kept loose; service coerces
            ],
            'ends_at' => [
                'type' => ['string', 'null'],
            ],
            'style' => [
                'type' => ['object', 'null'],
            ],
            'hide_header' => ['type' => 'boolean'],
            'hide_footer' => ['type' => 'boolean'],
            'default_fund_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'default_form_id' => ['type' => ['integer', 'null'], 'minimum' => 1],
            'image_attachment_id' => ['type' => ['integer', 'null'], 'minimum' => 1],

            // Core-data PUTs send the whole record including derived/read-only fields.
            // We accept-but-ignore them rather than rejecting.
            'image_url' => ['type' => ['string', 'null']],
            'id' => ['type' => 'integer'],
            'forms_count' => ['type' => 'integer'],
            'raised_cents' => ['type' => 'integer'],
            'donations_count' => ['type' => 'integer'],
            'donors_count' => ['type' => 'integer'],
            'page_id' => ['type' => ['integer', 'null']],
            'page_edit_url' => ['type' => ['string', 'null']],
            'page_url' => ['type' => ['string', 'null']],
            'updated_at' => ['type' => 'string'],
            'created_at' => ['type' => 'string'],
            'metrics' => ['type' => 'object'],
        ];
    }
}
