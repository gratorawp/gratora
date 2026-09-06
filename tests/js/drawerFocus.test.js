/**
 * Both drawers claim the screen the way every other FundKit modal does. They
 * were reaching past the wrapper that adds focus management to the design
 * system's dialog, so Tab walked straight out into the list behind them.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const CreateCampaignDrawer = require( '../../assets/admin/campaigns/CreateCampaignDrawer' ).default;
const RecordDonationDrawer = require( '../../assets/admin/donations/RecordDonationDrawer' ).default;

async function settle() {
    for ( let i = 0; i < 5; i++ ) {
        await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
        await new Promise( ( r ) => setTimeout( r, 0 ) );
    }
}

let root = null;

async function open( node ) {
    if ( root ) render( null, root );

    document.body.innerHTML = '<button id="behind">Behind</button><div id="root"></div>';
    document.getElementById( 'behind' ).focus();
    root = document.getElementById( 'root' );

    render( node, root );
    await settle();
}

const dialog = () => document.querySelector( '.fundkit-dialog' );

const tabbables = () => [ ...dialog().querySelectorAll(
    'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])'
) ];

const tab = ( shift = false ) => document.dispatchEvent(
    new window.KeyboardEvent( 'keydown', { key: 'Tab', shiftKey: shift, bubbles: true, cancelable: true } )
);

beforeEach( () => {
    apiFetch.mockReset();
    apiFetch.mockImplementation( () => Promise.resolve( [] ) );
    document.body.innerHTML = '';
} );

const drawers = [
    [ 'the new campaign drawer', () => <CreateCampaignDrawer onClose={ () => {} } /> ],
    [ 'the record donation drawer', () => <RecordDonationDrawer onClose={ () => {} } onRecorded={ () => {} } /> ],
];

describe.each( drawers )( '%s', ( _name, node ) => {
    it( 'takes focus off the page behind it', async () => {
        await open( node() );

        expect( dialog() ).not.toBeNull();
        expect( dialog().contains( document.activeElement ) ).toBe( true );
        expect( document.activeElement.id ).not.toBe( 'behind' );
    } );

    it( 'keeps Tab inside itself', async () => {
        await open( node() );

        const nodes = tabbables();
        expect( nodes.length ).toBeGreaterThan( 1 );

        nodes[ nodes.length - 1 ].focus();
        tab();
        expect( document.activeElement ).toBe( nodes[ 0 ] );

        nodes[ 0 ].focus();
        tab( true );
        expect( document.activeElement ).toBe( nodes[ nodes.length - 1 ] );
    } );

    it( 'gives focus back to whatever opened it', async () => {
        await open( node() );
        expect( document.activeElement.id ).not.toBe( 'behind' );

        render( null, root );
        await settle();

        expect( document.activeElement.id ).toBe( 'behind' );
    } );
} );
