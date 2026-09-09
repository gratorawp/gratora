/**
 * Every Gratora modal claims the screen with aria-modal="true" but never took
 * focus, so Tab carried on into the page behind the overlay and the list under
 * the dialog stayed operable.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const ConfirmDialog = require( '../../assets/admin/_shared/components/ConfirmDialog' ).default;

// preact defers effects, so the trap has not bound on the render that returns.
async function settle() {
    for ( let i = 0; i < 5; i++ ) {
        await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
        await new Promise( ( r ) => setTimeout( r, 0 ) );
    }
}

let root = null;
let opener = null;

async function open( onClose = () => {} ) {
    if ( root ) render( null, root );

    document.body.innerHTML = '<button id="behind">Behind</button><div id="root"></div>';
    opener = document.getElementById( 'behind' );
    opener.focus();
    root = document.getElementById( 'root' );

    render(
        <ConfirmDialog
            confirm={ {
                title:   'Delete donor',
                message: 'This cannot be undone.',
                confirmLabel: 'Delete',
                onConfirm: () => {},
            } }
            onClose={ onClose }
        />,
        root
    );
    await settle();
}

const tabbables = () => [ ...document
    .querySelector( '.gratora-dialog' )
    .querySelectorAll( 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])' ) ];

const tab = ( shift = false ) => {
    document.dispatchEvent( new window.KeyboardEvent( 'keydown', { key: 'Tab', shiftKey: shift, bubbles: true, cancelable: true } ) );
};

beforeEach( () => {
    document.body.innerHTML = '';
} );

it( 'moves focus into the dialog when it opens', async () => {
    await open();

    expect( document.querySelector( '.gratora-dialog' ).contains( document.activeElement ) ).toBe( true );
    expect( document.activeElement.id ).not.toBe( 'behind' );
} );

it( 'wraps Tab from the last control back to the first', async () => {
    await open();

    const nodes = tabbables();
    expect( nodes.length ).toBeGreaterThan( 1 );

    nodes[ nodes.length - 1 ].focus();
    tab();

    expect( document.activeElement ).toBe( nodes[ 0 ] );
} );

it( 'wraps shift-Tab from the first control back to the last', async () => {
    await open();

    const nodes = tabbables();
    nodes[ 0 ].focus();
    tab( true );

    expect( document.activeElement ).toBe( nodes[ nodes.length - 1 ] );
} );

it( 'gives focus back to whatever opened it', async () => {
    await open();
    expect( document.activeElement.id ).not.toBe( 'behind' );

    render( null, root );
    await settle();

    expect( document.activeElement.id ).toBe( 'behind' );
} );
