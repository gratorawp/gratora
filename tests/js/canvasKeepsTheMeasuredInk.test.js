/**
 * The Develop canvas paints the accent grounds itself and reads the ink back
 * with a white fallback, so a canvas that emits no derived ink draws a white
 * label on a pale accent while the published form draws a dark one.
 *
 * The currency switcher, the recurring toggle and the submit button are the
 * three blocks that consume --gratora-on-accent.
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
    defaults:   { 'gratora-accent': '#211d3f', 'gratora-accent-soft': '#efedf8' },
    presets:    [ { id: 'sunny', tokens: { 'gratora-accent': '#ffd400' } } ],
    default_id: 'classic',
};

it( 'draws on a pale accent the ink the published form draws', () => {
    const sx = canvasStyle( { style: { preset_id: 'sunny' } }, null, styling );

    expect( sx[ '--gratora-accent' ] ).toBe( '#ffd400' );
    expect( sx[ '--gratora-on-accent' ] ).toBe( '#10162a' );
} );

it( 'and reverses out of a dark one', () => {
    const sx = canvasStyle( { style: { preset_id: '' } }, null, styling );

    expect( sx[ '--gratora-on-accent' ] ).toBe( '#ffffff' );
} );

it( "measures the campaign's accent when the form picked no preset", () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { tokens: { 'gratora-accent': '#ffe066' } } },
        styling
    );

    expect( sx[ '--gratora-on-accent' ] ).toBe( '#10162a' );
} );

it( 'leaves the body ink alone, because the canvas sheet is white', () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { tokens: { 'gratora-bg': '#101010', 'gratora-text': '#111827' } } },
        styling
    );

    expect( sx[ '--gratora-text' ] ).toBe( '#111827' );
} );

// CampaignStyleResolver clears a preset id nothing answers to, and then the
// campaign's own overrides apply again.
it( 'lets the campaign through when the form names a preset that is gone', () => {
    const sx = canvasStyle(
        { style: { preset_id: 'deleted-preset' } },
        { id: 4, style: { tokens: { 'gratora-accent': '#ffe066' } } },
        styling
    );

    expect( sx[ '--gratora-accent' ] ).toBe( '#ffe066' );
} );

const BOLD = {
    'gratora-accent':      '#0F3D5C',
    'gratora-accent-soft': '#dde6ed',
    'gratora-focus-ring':  '#0F3D5C',
};

// Bold, with the org's own accent painted over it in Settings > Brand.
const orgBrand = {
    defaults: {
        'gratora-accent':      '#211d3f',
        'gratora-accent-soft': '#efedf8',
        'gratora-focus-ring':  '#211d3f',
        'gratora-bg':          '#ffffff',
        'gratora-text':        '#111827',
        'gratora-text-muted':  '#6b7280',
    },
    builtins:   [ { id: 'bold', tokens: BOLD } ],
    presets:    [ { id: 'bold', tokens: { ...BOLD, 'gratora-accent': '#7c1d1d' } } ],
    default_id: 'bold',
};

it( 'drops a tint the org repainted the accent out from under', () => {
    const sx = canvasStyle( { style: { preset_id: 'bold' } }, null, orgBrand );

    expect( sx[ '--gratora-accent' ] ).toBe( '#7c1d1d' );
    expect( sx[ '--gratora-accent-soft' ] ).toBeUndefined();
    expect( sx[ '--gratora-focus-ring' ] ).toBeUndefined();
} );

it( 'gives a campaign on a preset nothing answers to the org default', () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { preset_id: 'gone' } },
        orgBrand
    );

    expect( sx[ '--gratora-accent' ] ).toBe( '#7c1d1d' );
} );

it( 'measures no ink against a ground the sheet never paints', () => {
    const sx = canvasStyle(
        { style: { preset_id: '' } },
        { id: 4, style: { tokens: { 'gratora-bg': '#101828' } } },
        orgBrand
    );

    expect( sx[ '--gratora-text' ] ).toBe( '#111827' );
    expect( sx[ '--gratora-text-muted' ] ).toBe( '#6b7280' );
} );

it( 'ignores the campaign when the form is on a preset of its own', () => {
    const sx = canvasStyle(
        { style: { preset_id: 'bold' } },
        { id: 4, style: { tokens: { 'gratora-accent': '#ffe066' } } },
        orgBrand
    );

    expect( sx[ '--gratora-accent' ] ).toBe( '#7c1d1d' );
} );

it( 'ignores a form token map the published form does not read', () => {
    const sx = canvasStyle(
        { style: { preset_id: '', tokens: { 'gratora-accent': '#00ff00' } } },
        null,
        orgBrand
    );

    expect( sx[ '--gratora-accent' ] ).toBe( '#7c1d1d' );
} );
