/**
 * A built-in preset arrives at the Brand panel as its whole token map: the
 * values it ships, with the org's edits merged over them. Offering Reset on
 * every key in that map offers it on all fifteen of Quiet's rows on a site
 * that has changed nothing, so the one row the org did change is
 * indistinguishable from the fourteen it did not, and clicking any of the
 * others marks the whole settings group dirty and moves nothing on screen.
 *
 * StylePresets::tokenLayers() answers the same question on the server, as
 * array_diff_assoc(stored, shipped). These are the rows that diff names.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '@wordpress/components', () => {
    const PanelBody = ( { children } ) => <div>{ children }</div>;
    const Button = ( { children, className, onClick } ) => (
        <button type="button" className={ className } onClick={ onClick }>{ children }</button>
    );
    const Control = ( { value, onChange } ) => (
        <input value={ value ?? '' } onChange={ ( e ) => onChange( e.target.value ) } />
    );
    // Both halves, so a case can drive the picker the colour rows actually use.
    const Dropdown = ( { renderToggle, renderContent } ) => (
        <div>{ renderToggle( { isOpen: true, onToggle: () => {} } ) }{ renderContent( { onClose: () => {} } ) }</div>
    );

    return {
        __esModule: true,
        PanelBody,
        Button,
        Dropdown,
        ColorPicker:   ( { onChange } ) => (
            <input className="picker" onInput={ ( e ) => onChange( e.target.value ) } />
        ),
        RangeControl:  Control,
        SelectControl: Control,
        TextControl:   Control,
    };
} );

import { render } from 'preact';

import TokenEditor from '../../assets/admin/_shared/styling/TokenEditor';
import { PresetEditor } from '../../assets/admin/settings/panels/BrandPanel';

/** Tokens.php, verbatim. */
const CATALOGUE = {
    'gratora-accent':        { group: 'brand',    label: 'Accent',           default: '#211d3f', control: 'color' },
    'gratora-accent-soft':   { group: 'brand',    label: 'Accent soft',      default: '#efedf8', control: 'color' },
    'gratora-text':          { group: 'brand',    label: 'Body text',        default: '#111827', control: 'color' },
    'gratora-radius':        { group: 'radius',   label: 'Corner radius',    default: '10px',    control: 'range' },
    'gratora-gap':           { group: 'spacing',  label: 'Block spacing',    default: '20px',    control: 'range' },
    'gratora-button-border': { group: 'buttons',  label: 'Button border',    default: '0',       control: 'select', options: { 0: 'None (filled)', '1px solid currentColor': 'Outline thin' } },
};

const DEFAULTS = Object.fromEntries(
    Object.entries( CATALOGUE ).map( ( [ key, def ] ) => [ key, def.default ] )
);

const GROUPS = { brand: 'Brand colors', radius: 'Radius + borders', spacing: 'Spacing', buttons: 'Buttons' };

/** StylePresets::builtins(), the Quiet subset this catalogue covers. */
const QUIET = {
    'gratora-accent':        '#111827',
    'gratora-accent-soft':   '#f3f4f6',
    'gratora-radius':        '0px',
    'gratora-gap':           '28px',
    'gratora-button-border': '1px solid currentColor',
};

beforeEach( () => {
    document.body.innerHTML = '<div id="root"></div>';
    window.gratora = { styling: { catalogue: CATALOGUE, groups: GROUPS, defaults: DEFAULTS } };
} );

afterEach( () => {
    delete window.gratora;
} );

/**
 * What the panel hands the editor for a built-in: the shipped map with the
 * org's edits merged over it, the way brandPresets.mergePresets builds it and
 * StylePresets::all() rebuilds it on the server.
 */
function mountPreset( { edits = {}, builtin = true, shipped = QUIET } = {} ) {
    const onTokens = jest.fn();
    const host = document.getElementById( 'root' );
    const base = builtin ? shipped : {};

    render(
        <PresetEditor
            preset={ { id: builtin ? 'quiet' : 'house', name: 'Quiet', tokens: { ...base, ...edits }, builtin } }
            resetDefaults={ { ...DEFAULTS, ...base } }
            base={ base }
            isDefault={ false }
            onRename={ () => {} }
            onTokens={ onTokens }
            onMakeDefault={ () => {} }
            onClone={ () => {} }
            onDelete={ () => {} }
        />,
        host
    );

    return { host, onTokens };
}

const row = ( host, label ) =>
    [ ...host.querySelectorAll( '.gratora-token-editor__row' ) ].find(
        ( r ) => r.querySelector( '.gratora-token-editor__label' ).textContent === label
    );

const resettable = ( host ) =>
    [ ...host.querySelectorAll( '.gratora-token-editor__row' ) ]
        .filter( ( r ) => r.querySelector( '.gratora-token-editor__reset' ) )
        .map( ( r ) => r.querySelector( '.gratora-token-editor__label' ).textContent );

const reset = ( host, label ) => row( host, label ).querySelector( '.gratora-token-editor__reset' ).click();

it( 'offers nothing to reset on a built-in the org has not touched', () => {
    const { host } = mountPreset();

    expect( resettable( host ) ).toEqual( [] );
} );

it( 'offers it on the one row the org changed', () => {
    const { host } = mountPreset( { edits: { 'gratora-accent': '#c62828', 'gratora-gap': '32px' } } );

    expect( resettable( host ) ).toEqual( [ 'Accent', 'Block spacing' ] );
} );

/**
 * A key the built-in does not ship is the org's whichever value it holds, so
 * the row offers Reset there too. array_diff_assoc keeps it for the same
 * reason: shipped has no entry to match.
 */
it( 'offers it on a key the built-in does not ship', () => {
    const { host } = mountPreset( { edits: { 'gratora-text': '#333333' } } );

    expect( resettable( host ) ).toEqual( [ 'Body text' ] );
} );

it( 'restores the value the built-in ships, not the catalogue default', () => {
    const { host, onTokens } = mountPreset( { edits: { 'gratora-accent': '#c62828' } } );

    reset( host, 'Accent' );

    expect( onTokens ).toHaveBeenCalledTimes( 1 );
    expect( onTokens.mock.calls[ 0 ][ 0 ][ 'gratora-accent' ] ).toBe( '#111827' );
} );

it( 'leaves the rest of the map alone', () => {
    const { host, onTokens } = mountPreset( { edits: { 'gratora-accent': '#c62828', 'gratora-gap': '32px' } } );

    reset( host, 'Accent' );

    expect( onTokens.mock.calls[ 0 ][ 0 ] ).toEqual( { ...QUIET, 'gratora-gap': '32px' } );
} );

/**
 * A custom preset has nothing beneath it but the catalogue, so every value in
 * it is the org's and Reset drops the key entirely.
 */
it( 'drops the key on a custom preset, where the catalogue is the layer beneath', () => {
    const { host, onTokens } = mountPreset( { builtin: false, edits: { 'gratora-accent': '#c62828' } } );

    expect( resettable( host ) ).toEqual( [ 'Accent' ] );

    reset( host, 'Accent' );

    expect( onTokens.mock.calls[ 0 ][ 0 ] ).not.toHaveProperty( 'gratora-accent' );
} );

/**
 * The campaign panel passes only the campaign's own overrides as `value`, with
 * the chosen preset's effective tokens as `defaults` and no layer inside
 * `value` at all. Every key there is an override and Reset drops it.
 */
describe( 'a call site whose value carries overrides only', () => {
    const mountInline = ( value ) => {
        const onChange = jest.fn();
        const host = document.getElementById( 'root' );

        render(
            <TokenEditor
                value={ value }
                defaults={ { ...DEFAULTS, ...QUIET } }
                onChange={ onChange }
                catalogue={ CATALOGUE }
                groups={ GROUPS }
            />,
            host
        );

        return { host, onChange };
    };

    it( 'offers Reset on each of them', () => {
        const { host } = mountInline( { 'gratora-accent': '#c62828' } );

        expect( resettable( host ) ).toEqual( [ 'Accent' ] );
    } );

    it( 'offers it even when the override repeats the preset', () => {
        const { host } = mountInline( { 'gratora-accent': '#111827' } );

        expect( resettable( host ) ).toEqual( [ 'Accent' ] );
    } );

    it( 'drops the key rather than pinning a value', () => {
        const { host, onChange } = mountInline( { 'gratora-accent': '#c62828' } );

        reset( host, 'Accent' );

        expect( onChange ).toHaveBeenCalledWith( {} );
    } );
} );

/**
 * The built-ins ship uppercase hex and the colour control writes lowercase, so
 * dialling a token back to the value it already had read as a change: a Reset
 * link on a row nobody touched, and a stored override that means nothing.
 * dropStalePairs compares the same colours case-insensitively for the same
 * reason.
 */
describe( 'a colour typed back in the other case', () => {
    it( 'is not an override', () => {
        const { host } = mountPreset( { edits: { 'gratora-accent-soft': QUIET[ 'gratora-accent-soft' ].toUpperCase() } } );

        expect( QUIET[ 'gratora-accent-soft' ] ).toMatch( /[a-f]/ );
        expect( resettable( host ) ).toEqual( [] );
    } );

    it( 'is cleared back to the value the built-in ships', () => {
        const { host, onTokens } = mountPreset();

        const picker = row( host, 'Accent soft' ).querySelector( '.picker' );
        picker.value = QUIET[ 'gratora-accent-soft' ].toUpperCase();
        picker.dispatchEvent( new Event( 'input', { bubbles: true } ) );

        expect( onTokens ).toHaveBeenCalled();
        expect( onTokens.mock.calls[ 0 ][ 0 ][ 'gratora-accent-soft' ] ).toBe( QUIET[ 'gratora-accent-soft' ] );
    } );

    it( 'still tells two different colours apart', () => {
        const { host } = mountPreset( { edits: { 'gratora-accent': '#C62828' } } );

        expect( resettable( host ) ).toEqual( [ 'Accent' ] );
    } );

    /** A font stack is not a colour: its case is the author's. */
    it( 'leaves a non-colour compared as stored', () => {
        const { host } = mountPreset( { edits: { 'gratora-gap': '28PX' } } );

        expect( resettable( host ) ).toEqual( [ 'Block spacing' ] );
    } );
} );
