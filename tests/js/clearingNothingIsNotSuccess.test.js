/**
 * Clear stranded records reported "0 stranded records removed." as a success,
 * in the green notice a completed job gets.
 *
 * Zero is reachable two ways and neither is a job done. The card is built from
 * a count fetched earlier, so a second admin who cleared first leaves this one
 * pressing a button with nothing behind it. And an add-on that reports its
 * stranded rows through one action and clears none through the other produces
 * zero every time, forever, with the card never going away: a fault the owner
 * has no other way to see.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import MaintenanceTab from '../../assets/admin/tools/tabs/MaintenanceTab';

const INFO = {
    test_data:             { donations: 0, recurring_plans: 0, donors: 0 },
    pending_upgrades:      [],
    unconverted_donations: [],
    recalc_scopes:         [ { value: 'all', label: 'Everything' } ],
    cron:                  [],
    orphans:               [ { key: 'p2p_rows', label: 'Peer-to-peer rows', count: 2 } ],
};

const notices = [];

/** Mount the tab with the orphans card showing, and clear with the given result. */
async function clearReturning( removed ) {
    notices.length = 0;
    document.body.innerHTML = '<div id="root"></div>';

    apiFetch.mockReset();
    apiFetch.mockImplementation( ( { path } ) => {
        if ( typeof path === 'string' && path.includes( 'clear-orphans' ) ) {
            return Promise.resolve( { removed } );
        }
        return Promise.resolve( INFO );
    } );

    window.gratora = { can: { manage_options: true } };

    render(
        <MaintenanceTab
            active
            info={ INFO }
            infoError={ null }
            loadInfo={ () => {} }
            setNotice={ ( n ) => notices.push( n ) }
        />,
        document.getElementById( 'root' )
    );

    await settle();

    const input = [ ...document.querySelectorAll( 'input[type="text"]' ) ].pop();
    input.value = 'DELETE';
    input.dispatchEvent( new Event( 'input', { bubbles: true } ) );

    await settle();

    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => /remove them/i.test( b.textContent ) && ! b.disabled );

    expect( button ).toBeDefined();
    button.click();

    await settle();

    return notices[ notices.length - 1 ];
}

test( 'clearing nothing is not reported as a success', async () => {
    const notice = await clearReturning( [] );

    expect( notice.type ).not.toBe( 'success' );
} );

test( 'and it says plainly that nothing went', async () => {
    const notice = await clearReturning( [] );

    expect( notice.text ).toMatch( /nothing was removed/i );
    // The count is what made the old sentence read as a job done.
    expect( notice.text ).not.toMatch( /^0 /);
} );

test( 'rows actually removed are still counted and still read as done', async () => {
    const notice = await clearReturning( [ { key: 'p2p_rows', label: 'Peer-to-peer rows', count: 2 } ] );

    expect( notice.type ).toBe( 'success' );
    expect( notice.text ).toMatch( /2 stranded records removed/ );
} );

/** One row is one record, or the sentence reads as a rounding. */
test( 'a single row is singular', async () => {
    const notice = await clearReturning( [ { key: 'p2p_rows', label: 'Peer-to-peer rows', count: 1 } ] );

    expect( notice.type ).toBe( 'success' );
    expect( notice.text ).toMatch( /1 stranded record removed/ );
} );
