
/**
 * The Live preview paints a token map that has not reached the server, so it
 * applies the same derivations or it promises a look the published page will
 * not have: black body text on a ground the page reverses out of, and the
 * shipped tint under an accent the stylesheet mixes its own from.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import StylePreview, { resolveEffectiveTokens } from '../../assets/admin/_shared/styling/StylePreview';

const BOLD = {
    'fundkit-accent':      '#0F3D5C',
    'fundkit-accent-soft': '#dde6ed',
    'fundkit-focus-ring':  '#0F3D5C',
};

const styling = {
    defaults: {
        'fundkit-accent':      '#211d3f',
        'fundkit-accent-soft': '#efedf8',
        'fundkit-focus-ring':  '#211d3f',
        'fundkit-bg':          '#ffffff',
        'fundkit-bg-soft':     '#f8fafb',
        'fundkit-field-bg':    '#ffffff',
        'fundkit-text':        '#111827',
        'fundkit-text-muted':  '#6b7280',
    },
    builtins: [
        { id: 'classic', tokens: { 'fundkit-button-radius': '999px' } },
        { id: 'bold',    tokens: BOLD },
    ],
    presets: [
        { id: 'classic', tokens: { 'fundkit-button-radius': '999px' } },
        { id: 'bold',    tokens: BOLD },
        { id: 'sunny',   tokens: { 'fundkit-accent': '#ffd400' } },
    ],
    default_id: 'classic',
};

function frame( props ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( <StylePreview styling={ styling } { ...props } />, document.getElementById( 'root' ) );

    return document.querySelector( '.fundkit-style-preview__frame' );
}

const read = ( el, name ) => el.style.getPropertyValue( name ).trim();

it( 'draws on a pale accent the ink the published page draws', () => {
    expect( read( frame( { presetId: 'sunny' } ), '--fundkit-on-accent' ) ).toBe( '#10162a' );
} );

it( 'and reverses out of a dark one', () => {
    expect( read( frame( { presetId: 'bold' } ), '--fundkit-on-accent' ) ).toBe( '#ffffff' );
} );

it( 'gives the soft and field grounds ink of their own', () => {
    const el = frame( { tokens: { 'fundkit-field-bg': '#101828' } } );

    expect( read( el, '--fundkit-on-field' ) ).toBe( '#ffffff' );
    expect( read( el, '--fundkit-on-soft' ) ).toBe( '#10162a' );
} );

it( 'measures body ink against a ground the campaign chose', () => {
    const el = frame( { tokens: { 'fundkit-bg': '#101828' } } );

    expect( read( el, '--fundkit-text' ) ).toBe( '#ffffff' );
    expect( read( el, '--fundkit-text-muted' ) ).toBe( 'rgba(255,255,255,.72)' );
} );

it( 'leaves ink a layer chose alone', () => {
    const el = frame( { tokens: { 'fundkit-bg': '#101828', 'fundkit-text': '#ffd400' } } );

    expect( read( el, '--fundkit-text' ) ).toBe( '#ffd400' );
} );

/** A tint belongs to the accent it was chosen beside; the stylesheet mixes the rest. */
it( 'drops a tint chosen beside an accent the campaign repainted', () => {
    const el = frame( { presetId: 'bold', tokens: { 'fundkit-accent': '#ffd400' } } );

    expect( read( el, '--fundkit-accent-soft' ) ).toBe( '' );
    expect( read( el, '--fundkit-focus-ring' ) ).toBe( '' );
} );

it( 'keeps the tint the preset chose beside the accent that resolved', () => {
    const el = frame( { presetId: 'bold' } );

    expect( read( el, '--fundkit-accent-soft' ) ).toBe( '#dde6ed' );
    expect( read( el, '--fundkit-focus-ring' ) ).toBe( '#0F3D5C' );
} );

it( 'never pins the shipped tint under an accent nothing paired it with', () => {
    const el = frame( { presetId: 'sunny' } );

    expect( read( el, '--fundkit-accent-soft' ) ).toBe( '' );
} );

/** #ed1212 sits just above the flip, so it is the case a wrong constant loses first. */
it( 'drops a built-in tint when the brand panel repaints that preset', () => {
    const el = frame( {
        layer:    'brand',
        presetId: 'bold',
        tokens:   { ...BOLD, 'fundkit-accent': '#ed1212' },
    } );

    expect( read( el, '--fundkit-accent-soft' ) ).toBe( '' );
    expect( read( el, '--fundkit-on-accent' ) ).toBe( '#10162a' );
} );

// StylePresets::tokensFor hands a campaign naming a deleted preset the org
// default, not the bare catalogue.
it( 'shows the org default when the named preset is gone', () => {
    expect( read( frame( { presetId: 'deleted-preset' } ), '--fundkit-button-radius' ) ).toBe( '999px' );
} );

/**
 * The campaign token editor reads its baseline from the authored cascade: a
 * control whose value the resolver drops still has to show what the preset
 * chose.
 */
it( 'leaves the token editor its baseline', () => {
    const base = resolveEffectiveTokens( { presetId: 'bold', tokens: { 'fundkit-accent': '#ffd400' }, styling } );

    expect( base[ 'fundkit-accent-soft' ] ).toBe( '#dde6ed' );
} );
