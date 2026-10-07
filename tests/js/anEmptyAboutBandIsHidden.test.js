/**
 * A campaign with no description shows no "About this campaign" band. Themes
 * differ in how they draw a group block: some wrap what is inside it in a
 * container of their own, and the band has to go in both shapes. In the editor
 * it stays, so it can still be selected and filled in.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const css = fs.readFileSync( path.join( __dirname, '../../assets/campaign-page/page.css' ), 'utf8' );

// jsdom cannot read the whole stylesheet, so it is given the rules that name the band.
const rules = ( css.match( /[^{}]*dp-band--tight[^{}]*\{[^{}]*\}/g ) || [] ).join( '\n' );

const about = ( description ) => `<h2 class="wp-block-heading dp-h2">About this campaign</h2> <p class="dp-body wp-block-paragraph">${ description }</p>`;
const asItIs = ( inside ) => `<div class="wp-block-group dp-band dp-band--tight is-layout-flow wp-block-group-is-layout-flow"> ${ inside } </div>`;
const wrapped = ( inside ) => `<div class="wp-block-group dp-band dp-band--tight"><div class="wp-block-group__inner-container is-layout-flow wp-block-group-is-layout-flow"> ${ inside } </div></div>`;

function shown( html ) {
    document.head.innerHTML = `<style>${ rules }</style>`;
    document.body.innerHTML = html;

    return window.getComputedStyle( document.querySelector( '.dp-band--tight' ) ).display !== 'none';
}

test( 'the rules for the band were found in the stylesheet', () => {
    expect( rules ).toContain( ':empty' );
} );

describe( 'with no description', () => {
    test( 'the band is hidden where the theme draws the group as it is', () => {
        expect( shown( asItIs( about( '' ) ) ) ).toBe( false );
    } );

    test( 'and where the theme wraps what is inside the group', () => {
        expect( shown( wrapped( about( '' ) ) ) ).toBe( false );
    } );

    test( 'in the editor it stays', () => {
        expect( shown( `<div class="editor-styles-wrapper">${ asItIs( about( '' ) ) }</div>` ) ).toBe( true );
    } );
} );

describe( 'with a description', () => {
    test( 'the band shows where the theme draws the group as it is', () => {
        expect( shown( asItIs( about( 'Clean water for six river towns.' ) ) ) ).toBe( true );
    } );

    test( 'and where the theme wraps what is inside the group', () => {
        expect( shown( wrapped( about( 'Clean water for six river towns.' ) ) ) ).toBe( true );
    } );
} );
