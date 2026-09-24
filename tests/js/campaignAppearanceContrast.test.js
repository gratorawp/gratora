/**
 * A campaign's own appearance card edits the same four grounds the brand panel
 * does, and a saved override paints them on the published page. A ground no
 * ink carries is flagged on the brand screen, so the same value chosen for one
 * campaign has to be flagged here too.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );
jest.mock( '../../assets/admin/_shared/styling/TokenEditor', () => ( { __esModule: true, default: () => null } ) );

import { AppearancePanel } from '../../assets/admin/campaigns/Detail';

beforeEach( () => {
    window.gratora = {
        styling: {
            catalogue:  {},
            groups:     {},
            defaults:   { 'gratora-bg': '#ffffff', 'gratora-bg-soft': '#f8fafb', 'gratora-field-bg': '#ffffff', 'gratora-accent': '#211d3f' },
            presets:    [ { id: 'qa', name: 'QA', tokens: { 'gratora-bg': '#15142b', 'gratora-accent': '#fde68a' } } ],
            default_id: 'qa',
        },
    };
} );
afterEach( () => { delete window.gratora; } );

function mount( style ) {
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );

    const saved = { id: 3, style };
    const c = {
        record: { ...saved },
        savedRecord: saved,
        edits: {},
        isEdited: () => false,
        value: ( k, f = '' ) => c.record[ k ] ?? f,
        edit: () => {},
    };

    render( <AppearancePanel c={ c } />, host );
    return host;
}

const notice = ( host ) => host.querySelector( '.gratora-custom-style-body .gratora-preset-editor__contrast' );

it( 'names a ground the campaign chose that no ink carries, and what it reaches', () => {
    const host = mount( { tokens: { 'gratora-bg': '#777777' } } );

    expect( notice( host )?.textContent ).toContain( 'Background (#777777) reaches 4.0:1, under the 4.5:1 that text needs.' );
} );

it( 'measures a ground the chosen preset brings, under the campaign overrides', () => {
    const host = mount( { preset_id: 'qa', tokens: { 'gratora-bg-soft': '#ed1212' } } );

    expect( notice( host )?.textContent ).toContain( 'Soft background (#ED1212)' );
    expect( notice( host )?.textContent ).not.toContain( 'Background (#15142B)' );
} );

it( 'says nothing about a palette that reads', () => {
    expect( notice( mount( { tokens: {} } ) ) ).toBeNull();
} );
