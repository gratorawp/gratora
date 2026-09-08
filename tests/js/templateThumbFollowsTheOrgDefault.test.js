/**
 * The template thumbnail read its own preset out of the styling globals and
 * fell to the bare catalogue for an id nothing answers to, where the server
 * hands a form the org default. fundkit.form.templates lets a site add a
 * template naming any preset, including one it later deleted.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );
jest.mock( '@wordpress/components', () => ( {
    __esModule: true,
    Modal:   ( { children } ) => children,
    Spinner: () => null,
} ) );

import { FormTemplateThumb } from '../../assets/admin/_shared/components/FormTemplatePicker';

const HOUSE = { 'fundkit-accent': '#7c1d1d', 'fundkit-radius': '2px' };

beforeEach( () => {
    window.fundkit = {
        styling: {
            defaults:   { 'fundkit-accent': '#211d3f', 'fundkit-radius': '10px' },
            presets:    [
                { id: 'house', tokens: HOUSE },
                { id: 'bold',  tokens: { 'fundkit-accent': '#0F3D5C', 'fundkit-radius': '6px' } },
            ],
            builtins:   [ { id: 'bold', tokens: { 'fundkit-accent': '#0F3D5C', 'fundkit-radius': '6px' } } ],
            default_id: 'house',
        },
    };
} );

afterEach( () => { delete window.fundkit; } );

const BLOCKS = '<!-- wp:fundkit/donation-amount {"presets":[1000]} /--><!-- wp:fundkit/submit-button /-->';

function paint( settings ) {
    const template = { blocks: BLOCKS, settings };
    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );
    render( <FormTemplateThumb template={ template } />, host );

    return host.querySelector( '.fundkit-template-thumb' ).style.getPropertyValue( '--thumb-accent' ).trim();
}

it( 'paints a template that names no preset in the org default', () => {
    expect( paint( {} ) ).toBe( '#7c1d1d' );
} );

it( 'paints a template that names one in that preset', () => {
    expect( paint( { style: { preset_id: 'bold' } } ) ).toBe( '#0F3D5C' );
} );

it( 'falls back to the org default for a preset nothing answers to', () => {
    expect( paint( { style: { preset_id: 'gone' } } ) ).toBe( '#7c1d1d' );
} );

it( 'survives a page that never shipped the styling globals', () => {
    delete window.fundkit;

    expect( () => paint( {} ) ).not.toThrow();
} );
