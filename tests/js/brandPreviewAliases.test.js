/**
 * The preview and the catalogue name the same three things differently, so a
 * typeface or a border width the admin picks reaches a variable the preview's
 * stylesheet does not read, and the preview does not move.
 */

import { aliasStyle } from '../../assets/admin/_shared/styling/StylePreview';

const styling = {
    defaults: {
        'fundkit-typeface':  'system-ui, sans-serif',
        'fundkit-type-size': '15px',
        'fundkit-stroke':    '1px',
    },
};

it( 'gives the preview the names its stylesheet reads', () => {
    const style = aliasStyle( { layer: 'brand', tokens: {
        'fundkit-typeface':  'Georgia, serif',
        'fundkit-type-size': '18px',
        'fundkit-stroke':    '3px',
    }, styling } );

    expect( style[ '--fundkit-font-family' ] ).toBe( 'Georgia, serif' );
    expect( style[ '--fundkit-font-size' ] ).toBe( '18px' );
    expect( style[ '--fundkit-border-width' ] ).toBe( '3px' );
} );

it( 'falls back to the catalogue default when the preset says nothing', () => {
    expect( aliasStyle( { layer: 'brand', tokens: {}, styling } )[ '--fundkit-font-family' ] )
        .toBe( 'system-ui, sans-serif' );
} );

/** The wrapper must not become a box, or it lays the preview out differently. */
it( 'adds no layout of its own', () => {
    expect( aliasStyle( { layer: 'brand', tokens: {}, styling } ).display ).toBe( 'contents' );
} );

it( 'survives a page that lost its globals', () => {
    expect( () => aliasStyle( {} ) ).not.toThrow();
} );
