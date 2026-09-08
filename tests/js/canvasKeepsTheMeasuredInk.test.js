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

const BOLD = {
    'fundkit-accent':      '#0F3D5C',
    'fundkit-accent-soft': '#dde6ed',
    'fundkit-focus-ring':  '#0F3D5C',
};

// Bold, with the org's own accent painted over it in Settings > Brand.
const orgBrand = {
    defaults: {
        'fundkit-accent':      '#211d3f',
        'fundkit-accent-soft': '#efedf8',
        'fundkit-focus-ring':  '#211d3f',
        'fundkit-bg':          '#ffffff',
        'fundkit-text':        '#111827',
        'fundkit-text-muted':  '#6b7280',
    },
    builtins:   [ { id: 'bold', tokens: BOLD } ],
    presets:    [ { id: 'bold', tokens: { ...BOLD, 'fundkit-accent': '#7c1d1d' } } ],
    default_id: 'bold',
};

it( 'drops a tint the org repainted the accent out from under', () => {
    const sx = canvasStyle( { style: { preset_id: 'bold' } }, null, orgBrand );

    expect( sx[ '--fundkit-accent' ] ).toBe( '#7c1d1d' );
    expect( sx[ '--fundkit-accent-soft' ] ).toBeUndefined();
    expect( sx[ '--fundkit-focus-ring' ] ).toBeUndefined();
} );

it( 'gives a campaign on a preset nothing answers to the org default', () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { preset_id: 'gone' } },
        orgBrand
    );

    expect( sx[ '--fundkit-accent' ] ).toBe( '#7c1d1d' );
} );

it( 'measures no ink against a ground the sheet never paints', () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { tokens: { 'fundkit-bg': '#101828' } } },
        orgBrand
    );

    expect( sx[ '--fundkit-text' ] ).toBe( '#111827' );
    expect( sx[ '--fundkit-text-muted' ] ).toBe( '#6b7280' );
} );

it( 'ignores the campaign when the form is on a preset of its own', () => {
    const sx = canvasStyle(
        { style: { preset_id: 'bold' } },
        { id: 4, style: { tokens: { 'fundkit-accent': '#ffe066' } } },
        orgBrand
    );

    expect( sx[ '--fundkit-accent' ] ).toBe( '#7c1d1d' );
} );

it( 'ignores a form token map the published form does not read', () => {
    const sx = canvasStyle(
        { style: { preset_id: '', tokens: { 'fundkit-accent': '#00ff00' } } },
        null,
        orgBrand
    );

    expect( sx[ '--fundkit-accent' ] ).toBe( '#7c1d1d' );
} );
