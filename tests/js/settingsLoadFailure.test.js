/**
 * Every settings field reads its group through a fallback, so a group that
 * never loaded draws a complete, ordinary looking form: Anonymize IPs on,
 * reference prefix DONO, an empty legal name. On a screen about retention and
 * money the operator has to be told that is not what the site holds.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import Settings from '../../assets/admin/settings/Settings';

const settle = () => new Promise( ( r ) => setTimeout( r, 20 ) );

let settingsFail = true;

function seedApi() {
    apiFetch.mockImplementation( ( { path } ) => {
        if ( path.startsWith( '/dono/v1/admin/settings/' ) ) {
            return settingsFail
                ? Promise.reject( new Error( 'Internal server error' ) )
                : Promise.resolve( {} );
        }
        return Promise.resolve( {} );
    } );
}

async function mountOn( tab ) {
    window.location.hash = `#${ tab }`;
    document.body.innerHTML = '<div id="root"></div>';
    render( <Settings />, document.getElementById( 'root' ) );
    await settle();
    return document.getElementById( 'root' );
}

/** The visible tab panel: the others are rendered but hidden. */
const shownPanels = ( root ) =>
    [ ...root.querySelectorAll( '.dono-settings-page__body > div' ) ].filter( ( d ) => ! d.hidden );

beforeEach( () => {
    apiFetch.mockReset();
    settingsFail = true;
    seedApi();
} );

test( 'a privacy group that failed to load shows the failure, not defaults', async () => {
    const root = await mountOn( 'privacy' );
    const shown = shownPanels( root ).map( ( d ) => d.textContent ).join( ' ' );

    expect( shown ).toContain( 'Could not load these settings.' );
    expect( shown ).toContain( 'Retry' );
    expect( shown ).not.toContain( 'Anonymize IPs' );
} );

test( 'a numbering group that failed to load does not advertise a prefix', async () => {
    const root = await mountOn( 'numbering' );
    const shown = shownPanels( root ).map( ( d ) => d.textContent ).join( ' ' );

    expect( shown ).toContain( 'Could not load these settings.' );
    expect( shown ).not.toContain( 'DONO-' );
} );

test( 'Retry asks for the group again', async () => {
    const root = await mountOn( 'organization' );

    const retry = [ ...root.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Retry' );
    expect( retry ).toBeTruthy();

    settingsFail = false;
    const before = apiFetch.mock.calls.filter( ( [ a ] ) => a.path === '/dono/v1/admin/settings/org-profile' ).length;
    retry.click();
    await settle();
    const after = apiFetch.mock.calls.filter( ( [ a ] ) => a.path === '/dono/v1/admin/settings/org-profile' ).length;

    expect( after ).toBeGreaterThan( before );
    expect( shownPanels( root ).map( ( d ) => d.textContent ).join( ' ' ) ).not.toContain( 'Could not load these settings.' );
} );

/**
 * Currency is the one tab whose group is two sources, and the second exposes
 * no reload. Retry has to work there rather than throwing on the click.
 */
test( 'Retry works on a tab whose group has more than one source', async () => {
    const root = await mountOn( 'currency' );

    // Every tab is rendered and the others are only hidden, so the button has
    // to be found inside the visible panel or this reaches another tab's.
    const panel = shownPanels( root )[ 0 ];
    const retry = [ ...panel.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.trim() === 'Retry' );
    expect( retry ).toBeTruthy();

    settingsFail = false;
    const before = apiFetch.mock.calls.length;

    // The second source exposes no reload, so an unguarded call throws here and
    // the only way out of a failed Currency tab is a full page reload.
    expect( () => retry.click() ).not.toThrow();
    await settle();

    expect( apiFetch.mock.calls.length ).toBeGreaterThan( before );
} );
