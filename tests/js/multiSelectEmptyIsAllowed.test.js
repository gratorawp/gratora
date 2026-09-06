/**
 * A minimum on an optional multi-select blocked an empty answer on the client
 * while the server let it through, so the donor could neither leave the field
 * alone nor get past the step. "Required" is what makes empty an error.
 */

const { validateStep } = require( '../../assets/donation-form/state/store' );

const step = ( attrs ) => ( {
    type:   'donor',
    fields: [ {
        kind:  'multi-select',
        field: 'extras',
        ...attrs,
    } ],
} );

const state = ( selected ) => ( {
    values: { email: 'a@example.test', custom: selected === null ? {} : { extras: selected } },
    i18n:   {},
} );

it( 'lets an optional field with a minimum be left empty', () => {
    const errors = validateStep(
        step( { required: false, minSelections: 2 } ),
        state( null )
    );

    expect( errors[ 'custom.extras' ] ).toBeUndefined();
} );

it( 'still enforces the minimum once something is picked', () => {
    const errors = validateStep(
        step( { required: false, minSelections: 2 } ),
        state( [ 'a' ] )
    );

    expect( errors[ 'custom.extras' ] ).toBeDefined();
} );

it( 'still refuses an empty required field', () => {
    const errors = validateStep(
        step( { required: true, minSelections: 2 } ),
        state( [] )
    );

    expect( errors[ 'custom.extras' ] ).toBeDefined();
} );

it( 'still enforces the maximum', () => {
    const errors = validateStep(
        step( { required: false, maxSelections: 1 } ),
        state( [ 'a', 'b' ] )
    );

    expect( errors[ 'custom.extras' ] ).toBeDefined();
} );
