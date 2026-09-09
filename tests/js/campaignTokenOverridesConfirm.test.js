/**
 * Turning the customize toggle off dropped style.tokens outright. The only way
 * back is the settings tab's shared Discard, which costs every other unsaved
 * change on it, and after a save CampaignService rebuilds style from the
 * payload, so the overrides are gone for good.
 */

import { render } from 'preact';

const { settle } = require( './support/waitFor' );

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { AppearancePanel } from '../../assets/admin/campaigns/Detail';

window.gratora = { styling: {} };

function mount( style ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    const saved = { id: 3, style };
    const c = {
        record: { ...saved, style },
        savedRecord: saved,
        edits: {},
        isEdited: ( k ) => c.edits[ k ] !== undefined,
        value: ( k, f = '' ) => c.record[ k ] ?? f,
        edit: jest.fn( ( patch ) => {
            Object.assign( c.record, patch );
            Object.assign( c.edits, patch );
            draw();
        } ),
    };

    const draw = () => render( <AppearancePanel c={ c } />, host );
    draw();

    return { host, c };
}

// The customize toggle is the first checkbox in the panel; the hide-header and
// hide-footer switches are in the card after it.
const toggleOff = ( host ) => {
    const box = host.querySelector( 'input[type=checkbox]' );
    box.checked = false;
    box.dispatchEvent( new Event( 'change', { bubbles: true } ) );
};

const buttonSaying = ( host, text ) =>
    [ ...host.querySelectorAll( 'button' ) ].find( ( b ) => b.textContent.includes( text ) );

it( 'asks before dropping the overrides the toggle would discard', async () => {
    const { host, c } = mount( { preset_id: 'bold', tokens: { 'gratora-accent': '#ff0000' } } );

    toggleOff( host );
    await settle();

    expect( c.edit ).not.toHaveBeenCalled();
    expect( c.record.style.tokens[ 'gratora-accent' ] ).toBe( '#ff0000' );
    expect( host.textContent ).toContain( '1 token this campaign overrides' );
} );

it( 'discards them only once the admin confirms', async () => {
    const { host, c } = mount( { preset_id: 'bold', tokens: { 'gratora-accent': '#ff0000' } } );

    toggleOff( host );
    await settle();
    buttonSaying( host, 'Discard overrides' ).click();
    await settle();

    expect( c.edit ).toHaveBeenCalledWith( { style: { preset_id: 'bold' } } );
} );

it( 'closes straight through when there is nothing to lose', async () => {
    const { host, c } = mount( { preset_id: 'bold', tokens: {} } );

    toggleOff( host );
    await settle();

    expect( c.edit ).toHaveBeenCalledWith( { style: { preset_id: 'bold' } } );
    expect( host.textContent ).not.toContain( 'Discard overrides' );
} );
