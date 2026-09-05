/**
 * The route behind this block always answers with at least the org's base
 * currency, so an empty list can only mean the request failed. Storing the
 * failure as an empty list told the author "No currencies are enabled yet",
 * which is a statement about the org rather than about the request, and left
 * nothing to try again.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/block-editor', () => ( {
    useBlockProps: () => ( {} ),
    InspectorControls: ( { children } ) => children,
} ) );

jest.mock( '@wordpress/components', () => ( {
    PanelBody:    ( { children } ) => children,
    TextControl:  () => null,
    Spinner:      () => null,
    ExternalLink: ( { children } ) => children,
    Notice:       ( { children } ) => children,
    Button:       ( { children, onClick } ) => (
        <button type="button" onClick={ onClick }>{ children }</button>
    ),
} ) );

const register = require( '../../assets/admin/forms/blocks/currency-switcher/index' ).default;

let block = null;
register( { register: ( name, settings ) => { block = settings; } } );

let root = null;

function mount() {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    const Edit = block.edit;
    render( <Edit attributes={ {} } setAttributes={ () => {} } />, root );
}

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
} );

it( 'says the list could not be loaded, not that there is nothing to load', async () => {
    apiFetch.mockImplementation( () => Promise.reject( new Error( 'down' ) ) );

    mount();
    await waitFor( () => document.body.textContent.includes( 'could not be loaded' ) );

    expect( document.body.textContent ).not.toContain( 'No currencies are enabled yet' );
    expect( [ ...document.querySelectorAll( 'button' ) ].map( ( b ) => b.textContent.trim() ) )
        .toContain( 'Try again' );
} );

it( 'asks again when told to', async () => {
    let fail = true;
    apiFetch.mockImplementation( () => (
        fail
            ? Promise.reject( new Error( 'down' ) )
            : Promise.resolve( { base: 'EUR', currencies: [ 'EUR', 'USD' ] } )
    ) );

    mount();
    await waitFor( () => document.body.textContent.includes( 'could not be loaded' ) );

    fail = false;
    [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === 'Try again' )
        .click();

    await waitFor( () => ! document.body.textContent.includes( 'could not be loaded' ) );
    expect( apiFetch ).toHaveBeenCalledTimes( 2 );
} );
