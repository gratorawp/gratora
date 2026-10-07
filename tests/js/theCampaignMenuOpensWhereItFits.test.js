/**
 * The campaign's menu hangs from the far edge of its button and runs back along
 * the row of tabs. Add-ons add tabs, and with enough of them the button wraps to
 * the start of a second row. The menu then ran out under the WordPress menu,
 * where nothing in it could be clicked.
 */

import { render } from 'preact';
import { act } from 'preact/test-utils';
import { setLocaleData } from '@wordpress/i18n';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { HeaderMenu } from '../../assets/admin/campaigns/Detail';

const ROW = { left: 182, width: 1078 };
const AT_THE_LEFT = { left: 182, width: 34 };
const AT_THE_RIGHT = { left: 1226, width: 34 };

let boxes = {};

// jsdom lays nothing out, so each box is given the place it has on the screen.
beforeEach( () => {
    jest.spyOn( Element.prototype, 'getBoundingClientRect' ).mockImplementation( function () {
        const name = Object.keys( boxes ).find( ( cls ) => this.classList.contains( cls ) );
        const { left = 0, width = 0 } = boxes[ name ] || {};

        return { left, right: left + width, width, top: 0, bottom: 0, height: 0, x: left, y: 0 };
    } );

    document.body.innerHTML = '<div class="gratora-detail-nav" id="row"></div>';
    render( <HeaderMenu campaign={ { status: 'draft' } } onAction={ () => {} } />, document.getElementById( 'row' ) );
} );

afterEach( () => {
    render( null, document.getElementById( 'row' ) );
    jest.restoreAllMocks();
    setLocaleData( { 'text direction\u0004ltr': [ 'ltr' ] } );
} );

function press() {
    act( () => document.querySelector( '.gratora-menu__trigger' ).click() );
}

function openWithTheButton( trigger ) {
    boxes = {
        'gratora-detail-nav':    ROW,
        'gratora-menu__trigger': trigger,
        'gratora-menu__list':    { left: 0, width: 182 },
    };
    press();

    return document.querySelector( '.gratora-menu__list' );
}

describe( 'the campaign menu', () => {
    it( 'runs back along the row from a button at its far end', () => {
        const list = openWithTheButton( AT_THE_RIGHT );

        expect( list ).not.toBeNull();
        expect( list.classList.contains( 'is-from-start' ) ).toBe( false );
    } );

    it( 'runs forward from a button that wrapped to the start of the row', () => {
        expect( openWithTheButton( AT_THE_LEFT ).classList.contains( 'is-from-start' ) ).toBe( true );
    } );

    it( 'measures its room again each time it opens', () => {
        openWithTheButton( AT_THE_LEFT );
        press();
        expect( document.querySelector( '.gratora-menu__list' ) ).toBeNull();

        expect( openWithTheButton( AT_THE_RIGHT ).classList.contains( 'is-from-start' ) ).toBe( false );
    } );

    describe( 'read from right to left', () => {
        beforeEach( () => setLocaleData( { 'text direction\u0004ltr': [ 'rtl' ] } ) );

        it( 'runs back along the row from a button at its far end, on the left', () => {
            expect( openWithTheButton( AT_THE_LEFT ).classList.contains( 'is-from-start' ) ).toBe( false );
        } );

        it( 'runs forward from a button that wrapped to the start of the row, on the right', () => {
            expect( openWithTheButton( AT_THE_RIGHT ).classList.contains( 'is-from-start' ) ).toBe( true );
        } );
    } );
} );
