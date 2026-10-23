/**
 * A check, a bank transfer and a test donation carry no card fee, so the donor
 * is not asked to cover one and none is added, even on a form saved with the
 * box ticked.
 */

import { initialState, buildPayload, coveredFeeCents, feeOffered, validateStep } from '../../assets/donation-form/state/store';
import { coverFeesLabel } from '../../assets/donation-form/util/interpolate';

function state( gateway, more = [] ) {
    const s = initialState( {
        slug:     'appeal',
        currency: 'USD',
        gateway,
        steps: [
            { id: 'amount', type: 'amount', presets: [ { cents: 5000, preselected: true } ] },
            {
                id:     'donor',
                type:   'donor',
                fields: [ { kind: 'cover_fees', percent: 2.9, fixed: 30, defaultOn: true, label: '' }, ...more ],
            },
            { id: 'submit', type: 'submit' },
        ],
    } );
    return { ...s, values: { ...s.values, email: 'a@example.com' } };
}

it.each( [ 'offline', 'sandbox' ] )( 'adds no fee to a %s donation', ( gateway ) => {
    const s = state( gateway );

    expect( feeOffered( s ) ).toBe( false );
    expect( coveredFeeCents( s ) ).toBe( 0 );

    const payload = buildPayload( s );
    expect( payload.amount_cents ).toBe( 5000 );
    expect( payload.fee_covered_cents ).toBe( 0 );
} );

// The server reads the tick for display conditions, so it has to see what the form saw.
it( 'sends the tick as the form has it, so a field shown only without it agrees with the server', () => {
    const field = {
        kind:      'text',
        field:     'why_not',
        label:     'Why not cover the fee?',
        required:  true,
        condition: { field: 'cover_fees', op: '=', value: 'false' },
    };
    const s = state( 'offline', [ field ] );

    expect( validateStep( s.steps[ 1 ], s ) ).toEqual( {} );
    expect( buildPayload( s ).cover_fees ).toBe( true );
} );

it( 'still adds it to a card donation the donor chose to cover', () => {
    const s = state( 'stripe' );

    expect( feeOffered( s ) ).toBe( true );
    expect( buildPayload( s ).amount_cents ).toBe( 5000 + 175 );
} );

it( 'puts the amount where the label asks for it', () => {
    expect( coverFeesLabel( 'Add {fee} to cover card fees', '$1.75' ) ).toBe( 'Add $1.75 to cover card fees' );
} );
