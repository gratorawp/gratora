/**
 * The asterisk is the form's promise about what it will refuse. A minimum on an
 * optional multi-select marked it required while an empty answer sailed
 * through, so the mark named a rule the form does not have.
 */

import { render } from 'preact';

import DonorStep from '../../assets/donation-form/steps/DonorStep';

const CONFIG = { currency: 'USD', locale: 'en-US' };

const field = ( attrs ) => ( {
    kind:    'multi-select',
    field:   'extras',
    label:   'Add-ons',
    options: [ { value: 'a', label: 'Tote' }, { value: 'b', label: 'Mug' } ],
    ...attrs,
} );

let root = null;

function mount( f ) {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render(
        <DonorStep
            fields={ [ f ] }
            state={ { values: { custom: {} }, errors: {} } }
            dispatch={ () => {} }
            config={ CONFIG }
        />,
        root
    );
}

const marks = () => root.querySelectorAll( '.gratora-form__required' ).length;

it( 'marks a required multi-select', () => {
    mount( field( { required: true } ) );

    expect( root.textContent ).toContain( 'Add-ons' );
    expect( marks() ).toBe( 1 );
} );

it( 'does not mark an optional one that merely has a minimum', () => {
    mount( field( { required: false, minSelections: 2 } ) );

    expect( root.textContent ).toContain( 'Add-ons' );
    expect( marks() ).toBe( 0 );
} );
