/**
 * The card exists to be absent.
 *
 * It reports records no other screen can show, so it has to appear when there
 * are some and say nothing at all when there are none: a maintenance card that
 * is always there is scrolled past, and this one is the only warning that a
 * donor's name and postcode are sitting somewhere nothing can reach.
 */

import { render } from 'preact';

import MaintenanceTab from '../../assets/admin/tools/tabs/MaintenanceTab';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const ORPHANS = [
    { key: 'gift_aid_claims', label: 'Gift Aid claims whose donation or donor no longer exists.', count: 3 },
    { key: 'p2p_layout_pages', label: 'Published layout pages left behind by a deleted campaign.', count: 6 },
];

function mount( info ) {
    document.body.innerHTML = '<div id="root"></div>';
    const root = document.getElementById( 'root' );
    render(
        <MaintenanceTab
            info={ info }
            infoError={ null }
            active
            loadInfo={ () => {} }
            setNotice={ () => {} }
        />,
        root
    );
    return root;
}

beforeEach( () => {
    window.gratora = { can: { manage_options: true } };
} );

it( 'says nothing on a site with nothing stranded', () => {
    mount( { orphans: [] } );

    expect( document.body.textContent ).not.toContain( 'Records left behind' );
} );

it( 'names each kind and how many, when there are some', () => {
    mount( { orphans: ORPHANS } );

    const shown = document.body.textContent;
    expect( shown ).toContain( 'Records left behind' );
    expect( shown ).toContain( 'Gift Aid claims whose donation or donor no longer exists.' );
    expect( shown ).toContain( '6' );
} );

it( 'will not clear them until the word is typed', () => {
    mount( { orphans: ORPHANS } );

    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => /remove them/i.test( b.textContent ) );

    expect( button ).toBeTruthy();
    expect( button.disabled ).toBe( true );
} );
