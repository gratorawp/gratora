/**
 * The three previews at the top of the Numbering card are the only description
 * of the scheme an operator hands an accountant. The generator strips anything
 * outside its alphabet before minting, so a preview drawn from the raw value
 * describes references the site will never issue.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import NumberingPanel from '../../assets/admin/settings/panels/NumberingPanel';

/** A settings hook holding exactly $record, with nothing pending. */
const hook = ( record ) => {
    const read = ( key, fallback ) => key.split( '.' ).reduce(
        ( acc, part ) => ( acc && acc[ part ] !== undefined && acc[ part ] !== null ? acc[ part ] : undefined ),
        record,
    ) ?? fallback;

    return {
        record,
        savedRecord: record,
        value:       read,
        setValue:    () => () => {},
        isDirty:     false,
        bind:        ( key, fallback = '' ) => ( { value: read( key, fallback ), onChange: () => {} } ),
        bindNumber:  ( key ) => ( { value: read( key, '' ), onChange: () => {} } ),
        bindCheckbox: ( key ) => ( { checked: !! read( key, false ), onChange: () => {} } ),
    };
};

function mount( record ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( <NumberingPanel s={ hook( record ) } active={ false } />, document.getElementById( 'root' ) );
    return document.getElementById( 'root' );
}

const previews = ( root ) =>
    [ ...root.querySelectorAll( '.dono-ref-preview__value' ) ].map( ( n ) => n.textContent );

beforeEach( () => {
    apiFetch.mockReset();
    apiFetch.mockImplementation( () => Promise.resolve( {} ) );
} );

test( 'a separator the generator would strip is not previewed as one', () => {
    const root = mount( {
        separator: '.', padding: 5, include_year: true,
        prefixes: { donation: 'DONO', receipt: 'REC', refund: 'REF' },
    } );

    expect( previews( root ) ).toContain( 'DONO-2026-00001'.replace( '2026', String( new Date().getFullYear() ) ) );
    expect( previews( root ).join( ' ' ) ).not.toContain( 'DONO.' );
    // A separator of '.' strips to nothing, so both sides agree on the
    // fallback here. The prefix case above is where they used to diverge.
    expect( root.textContent ).toContain( 'Letters, numbers, hyphens and underscores only.' );
} );

test( 'a prefix the generator would strip is not previewed as one', () => {
    const root = mount( {
        separator: '-', padding: 5, include_year: true,
        prefixes: { donation: 'AC/DC', receipt: 'REC', refund: 'REF' },
    } );

    // Asserting only that AC/DC is absent passes whether the preview shows the
    // reference the generator mints or a fallback it never would. The generator
    // strips what it cannot use and keeps the rest, so ACDC is the truth here.
    expect( previews( root ) ).toContain( 'ACDC-' + new Date().getFullYear() + '-00001' );
    expect( previews( root ).join( ' ' ) ).not.toContain( 'DONATION' );
    expect( root.textContent ).toContain( 'Letters, numbers, hyphens and underscores only.' );
} );

test( 'an empty digit count previews the one digit the generator falls back to', () => {
    const root = mount( {
        separator: '-', padding: '', include_year: false,
        prefixes: { donation: 'DONO', receipt: 'REC', refund: 'REF' },
    } );

    expect( previews( root ) ).toContain( 'DONO-1' );
} );

test( 'a scheme the generator accepts is previewed as written', () => {
    const root = mount( {
        separator: '_', padding: 4, include_year: false,
        prefixes: { donation: 'APPEAL', receipt: 'REC', refund: 'REF' },
    } );

    expect( previews( root ) ).toContain( 'APPEAL_0001' );
    expect( root.textContent ).not.toContain( 'Letters, numbers, hyphens and underscores only.' );
} );
