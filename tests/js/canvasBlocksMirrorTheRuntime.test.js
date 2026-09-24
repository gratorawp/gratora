/**
 * The Develop canvas draws each block with inline styles that stand in for the
 * runtime stylesheet, under the token map the canvas root carries. A block that
 * reads a different token, or a literal, shows the author a look the published
 * form never paints: grey tiles where the form draws the soft ground, a chip on
 * the card colour with page ink on it, muted grey on a dark track.
 *
 * Each value here is what the browser resolves the block's own declaration to
 * against the canvas map, on the QA brand: a dark card and soft ground under a
 * pale accent, on the white sheet.
 */

import { render } from 'preact';
import { mix } from '../../assets/_shared/ink';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// jsdom drops a var() it cannot parse from background, color and border, so
// every element keeps the style it was rendered with where a query can read it.
jest.mock( 'preact/jsx-runtime', () => {
    const real = jest.requireActual( 'preact/jsx-runtime' );
    const keep = ( create ) => ( type, props, ...rest ) => {
        const kept = typeof type === 'string' && props?.style && typeof props.style === 'object'
            ? { ...props, 'data-sx': JSON.stringify( props.style ) }
            : props;
        return create( type, kept, ...rest );
    };
    return { ...real, jsx: keep( real.jsx ), jsxs: keep( real.jsxs ) };
} );

jest.mock( '@wordpress/api-fetch', () => jest.fn( () => Promise.resolve( { base: 'USD', currencies: [ 'USD', 'EUR', 'GBP', 'CHF' ] } ) ) );

jest.mock( '@wordpress/block-editor', () => ( {
    useBlockProps: ( p ) => p || {},
    InspectorControls: () => null,
    RichText: ( { value, style } ) => <span data-rich="" style={ style }>{ value }</span>,
} ) );

jest.mock( '@wordpress/components', () => ( {
    __esModule: true,
    PanelBody: () => null,
    TextControl: () => null,
    SelectControl: () => null,
    ToggleControl: () => null,
    Notice: () => null,
    Spinner: () => null,
    ExternalLink: () => null,
    Button: () => null,
} ) );

jest.mock( '../../assets/admin/forms/blocks/_shared/condition', () => ( {
    DEFAULT_CONDITION: { enabled: false },
    ConditionPanel: () => null,
} ) );

window.matchMedia = () => ( {
    matches: false, addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
} );

const { waitFor } = require( './support/waitFor' );
const { canvasStyle } = require( '../../assets/admin/forms/Editor' );

/** Tokens::defaults(), verbatim. */
const DEFAULTS = {
    'gratora-accent':        '#211d3f',
    'gratora-accent-soft':   '#efedf8',
    'gratora-text':          '#111827',
    'gratora-text-muted':    '#6b7280',
    'gratora-bg':            '#ffffff',
    'gratora-field-bg':      '#ffffff',
    'gratora-bg-soft':       '#f8fafb',
    'gratora-border':        '#e5e7eb',
    'gratora-radius':        '10px',
    'gratora-radius-sm':     '8px',
    'gratora-stroke':        '1px',
    'gratora-button-size':   '48px',
    'gratora-button-weight': '600',
    'gratora-button-shadow': '0 1px 2px rgba(0,0,0,.08)',
    'gratora-button-border': '0',
    'gratora-focus-ring':    '#211d3f',
};

const QA = {
    'gratora-bg':        '#15142b',
    'gratora-bg-soft':   '#221f3d',
    'gratora-accent':    '#fde68a',
    'gratora-border':    '#3a3660',
    'gratora-radius':    '10px',
    'gratora-radius-sm': '6px',
};

const styling = {
    defaults:   DEFAULTS,
    presets:    [ { id: 'qa-dark-pale', tokens: QA } ],
    builtins:   [],
    default_id: 'qa-dark-pale',
};

/** The canvas root: its inline map, and the tint editor.scss mixes where the map has none. */
function canvasTokens() {
    const sx = canvasStyle( { style: { preset_id: '' } }, null, styling );
    if ( sx[ '--gratora-accent-soft' ] === undefined ) {
        sx[ '--gratora-accent-soft' ] = mix( sx[ '--gratora-accent' ], sx[ '--gratora-bg' ] ?? '#ffffff', 0.12 );
    }
    return sx;
}

/** What a browser does with every var() in a declaration: the property if set, else the fallback. */
function resolve( value, tokens ) {
    let out = String( value );
    for ( let at = out.lastIndexOf( 'var(' ); at !== -1; at = out.lastIndexOf( 'var(' ) ) {
        let end = at + 4;
        for ( let depth = 1; depth > 0; end++ ) {
            if ( out[ end ] === '(' ) depth++;
            if ( out[ end ] === ')' ) depth--;
        }
        end--;
        const [ name, ...rest ] = out.slice( at + 4, end ).split( ',' );
        const fallback = rest.join( ',' ).trim();
        const set = tokens[ name.trim() ];
        out = out.slice( 0, at ) + ( set !== undefined && set !== '' ? set : fallback ) + out.slice( end + 1 );
    }
    return out.trim();
}

/** A number is pixels, as preact writes it, except where the property has no unit. */
const px = ( key, v ) => ( typeof v === 'number' && ! /^(fontWeight|lineHeight|opacity|zIndex|flex|order)$/.test( key ) ? `${ v }px` : String( v ) );

function paint( register, attributes ) {
    let Edit = null;
    register( { register: ( name, settings ) => { Edit = settings.edit; } } );

    document.body.innerHTML = '<div id="root"></div>';
    const host = document.getElementById( 'root' );
    render( <Edit attributes={ attributes } setAttributes={ () => {} } clientId="c1" />, host );

    return host;
}

/** The innermost styled element holding exactly this text. */
function holding( host, text ) {
    const el = [ ...host.querySelectorAll( '[data-sx]' ) ].reverse()
        .find( ( n ) => n.textContent.trim() === text );
    if ( ! el ) throw new Error( `Nothing styled holds "${ text }".` );

    return el;
}

const styleOf = ( host, text, tokens = canvasTokens() ) => styleFrom( holding( host, text ), tokens );

function styleFrom( el, tokens = canvasTokens() ) {
    const raw = JSON.parse( el.getAttribute( 'data-sx' ) );
    return Object.fromEntries( Object.entries( raw ).map( ( [ k, v ] ) => [ k, resolve( px( k, v ), tokens ) ] ) );
}

const recurring = () => require( '../../assets/admin/forms/blocks/recurring-toggle' ).default;
const currency  = () => require( '../../assets/admin/forms/blocks/currency-switcher' ).default;
const submit    = () => require( '../../assets/admin/forms/blocks/submit-button' ).default;
const amount    = () => require( '../../assets/admin/forms/blocks/donation-amount' ).default;

afterEach( () => { document.body.innerHTML = ''; } );

describe( 'the recurring toggle as pills', () => {
    const host = () => paint( recurring(), { style: 'pills', frequencies: [ 'one-time', 'monthly' ], defaultFrequency: 'one-time' } );

    it( 'sits the options in one soft track, as the runtime does', () => {
        const row = styleFrom( holding( host(), 'Monthly' ).parentElement );

        expect( row.display ).toBe( 'inline-flex' );
        expect( row.gap ).toBe( '6px' );
        expect( row.padding ).toBe( '4px' );
        expect( row.background ).toBe( '#221f3d' );
        expect( row.borderRadius ).toBe( '6px' );
    } );

    it( 'draws an unselected option in the soft ground ink, with no fill of its own', () => {
        const option = styleOf( host(), 'Monthly' );

        expect( option.background ).toBe( 'transparent' );
        expect( option.color ).toBe( '#ffffff' );
        expect( option.fontSize ).toBe( '13px' );
        expect( option.fontWeight ).toBe( '500' );
        expect( option.padding ).toBe( '8px 14px' );
        expect( option.borderRadius ).toBe( '6px' );
    } );

    it( 'keeps the selected option on the accent in its measured ink', () => {
        const option = styleOf( host(), 'One-time' );

        expect( option.background ).toBe( '#fde68a' );
        expect( option.color ).toBe( '#10162a' );
    } );
} );

describe( 'the recurring toggle as tabs', () => {
    const host = () => paint( recurring(), { style: 'tabs', frequencies: [ 'one-time', 'monthly' ], defaultFrequency: 'one-time' } );

    it( 'draws the selected tab and its underline in the accent measured on the page', () => {
        const tab = styleOf( host(), 'One-time' );

        expect( tab.color ).toBe( '#111827' );
        expect( tab.borderBottom ).toBe( '2px solid #111827' );
        expect( tab.fontSize ).toBe( '13px' );
        expect( tab.fontWeight ).toBe( '600' );
    } );

    it( 'draws the others in muted page ink at the runtime size and weight', () => {
        const tab = styleOf( host(), 'Monthly' );

        expect( tab.color ).toBe( '#6b7280' );
        expect( tab.fontSize ).toBe( '13px' );
        expect( tab.fontWeight ).toBe( '500' );
    } );
} );

describe( 'the currency switcher', () => {
    async function painted( style ) {
        const host = paint( currency(), { style, currencies: [ 'USD', 'EUR', 'GBP', 'CHF' ] } );
        await waitFor( () => host.textContent.includes( 'USD' ), { what: 'the enabled currencies' } );
        return host;
    }

    it( 'draws the unselected pills in the soft ground muted ink', async () => {
        const host = await painted( 'pills' );

        for ( const code of [ 'EUR', 'GBP', 'CHF' ] ) {
            expect( styleOf( host, code ).color ).toBe( 'rgba(255,255,255,.72)' );
        }
    } );

    it( 'draws the dropdown as a field, in the field ink', async () => {
        const host = await painted( 'dropdown' );
        const chip = styleOf( host, 'USD▾' );

        expect( chip.background ).toBe( '#ffffff' );
        expect( chip.color ).toBe( '#10162a' );
        expect( chip.border ).toBe( '1px solid #3a3660' );
        expect( chip.borderRadius ).toBe( '6px' );
        expect( styleOf( host, '▾' ).color ).toBe( 'rgba(16,22,42,.62)' );
    } );
} );

describe( 'the donate button', () => {
    it( 'reads the button tokens the published button reads', () => {
        const button = styleOf( paint( submit(), { label: 'Donate now' } ), 'Donate now' );

        expect( button.display ).toBe( 'inline-flex' );
        expect( button.alignItems ).toBe( 'center' );
        expect( button.justifyContent ).toBe( 'center' );
        expect( button.minHeight ).toBe( '48px' );
        expect( button.padding ).toBe( '0 22px' );
        expect( button.border ).toBe( '0' );
        expect( button.borderRadius ).toBe( '6px' );
        expect( button.fontWeight ).toBe( '600' );
        expect( button.boxShadow ).toBe( '0 1px 2px rgba(0,0,0,.08)' );
        expect( button.fontSize ).toBe( '14px' );
    } );

    it( 'takes a preset pill radius and outline border', () => {
        const tokens = { ...canvasTokens(), '--gratora-button-radius': '999px', '--gratora-button-border': '1px solid currentColor' };
        const button = styleOf( paint( submit(), { label: 'Donate now' } ), 'Donate now', tokens );

        expect( button.borderRadius ).toBe( '999px' );
        expect( button.border ).toBe( '1px solid currentColor' );
    } );
} );

describe( 'the donation amount tiles', () => {
    const PRESETS = [
        { id: 'a', cents: 2500, impact: 'A week of meals', preselected: false },
        { id: 'b', cents: 5000, impact: 'A month of meals', preselected: true },
    ];
    const tiles = () => {
        const host = paint( amount(), { presets: PRESETS, currency: 'USD' } );
        const impact = ( text ) => [ ...host.querySelectorAll( '[data-rich]' ) ].find( ( n ) => n.textContent === text );

        return {
            resting:        styleFrom( impact( 'A week of meals' ).parentElement ),
            selected:       styleFrom( impact( 'A month of meals' ).parentElement ),
            restingImpact:  styleFrom( impact( 'A week of meals' ) ),
            selectedImpact: styleFrom( impact( 'A month of meals' ) ),
        };
    };

    it( 'paints a tile on the soft ground in its ink', () => {
        const { resting } = tiles();

        expect( resting.background ).toBe( '#221f3d' );
        expect( resting.color ).toBe( '#ffffff' );
    } );

    it( 'paints the selected tile on the tint, in the accent measured on it', () => {
        const { selected } = tiles();

        expect( selected.background ).toBe( '#312d36' );
        expect( selected.color ).toBe( '#fde68a' );
        expect( selected.outline ).toBe( '2px solid #fde68a' );
        expect( selected.outlineOffset ).toBe( '-2px' );
    } );

    it( 'draws the impact line in the soft muted ink, and in the tile ink once selected', () => {
        const { restingImpact, selectedImpact } = tiles();

        expect( restingImpact.color ).toBe( 'rgba(255,255,255,.72)' );
        expect( selectedImpact.color ).toBe( 'inherit' );
    } );
} );
