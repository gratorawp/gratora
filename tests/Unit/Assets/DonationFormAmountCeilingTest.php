<?php

declare(strict_types=1);

namespace Dono\Tests\Unit\Assets;

use PHPUnit\Framework\TestCase;

/**
 * The REST schema caps amount_cents, and WordPress checks registered args
 * before the callback runs, so anything the form lets through above the cap
 * comes back as "Invalid parameter(s): amount_cents". A major donor typing a
 * seven-figure amount gets a developer's string and no idea a ceiling exists.
 *
 * @since 1.0.0
 */
final class DonationFormAmountCeilingTest extends TestCase
{
    use RunsFormModule;

    /** A state with nothing configured but the amount the donor entered. */
    private const STATE = <<<'JS'
        const stateWith = ( cents, currency = 'USD' ) => ( {
            steps: [],
            preamble: [],
            currency,
            presetCurrency: currency,
            fx: { base: 'USD', rates: { USD: 1 } },
            minAmountCents: 0,
            i18n: { validation: { maxNumber: 'Must be at most %s.' } },
            values: { amount_cents: cents, cover_fees: false },
            errors: {},
        } );
    JS;

    public function test_an_amount_over_the_schema_cap_is_refused_with_a_figure(): void
    {
        $out = $this->runModule('state/store.js', self::STATE . <<<'JS'
            const fmt = await import( './util/format.mjs' );
            fmt.setActiveNumberFormat(
                { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
                'USD'
            );
            const over = mod.validateStep( { type: 'amount' }, stateWith( 100000000 ) );
            const at   = mod.validateStep( { type: 'amount' }, stateWith( 99999999 ) );
            emit( { over: over.amount_cents || null, at: at.amount_cents || null } );
        JS);

        $this->assertSame('Must be at most $999,999.99.', $out['over']);
        $this->assertNull($out['at'], 'the cap itself is still a donation the server takes');
    }

    /**
     * Typing is the path the ceiling exists for, and a clamp on the box would
     * settle it before the message could: the figure is rewritten to the cap
     * while the box still shows what the donor typed, and the payload carries
     * an amount they never entered. Nothing tells them.
     *
     * The box is JSX, which the node harness cannot execute, so the two
     * expressions that would do the rewriting are pinned in source.
     */
    public function test_a_typed_amount_is_never_rewritten_to_the_ceiling(): void
    {
        $root = dirname(__DIR__, 3) . '/assets/donation-form';

        $step = (string) file_get_contents($root . '/steps/AmountStep.jsx');
        $this->assertNotSame('', $step);
        $this->assertDoesNotMatchRegularExpression(
            '/\bmax=\{/',
            $step,
            'the amount step must not cap the box: validateStep names the ceiling instead'
        );

        $input = (string) file_get_contents($root . '/components/AmountInput.jsx');
        $this->assertNotSame('', $input);
        $this->assertStringNotContainsString(
            'n > max',
            $input,
            'and the box itself must not carry an upper clamp for a caller to arm'
        );
    }

    /**
     * Storage is major x 100 in every currency, so a zero-decimal currency
     * runs out a hundred whole units earlier and its ceiling has to land on
     * one: the box would otherwise offer a figure the create path refuses for
     * having a fractional part.
     */
    public function test_the_ceiling_lands_on_a_whole_unit_in_a_zero_decimal_currency(): void
    {
        $out = $this->runModule('util/fx.js', <<<'JS'
            emit( { jpy: mod.maxAmountFor( 'JPY' ), usd: mod.maxAmountFor( 'USD' ) } );
        JS);

        $this->assertSame(99999900, $out['jpy']);
        $this->assertSame(99999999, $out['usd']);
    }
}
