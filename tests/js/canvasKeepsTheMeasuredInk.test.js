/**
 * The Develop canvas paints the accent grounds itself and reads the ink back
 * with a white fallback, so a canvas that emits no derived ink draws a white
 * label on a pale accent while the published form draws a dark one.
 *
 * The currency switcher, the recurring toggle and the submit button are the
 * three blocks that consume --fundkit-on-accent.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// @wordpress/interface subscribes to breakpoints the moment the editor module
// is imported, and jsdom ships no matchMedia, so the stub has to be in place
// before the require: an import statement would be hoisted above it.
window.matchMedia = () => ( {
    matches: false, addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
} );

const { canvasStyle } = require( '../../assets/admin/forms/Editor' );

const styling = {
    defaults:   { 'fundkit-accent': '#211d3f', 'fundkit-accent-soft': '#efedf8' },
    presets:    [ { id: 'sunny', tokens: { 'fundkit-accent': '#ffd400' } } ],
    default_id: 'classic',
};

it( 'draws on a pale accent the ink the published form draws', () => {
    const sx = canvasStyle( { style: { preset_id: 'sunny' } }, null, styling );

    expect( sx[ '--fundkit-accent' ] ).toBe( '#ffd400' );
    expect( sx[ '--fundkit-on-accent' ] ).toBe( '#10162a' );
} );

it( 'and reverses out of a dark one', () => {
    const sx = canvasStyle( { style: { preset_id: '' } }, null, styling );

    expect( sx[ '--fundkit-on-accent' ] ).toBe( '#ffffff' );
} );

it( "measures the campaign's accent when the form picked no preset", () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { tokens: { 'fundkit-accent': '#ffe066' } } },
        styling
    );

    expect( sx[ '--fundkit-on-accent' ] ).toBe( '#10162a' );
} );

it( 'leaves the body ink alone, because the canvas sheet is white', () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { tokens: { 'fundkit-bg': '#101010', 'fundkit-text': '#111827' } } },
        styling
    );

    expect( sx[ '--fundkit-text' ] ).toBe( '#111827' );
} );

// CampaignStyleResolver clears a preset id nothing answers to, and then the
// campaign's own overrides apply again.
it( 'lets the campaign through when the form names a preset that is gone', () => {
    const sx = canvasStyle(
        { style: { preset_id: 'deleted-preset' } },
        { id: 4, style: { tokens: { 'fundkit-accent': '#ffe066' } } },
        styling
    );

    expect( sx[ '--fundkit-accent' ] ).toBe( '#ffe066' );
} );
