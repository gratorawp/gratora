/**
 * The Live preview and the published form are two stylesheets over one token
 * map. A property the preview reads and the form has never heard of is a
 * control that moves nothing: the admin turns Base font or Border width and
 * the preview holds still, so the panel reports a look nobody will ever see.
 *
 * jsdom resolves no var() and cssstyle drops font-size, color and the border
 * shorthand when their value is one, so the pairing is checked against the
 * compiled stylesheets rather than a computed style.
 */

import path from 'path';
import * as sass from 'sass';
import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import StylePreview from '../../assets/admin/_shared/styling/StylePreview';

const pkg = path.dirname( require.resolve( '@gratora/ui/package.json' ) );

const PREVIEW = sass.compile( path.join( pkg, 'src/scss/components/_style-preview.scss' ) ).css;
const RUNTIME = sass.compile( path.join( __dirname, '../../assets/donation-form/runtime.scss' ) ).css;

const reads = ( css ) => new Set(
    [ ...css.matchAll( /var\(\s*(--gratora-[a-z0-9-]+)/g ) ].map( ( m ) => m[ 1 ] )
);

const declares = ( css ) => new Set(
    [ ...css.matchAll( /(?:^|[;{\s])(--gratora-[a-z0-9-]+)\s*:/g ) ].map( ( m ) => m[ 1 ] )
);

const CARRIED = new Set( [ ...reads( RUNTIME ), ...declares( RUNTIME ) ] );

function preview( tokens ) {
    const host = document.createElement( 'div' );
    document.body.appendChild( host );
    render( <StylePreview layer="brand" styling={ { defaults: {} } } tokens={ tokens } />, host );
    return host;
}

it( 'reads no property the published form lacks', () => {
    const orphans = [ ...reads( PREVIEW ) ].filter( ( name ) => ! CARRIED.has( name ) ).sort();

    expect( orphans ).toEqual( [] );
} );

it( 'shows the typography and border-width controls moving', () => {
    const frame = preview( {
        'gratora-typeface':  'Georgia, serif',
        'gratora-type-size': '18px',
        'gratora-stroke':    '3px',
    } ).querySelector( '.gratora-style-preview__frame' );

    for ( const name of [ '--gratora-typeface', '--gratora-type-size', '--gratora-stroke' ] ) {
        expect( frame.style.getPropertyValue( name ).trim() ).not.toBe( '' );
        expect( reads( PREVIEW ) ).toContain( name );
    }
} );

it( 'mounts the preview with no box of its own around it', () => {
    expect( preview( {} ).firstElementChild.className ).toBe( 'gratora-style-preview' );
} );

/**
 * The amount tiles are the loudest surface the two stylesheets share. The
 * published form rests them on the soft ground and inks them against it; a
 * preview that rests them on the page ground makes Soft background look like a
 * control that moves nothing.
 */
it( 'rests the amount tiles on the ground the published form rests them on', () => {
    const rule = ( css, selector ) => {
        const at = css.indexOf( selector );
        return css.slice( at, css.indexOf( '}', at ) );
    };

    const previewTile = rule( PREVIEW, '.gratora-style-preview__amount ' );
    const formTile    = rule( RUNTIME, '.gratora-form__preset ' );

    for ( const name of [ '--gratora-bg-soft', '--gratora-on-soft' ] ) {
        expect( formTile ).toContain( `var(${ name }` );
        expect( previewTile ).toContain( `var(${ name }` );
    }
} );
