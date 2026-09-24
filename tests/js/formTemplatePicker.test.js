/**
 * The picker draws each template in the preset it names, and a zero radius is
 * a square corner, not a missing one. Every category tab goes through
 * translation, including the ones the core templates file under Campaign.
 */

import { render } from 'preact';

const { waitFor } = require( './support/waitFor' );

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// A marker on every string that went through a translation call.
jest.mock( '@wordpress/i18n', () => ( {
    __:      ( s ) => `T:${ s }`,
    _n:      ( s, p, n ) => `T:${ n === 1 ? s : p }`,
    _x:      ( s ) => `T:${ s }`,
    sprintf: ( f, ...a ) => a.reduce( ( out, v ) => out.replace( /%(\d\$)?[sd]/, String( v ) ), f ),
} ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( '@wordpress/components', () => ( {
    __esModule: true,
    Modal:   ( { children } ) => children,
    Spinner: () => null,
} ) );

import apiFetch from '@wordpress/api-fetch';
import FormTemplatePicker, { FormTemplateThumb } from '../../assets/admin/_shared/components/FormTemplatePicker';

const BLOCKS = '<!-- wp:gratora/donation-amount {"presets":[1000,2500]} /--><!-- wp:gratora/name /--><!-- wp:gratora/submit-button /-->';

beforeEach( () => {
    window.gratora = {
        styling: {
            defaults:   { 'gratora-accent': '#211d3f', 'gratora-radius': '10px' },
            presets:    [
                { id: 'quiet', tokens: { 'gratora-accent': '#111827', 'gratora-radius': '0px' } },
                { id: 'bold',  tokens: { 'gratora-accent': '#0F3D5C', 'gratora-radius': '6px' } },
                { id: 'odd',   tokens: { 'gratora-radius': 'inherit' } },
            ],
            default_id: 'bold',
        },
    };
    document.body.innerHTML = '<div id="root"></div>';
} );

afterEach( () => { delete window.gratora; } );

function thumbRadius( presetId ) {
    render(
        <FormTemplateThumb template={ { blocks: BLOCKS, settings: { style: { preset_id: presetId } } } } />,
        document.getElementById( 'root' )
    );

    return document.querySelector( '.gratora-template-thumb' ).style.getPropertyValue( '--thumb-radius' ).trim();
}

describe( 'the thumbnail radius', () => {
    it( 'keeps a square preset square', () => {
        expect( thumbRadius( 'quiet' ) ).toBe( '0px' );
    } );

    it( 'scales a rounded preset down with the sheet', () => {
        expect( thumbRadius( 'bold' ) ).toBe( '2px' );
    } );

    it( 'draws a radius it cannot read as the shipped one, scaled', () => {
        expect( thumbRadius( 'odd' ) ).toBe( '2px' );
    } );
} );

describe( 'the category tabs', () => {
    const CATEGORIES = [ 'Campaign', 'Wizard', 'Blank', 'Recurring', 'Standard', 'Starter' ];

    async function tabs() {
        apiFetch.mockResolvedValue( CATEGORIES.map( ( category, i ) => ( { id: `t${ i }`, name: category, category, blocks: '', settings: {} } ) ) );
        render( <FormTemplatePicker onPick={ () => {} } onClose={ () => {} } />, document.getElementById( 'root' ) );
        await waitFor( () => document.querySelectorAll( '.gratora-template-picker__filter' ).length > 1, { what: 'the tabs' } );

        return [ ...document.querySelectorAll( '.gratora-template-picker__filter' ) ].map( ( b ) => b.textContent );
    }

    it( 'translates the Campaign tab and puts it after the others', async () => {
        expect( await tabs() ).toEqual( [ 'T:All', 'T:Blank', 'T:Starter', 'T:Standard', 'T:Recurring', 'T:Wizard', 'T:Campaign' ] );
    } );
} );
