<?php

declare(strict_types=1);

namespace Dono\Rest\Schemas;

/**
 * JSON-Schema arg specs for the public donation endpoint. WP validates args
 * automatically (failures: rest_invalid_param, 400); DonationService guards
 * remain as defense-in-depth.
 *
 * @since 1.0.0
 */
final class DonationSchemas
{
    /**
     * @return array<string, array<string,mixed>>
     *
     * @since 1.0.0
     */
    public static function create(): array
    {
        return [
            'email' => [
                'type'     => 'string',
                'required' => true,
                'format'   => 'email',
            ],
            'amount_cents' => [
                'type'     => 'integer',
                'required' => true,
                'minimum'  => 1,
                'maximum'  => 99_999_999, // sanity cap: ~999k of any major unit
            ],
            'currency' => [
                'type'     => 'string',
                'required' => true,
                'pattern'  => '^[A-Za-z]{3}$',
            ],
            'gateway' => [
                'type'      => 'string',
                'required'  => true,
                'minLength' => 1,
            ],
            'frequency' => [
                'type'    => 'string',
                'default' => 'one_time',
                'enum'    => ['one_time', 'weekly', 'biweekly', 'monthly', 'quarterly', 'yearly'],
            ],
            'form_id'     => ['type' => ['integer', 'null']],
            'campaign_id' => ['type' => ['integer', 'null']],
            'fund_id'     => ['type' => ['integer', 'null']],

            'profile' => [
                'type'                 => 'object',
                'additionalProperties' => false,
                'properties'           => [
                    'first_name' => ['type' => 'string', 'maxLength' => 100],
                    'last_name'  => ['type' => 'string', 'maxLength' => 100],
                    'country'    => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$'],
                    'phone'      => ['type' => 'string', 'maxLength' => 40],
                    'address'    => [
                        'type'                 => 'object',
                        'additionalProperties' => false,
                        'properties'           => [
                            'line1'   => ['type' => 'string', 'maxLength' => 200],
                            'line2'   => ['type' => 'string', 'maxLength' => 200],
                            'city'    => ['type' => 'string', 'maxLength' => 100],
                            'region'  => ['type' => 'string', 'maxLength' => 100],
                            'postal'  => ['type' => 'string', 'maxLength' => 20],
                            'country' => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$'],
                        ],
                    ],
                ],
            ],

            'payment_method'     => ['type' => 'string', 'maxLength' => 32],
            // Public, unauthenticated blobs: bound the leaves so they can't be
            // used for storage amplification. A total-size cap is also enforced
            // server-side before persisting (source_attribution is stored
            // verbatim; custom is AES-encrypted).
            'source_attribution' => [
                'type'                 => 'object',
                'additionalProperties' => ['type' => 'string', 'maxLength' => 500],
            ],
            'custom'             => ['type' => 'object'],
            'locale'             => ['type' => 'string', 'maxLength' => 10],
            'note_to_org'        => ['type' => 'string', 'maxLength' => 5000],
            'is_anonymous'       => ['type' => 'boolean', 'default' => false],
            'country'            => ['type' => 'string', 'pattern' => '^[A-Za-z]{2}$'],
            'fee_covered_cents'  => ['type' => 'integer', 'minimum' => 0, 'default' => 0],
            // Not stored: the covered amount is. Carried so a field conditioned
            // on "Cover fees" evaluates the same on both sides.
            'cover_fees'         => ['type' => 'boolean', 'default' => false],
        ];
    }
}
