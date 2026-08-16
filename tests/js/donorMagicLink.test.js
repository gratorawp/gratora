/**
 * What the donor profile tells staff about a sign-in link they mint.
 *
 * The copy is the only place an admin learns how long the credential they are
 * about to hand over stays live and whether it can be taken back, and no PHP
 * test can read it.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

import IdentityCard from '../../assets/admin/donors/profile/IdentityCard';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
// Webpack aliases react to preact/compat for the admin bundles, and this card
// is only renderable under that alias: @wordpress/element's hooks and
// lucide-react's forwardRef icons both come through react. Scoped to this file
// so the suites that drive real React keep it.
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );

const THIRTY_DAYS = 30 * 24 * 60 * 60 * 1000;

function donor( overrides = {} ) {
    return {
        id:                4,
        name:              'Alice Okafor',
        email:             'alice@example.test',
        donor_type:        'individual',
        segment:           'loyal',
        is_anonymous:      false,
        redacted_at:       null,
        first_donation_at: null,
        last_donation_at:  null,
        ...overrides,
    };
}

function mount( props ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( <IdentityCard donor={ props } />, document.getElementById( 'root' ) );
}

function text() {
    return document.getElementById( 'root' ).textContent;
}

function clickButton( label ) {
    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === label );
    expect( button ).toBeTruthy();
    button.click();

    return new Promise( ( r ) => setTimeout( r, 20 ) );
}

// UTC so the assertion does not depend on the box the suite runs on.
function mysqlUtc( ms ) {
    return new Date( ms ).toISOString().slice( 0, 19 ).replace( 'T', ' ' );
}

beforeEach( () => {
    apiFetch.mockReset();
} );

describe( 'the sign-in link a rep mints from a donor profile', () => {
    test( 'the screen states the deadline the server issued, not a number of its own', async () => {
        const expiresAt = mysqlUtc( Date.now() + THIRTY_DAYS );
        apiFetch.mockResolvedValue( {
            magic_link_url: 'https://example.test/portal/?token=abc',
            expires_at:     expiresAt,
        } );

        mount( donor() );
        await clickButton( 'Create a sign-in link' );

        // Rendered from expires_at, in the reader's zone.
        const shown = new Date( expiresAt + 'Z' ).toLocaleString( undefined, {
            month: 'short', day: '2-digit', year: 'numeric',
            hour: '2-digit', minute: '2-digit',
        } );

        expect( text() ).toContain( 'https://example.test/portal/?token=abc' );
        expect( text() ).toContain( shown );
    } );

    test( 'a link with no stated deadline does not invent one', async () => {
        apiFetch.mockResolvedValue( { magic_link_url: 'https://example.test/portal/?token=abc' } );

        mount( donor() );
        await clickButton( 'Create a sign-in link' );

        expect( text() ).toContain( 'Works once.' );
        expect( text() ).not.toMatch( /\d+ days/ );
    } );

    test( 'staff are told the donor can revoke it', async () => {
        apiFetch.mockResolvedValue( {
            magic_link_url: 'https://example.test/portal/?token=abc',
            expires_at:     mysqlUtc( Date.now() + THIRTY_DAYS ),
        } );

        mount( donor() );
        await clickButton( 'Create a sign-in link' );

        expect( text() ).toContain( 'can revoke it' );
        expect( text() ).not.toContain( 'cannot be revoked' );
    } );

    test( 'before one exists, the card promises no sign-in length at all', () => {
        mount( donor() );

        expect( text() ).toContain( 'Create one only when they have asked.' );
        expect( text() ).not.toMatch( /\d+ days/ );
    } );
} );
