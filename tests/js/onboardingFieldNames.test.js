/**
 * The location step captures the organisation name printed on every receipt and
 * the country that sets the default currency, and country is required to
 * advance. A screen reader heard "edit text, edit text, combo box, combo box"
 * with no names, so the wizard could not be completed with any confidence about
 * what went where.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { LocationStep } from '../../assets/admin/onboarding/Onboarding';

function mount( country = 'GB' ) {
    document.body.innerHTML = '<div id="root"></div>';

    render(
        <LocationStep
            value={ { name: '', email: '', country, state: '', type: 'org' } }
            onChange={ () => {} }
            currency={ { default_currency: 'GBP' } }
            onCurrencyChange={ () => {} }
        />,
        document.getElementById( 'root' )
    );

    return document.getElementById( 'root' );
}

/** What a screen reader reads out for a control. */
function accessibleName( el ) {
    if ( el.id ) {
        const forLabel = document.querySelector( `label[for="${ el.id }"]` );
        if ( forLabel ) return forLabel.textContent.trim();
    }

    const wrapping = el.closest( 'label' );

    return wrapping ? wrapping.textContent.trim() : '';
}

it( 'names the organisation name and contact email fields', () => {
    const host = mount();

    host.querySelectorAll( 'input[type="text"], input[type="email"]' ).forEach( ( input ) => {
        expect( accessibleName( input ) ).not.toBe( '' );
    } );
} );

it( 'names every picker on the step', () => {
    const host = mount( 'US' );

    const pickers = host.querySelectorAll( '.fundkit-onboarding__control-label' );

    expect( pickers.length ).toBeGreaterThanOrEqual( 2 );
    pickers.forEach( ( label ) => {
        expect( label.textContent.trim() ).not.toBe( '' );
    } );
} );
