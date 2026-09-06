/**
 * Two places an English word was load-bearing.
 *
 * The purge dialog tells the admin to type a word and then compares what they
 * typed against a literal, so a translated instruction is an instruction that
 * cannot be followed. The offline bank-details sample is the one field on a
 * translated Payment gateways tab still written in English, and it is the text
 * an org edits into what its donors are shown.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( {} ) ) );
jest.mock( '../../assets/admin/settings/panels/StripeKeysCard', () => ( { __esModule: true, default: () => null } ) );
jest.mock( '../../assets/admin/settings/panels/PayPalKeysCard', () => ( { __esModule: true, default: () => null } ) );

import { render } from 'preact';
import { setLocaleData } from '@wordpress/i18n';

import MaintenanceTab from '../../assets/admin/tools/tabs/MaintenanceTab';
import GatewaysPanel from '../../assets/admin/settings/panels/GatewaysPanel';

const SAMPLE = 'Account holder: …\nIBAN: …\nBIC: …\nReference: {reference}\nAmount: {amount}';
const GERMAN = 'Kontoinhaber: …\nIBAN: …\nBIC: …\nVerwendungszweck: {reference}\nBetrag: {amount}';

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 20 ) );

function mount( node ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( node, document.getElementById( 'root' ) );
}

beforeEach( () => {
    setLocaleData( {
        '': { plural_forms: 'nplurals=2; plural=(n != 1);' },
        'Type %s to confirm.': [ 'Tapez %s pour confirmer.' ],
        [ SAMPLE ]: [ GERMAN ],
    }, 'fundraising-toolkit' );

    window.fundkit = { can: { manage_options: true } };
} );

afterEach( () => {
    delete window.fundkit;
    document.body.innerHTML = '';
} );

it( 'tells the admin to type the word the button actually accepts', async () => {
    mount( <MaintenanceTab
        info={ { test_data: { donations: 3, recurring_plans: 0, donors: 0 }, pending_upgrades: [], unconverted_donations: [], recalc_scopes: [] } }
        infoError={ false }
        active
        loadInfo={ () => {} }
        setNotice={ () => {} }
    /> );

    const label = [ ...document.querySelectorAll( 'label' ) ]
        .find( ( l ) => /pour confirmer/.test( l.textContent ) );
    expect( label ).toBeTruthy();

    const word = label.textContent.match( /Tapez (\S+) pour confirmer/ )[ 1 ];
    const input = label.querySelector( 'input' );
    const button = [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => /Delete test data/.test( b.textContent ) );

    input.value = word;
    input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
    await settle();

    expect( button.disabled ).toBe( false );
} );

it( 'writes the sample bank details in the language of the screen', () => {
    const s = {
        value:    ( k, f ) => f,
        bind:     () => ( { value: '', onChange: () => {} } ),
        setValue: () => () => {},
        isDirty:  false,
    };

    mount( <GatewaysPanel s={ s } /> );

    const head = document.querySelector( '.fundkit-card__head--toggle' );
    if ( head ) head.dispatchEvent( new MouseEvent( 'click', { bubbles: true } ) );

    const box = document.querySelector( '.fundkit-textarea--mono' );
    expect( box ).toBeTruthy();
    expect( box.placeholder ).toContain( 'Kontoinhaber' );
    expect( box.placeholder ).toContain( '{reference}' );
    expect( box.placeholder ).toContain( '{amount}' );
} );
