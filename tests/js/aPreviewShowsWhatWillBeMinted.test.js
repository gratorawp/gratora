/**
 * Two things on the Settings screen that counted to one number and printed
 * another.
 *
 * The numbering panel's format card built its sample from the counter 1, so a
 * site that had taken a hundred thousand donations was shown DON-2026-00001
 * directly above the card saying the next one is DON-2026-100817. The padding
 * it illustrated was wrong too: five digits, for a number that needs six.
 *
 * And the Setup banner pluralised its headline and not its subtitle, so one
 * blocker read "1 thing is stopping donations / Until these are fixed". The
 * warnings branch four lines below already counted; this one was missed.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import NumberingPanel from '../../assets/admin/settings/panels/NumberingPanel';
import SetupPanel from '../../assets/admin/settings/panels/SetupPanel';

/**
 * The settings store the panels are handed, with the members they read. Built
 * rather than mounted: useGratoraSettings fetches, and what is under test here
 * is what the panel draws from a record, not how it loads one.
 */
function store( saved ) {
    const value = ( key, fallback ) => {
        const found = String( key ).split( '.' ).reduce(
            ( acc, k ) => ( acc && acc[ k ] !== undefined ? acc[ k ] : undefined ),
            saved
        );

        return found === undefined ? fallback : found;
    };

    return {
        savedRecord:  saved,
        value,
        setValue:     () => {},
        edit:         () => {},
        replace:      () => {},
        discard:      () => {},
        save:         () => {},
        reload:       () => {},
        isDirty:      false,
        isSaving:     false,
        isLoading:    false,
        loadError:    null,
        bind:         ( key, fallback = '' ) => ( { value: value( key, fallback ), onChange: () => {} } ),
        bindNumber:   ( key ) => ( { value: value( key, '' ), onChange: () => {} } ),
        bindCheckbox: ( key ) => ( { checked: !! value( key, false ), onChange: () => {} } ),
    };
}

const NUMBERING = {
    prefixes:     { donation: 'DON', receipt: 'REC' },
    separator:    '-',
    padding:      5,
    include_year: true,
    reset_yearly: true,
};

async function mountNumbering( counters ) {
    document.body.innerHTML = '<div id="root"></div>';
    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path } ) => {
        if ( typeof path === 'string' && path.includes( 'numbering/counters' ) ) {
            return Promise.resolve( counters );
        }
        return Promise.resolve( {} );
    } );

    window.gratora = { can: { manage_options: true } };

    render(
        <NumberingPanel active s={ store( NUMBERING ) } setNotice={ () => {} } />,
        document.getElementById( 'root' )
    );

    await settle();

    return document.querySelector( '.gratora-ref-previews' )?.textContent || '';
}

test( 'the format preview shows the reference that will actually be minted', async () => {
    const text = await mountNumbering( { donation: 100817, receipt: 8 } );

    expect( text ).toContain( 'DON-2026-100817' );
    expect( text ).not.toContain( 'DON-2026-00001' );
} );

test( 'and it pads a counter that is still short', async () => {
    const text = await mountNumbering( { donation: 8, receipt: 8 } );

    expect( text ).toContain( 'DON-2026-00008' );
} );

/** Counters arrive after the first paint, and the endpoint can fail. */
test( 'a site with no counters yet still previews a first reference', async () => {
    const text = await mountNumbering( {} );

    expect( text ).toContain( 'DON-2026-00001' );
} );

// -- the Setup banner ------------------------------------------------------

const READINESS = ( blockers, warnings ) => ( {
    blockers,
    warnings,
    checks: [
        ...Array.from( { length: blockers }, ( _, i ) => ( {
            id: `b${ i }`, group: 'money', status: 'fail', title: `Blocker ${ i }`, detail: '',
        } ) ),
        ...Array.from( { length: warnings }, ( _, i ) => ( {
            id: `w${ i }`, group: 'money', status: 'warn', title: `Warning ${ i }`, detail: '',
        } ) ),
    ],
} );

async function bannerText( blockers, warnings ) {
    document.body.innerHTML = '<div id="root"></div>';
    apiFetch.mockReset();
    apiFetch.mockResolvedValue( READINESS( blockers, warnings ) );
    window.gratora = { can: { manage_options: true } };

    render( <SetupPanel active setNotice={ () => {} } />, document.getElementById( 'root' ) );
    await settle();

    return document.body.textContent || '';
}

test( 'one blocker reads as one thing in both sentences', async () => {
    const text = await bannerText( 1, 0 );

    expect( text ).toContain( '1 thing is stopping donations' );
    expect( text ).toContain( 'Until it is fixed' );
    expect( text ).not.toContain( 'Until they are fixed' );
} );

test( 'several blockers still read as several', async () => {
    const text = await bannerText( 3, 0 );

    expect( text ).toContain( '3 things are stopping donations' );
    expect( text ).toContain( 'Until they are fixed' );
    expect( text ).not.toContain( 'Until it is fixed' );
} );
