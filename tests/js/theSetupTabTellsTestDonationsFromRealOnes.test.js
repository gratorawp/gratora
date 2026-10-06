/**
 * A new install is in test mode, and its Setup tab used to open on "Ready to
 * accept donations" and "donations work" while it could not take a real one.
 * In test mode the headline says which kind of donation it is ready for.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import SetupPanel from '../../assets/admin/settings/panels/SetupPanel';

const { settle } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const ROWS = [
    { id: 'gateway', group: 'money', status: 'warn', label: 'Only test donations can be taken so far' },
    { id: 'mode', group: 'money', status: 'warn', label: 'Test mode is on for every form' },
];

async function headline( report ) {
    apiFetch.mockResolvedValue( { checks: ROWS, live: true, blockers: 0, warnings: 2, test_mode: false, ...report } );

    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <SetupPanel active onJumpTo={ () => {} } />, root );
    await settle();

    return {
        title: root.querySelector( '.gratora-readiness__title' ).textContent,
        sub:   root.querySelector( '.gratora-readiness__sub' ).textContent,
        tone:  root.querySelector( '.gratora-readiness__head' ).className,
    };
}

beforeEach( () => {
    document.body.innerHTML = '';
    apiFetch.mockReset();
} );

test( 'a site in test mode is ready for test donations, and is told what to look at before going live', async () => {
    expect( await headline( { test_mode: true } ) ).toEqual( {
        title: 'Ready to accept test donations',
        sub:   '2 things are worth a look before you turn test mode off.',
        tone:  'gratora-readiness__head is-amber',
    } );
} );

test( 'a live site with the same count is still ready to accept donations', async () => {
    expect( await headline( { test_mode: false } ) ).toEqual( {
        title: 'Ready to accept donations',
        sub:   '2 things are worth a look, but donations work.',
        tone:  'gratora-readiness__head is-amber',
    } );
} );

test( 'something that stops donations is said first, in test mode as out of it', async () => {
    const said = await headline( { test_mode: true, blockers: 1 } );

    expect( said.title ).toBe( '1 thing is stopping donations' );
    expect( said.tone ).toBe( 'gratora-readiness__head is-red' );
} );
