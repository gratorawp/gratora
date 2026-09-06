/**
 * An arrow glyph is text content, so the RTL stylesheet cannot flip it: rtlcss
 * rewrites direction selectors when it builds the RTL bundle, which would fire
 * the rule the wrong way round. On an Arabic form the page is mirrored, back is
 * to the right, and the back control still drew an arrow into forward progress.
 */

const { settle } = require( './support/waitFor' );

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { backGlyph, forwardGlyph } from '../../assets/admin/_shared/arrow';

const CONFIG = {
    slug:     'appeal',
    form_id:  7,
    currency: 'USD',
    gateway:  'offline',
    layout:   'paged',
    rest:     'https://example.test/wp-json/fundkit/v1/donations',
    gateways: { options: [ { id: 'offline', label: 'Offline' } ] },
    pages:    [ { title: 'Amount' }, { title: 'Details' } ],
    pageNav:  { progressStyle: 'bar' },
    steps: [
        { id: 'amount', type: 'amount', page: 0, presets: [ 2500 ] },
        { id: 'submit', type: 'submit', page: 1 },
    ],
    i18n: { donateNow: 'Donate now', processing: 'Processing', next: 'Next', prev: 'Back', error: 'Oops.' },
};

function addForm() {
    const form = document.createElement( 'form' );
    form.className = 'fundkit-donation-form';
    form.id = 'fundkit-form-7';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-fundkit-form-config', '' );
    json.textContent = JSON.stringify( CONFIG );
    form.appendChild( json );

    document.body.appendChild( form );
}

async function bootAndAdvance() {
    addForm();
    jest.isolateModules( () => { require( '../../assets/donation-form/runtime.jsx' ); } );
    await settle();

    document.querySelector( '.fundkit-form__button--primary' ).click();
    await settle();

    return document.querySelector( '.fundkit-form__bar-back span' );
}

beforeEach( () => {
    document.body.innerHTML = '';
    window.fundkit = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
} );

afterEach( () => {
    document.documentElement.dir = '';
    delete window.fundkit;
} );

it( 'points the donation form back control left when the page reads left to right', async () => {
    expect( ( await bootAndAdvance() ).textContent ).toBe( '←' );
} );

it( 'and right when the page is mirrored', async () => {
    document.documentElement.dir = 'rtl';

    expect( ( await bootAndAdvance() ).textContent ).toBe( '→' );
} );

/** The six admin nav arrows all read through this, so it is pinned directly. */
describe( 'the admin arrows', () => {
    it( 'point the way the page reads', () => {
        expect( forwardGlyph() ).toBe( '→' );
        expect( backGlyph() ).toBe( '←' );
    } );

    it( 'and turn round when it is mirrored', () => {
        document.documentElement.dir = 'rtl';

        expect( forwardGlyph() ).toBe( '←' );
        expect( backGlyph() ).toBe( '→' );
    } );

    it( 'are read at call time, so a dir set after load still counts', () => {
        expect( forwardGlyph() ).toBe( '→' );
        document.documentElement.dir = 'rtl';
        expect( forwardGlyph() ).toBe( '←' );
    } );
} );
