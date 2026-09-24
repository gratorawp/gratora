
/**
 * The Live preview paints a token map that has not reached the server, so it
 * applies the same derivations or it promises a look the published page will
 * not have: page ink measured for a card the page never paints, and the
 * shipped tint under an accent the stylesheet mixes its own from.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import StylePreview, { resolveEffectiveTokens } from '../../assets/admin/_shared/styling/StylePreview';

const BOLD = {
    'gratora-accent':      '#0F3D5C',
    'gratora-accent-soft': '#dde6ed',
    'gratora-focus-ring':  '#0F3D5C',
};

const styling = {
    defaults: {
        'gratora-accent':      '#211d3f',
        'gratora-accent-soft': '#efedf8',
        'gratora-focus-ring':  '#211d3f',
        'gratora-bg':          '#ffffff',
        'gratora-bg-soft':     '#f8fafb',
        'gratora-field-bg':    '#ffffff',
        'gratora-text':        '#111827',
        'gratora-text-muted':  '#6b7280',
    },
    builtins: [
        { id: 'classic', tokens: { 'gratora-button-radius': '999px' } },
        { id: 'bold',    tokens: BOLD },
    ],
    presets: [
        { id: 'classic', tokens: { 'gratora-button-radius': '999px' } },
        { id: 'bold',    tokens: BOLD },
        { id: 'sunny',   tokens: { 'gratora-accent': '#ffd400' } },
    ],
    default_id: 'classic',
};

function frame( props ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( <StylePreview styling={ styling } { ...props } />, document.getElementById( 'root' ) );

    return document.querySelector( '.gratora-style-preview__frame' );
}

const read = ( el, name ) => el.style.getPropertyValue( name ).trim();

it( 'draws on a pale accent the ink the published page draws', () => {
    expect( read( frame( { presetId: 'sunny' } ), '--gratora-on-accent' ) ).toBe( '#10162a' );
} );

it( 'and reverses out of a dark one', () => {
    expect( read( frame( { presetId: 'bold' } ), '--gratora-on-accent' ) ).toBe( '#ffffff' );
} );

it( 'gives the soft and field grounds ink of their own', () => {
    const el = frame( { tokens: { 'gratora-field-bg': '#101828' } } );

    expect( read( el, '--gratora-on-field' ) ).toBe( '#ffffff' );
    expect( read( el, '--gratora-on-soft' ) ).toBe( '#10162a' );
} );

/** The page is the theme's, so a card the campaign chose moves the card ink only. */
it( 'keeps the page ink and measures the card ink on a ground the campaign chose', () => {
    const el = frame( { tokens: { 'gratora-bg': '#101828' } } );

    expect( read( el, '--gratora-text' ) ).toBe( '#111827' );
    expect( read( el, '--gratora-text-muted' ) ).toBe( '#6b7280' );
    expect( read( el, '--gratora-on-bg' ) ).toBe( '#ffffff' );
    expect( read( el, '--gratora-on-bg-muted' ) ).toBe( 'rgba(255,255,255,.72)' );
} );

it( 'leaves ink a layer chose alone, and on the card where it reads', () => {
    const el = frame( { tokens: { 'gratora-bg': '#101828', 'gratora-text': '#ffd400' } } );

    expect( read( el, '--gratora-text' ) ).toBe( '#ffd400' );
    expect( read( el, '--gratora-on-bg' ) ).toBe( 'var(--gratora-text)' );
} );

/** A tint belongs to the accent it was chosen beside; the stylesheet mixes the rest. */
it( 'drops a tint chosen beside an accent the campaign repainted', () => {
    const el = frame( { presetId: 'bold', tokens: { 'gratora-accent': '#ffd400' } } );

    expect( read( el, '--gratora-accent-soft' ) ).toBe( '' );
    expect( read( el, '--gratora-focus-ring' ) ).toBe( '' );
} );

it( 'keeps the tint the preset chose beside the accent that resolved', () => {
    const el = frame( { presetId: 'bold' } );

    expect( read( el, '--gratora-accent-soft' ) ).toBe( '#dde6ed' );
    expect( read( el, '--gratora-focus-ring' ) ).toBe( '#0F3D5C' );
} );

it( 'never pins the shipped tint under an accent nothing paired it with', () => {
    const el = frame( { presetId: 'sunny' } );

    expect( read( el, '--gratora-accent-soft' ) ).toBe( '' );
} );

/** #ed1212 sits just under the crossing, where white reads better, so it is the case a wrong crossing loses first. */
it( 'drops a built-in tint when the brand panel repaints that preset', () => {
    const el = frame( {
        layer:    'brand',
        presetId: 'bold',
        tokens:   { ...BOLD, 'gratora-accent': '#ed1212' },
    } );

    expect( read( el, '--gratora-accent-soft' ) ).toBe( '' );
    expect( read( el, '--gratora-on-accent' ) ).toBe( '#ffffff' );
} );

// StylePresets::tokensFor hands a campaign naming a deleted preset the org
// default, not the bare catalogue.
it( 'shows the org default when the named preset is gone', () => {
    expect( read( frame( { presetId: 'deleted-preset' } ), '--gratora-button-radius' ) ).toBe( '999px' );
} );

/**
 * The campaign token editor reads its baseline from the authored cascade: a
 * control whose value the resolver drops still has to show what the preset
 * chose.
 */
it( 'leaves the token editor its baseline', () => {
    const base = resolveEffectiveTokens( { presetId: 'bold', tokens: { 'gratora-accent': '#ffd400' }, styling } );

    expect( base[ 'gratora-accent-soft' ] ).toBe( '#dde6ed' );
} );
