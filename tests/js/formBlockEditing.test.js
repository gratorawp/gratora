/**
 * Four things an author does in the form editor that the editor got wrong: the
 * last gateway, a field named only by its label, a snake_case key typed one
 * character at a time, and a minimum nothing can satisfy.
 */

import { render } from 'preact';

const { waitFor } = require( './support/waitFor' );

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// The condition panel reads the block-editor store, which is not what these
// cases are about and needs the whole editor to exist.
jest.mock( '../../assets/admin/forms/blocks/_shared/condition', () => ( {
    DEFAULT_CONDITION: { enabled: false },
    ConditionPanel: () => null,
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
    useBlockProps: () => ( {} ),
    InspectorControls: ( { children } ) => children,
    RichText: ( { value } ) => <span>{ value }</span>,
} ) );

jest.mock( '@wordpress/components', () => {
    const field = ( { label, value, onChange, onBlur, disabled, help, type = 'text', min, max } ) => (
        <label>
            <span>{ label }</span>
            <input
                aria-label={ label }
                type={ type }
                min={ min }
                max={ max }
                disabled={ disabled }
                value={ value === undefined || value === null ? '' : String( value ) }
                onInput={ ( e ) => onChange( type === 'range' ? Number( e.target.value ) : e.target.value ) }
                onBlur={ onBlur }
            />
            { help && <small>{ help }</small> }
        </label>
    );

    return {
        PanelBody:     ( { children } ) => children,
        TextControl:   field,
        TextareaControl: field,
        SelectControl: ( { label, value, options = [], onChange } ) => (
            <select
                aria-label={ label }
                value={ value }
                onChange={ ( e ) => onChange( e.target.value ) }
            >
                { options.map( ( o ) => (
                    <option key={ o.value } value={ o.value }>{ o.label }</option>
                ) ) }
            </select>
        ),
        ToggleControl: ( { label, checked, onChange, disabled, help } ) => (
            <label>
                <span>{ label }</span>
                <input
                    aria-label={ label }
                    type="checkbox"
                    disabled={ disabled }
                    checked={ !! checked }
                    onChange={ () => onChange( ! checked ) }
                />
                { help && <small>{ help }</small> }
            </label>
        ),
        CheckboxControl: ( { label, checked, onChange } ) => (
            <input aria-label={ label } type="checkbox" checked={ !! checked } onChange={ () => onChange( ! checked ) } />
        ),
        Button:        ( { children, onClick } ) => <button type="button" onClick={ onClick }>{ children }</button>,
        Notice:        ( { children } ) => <div>{ children }</div>,
        Spinner:       () => null,
        ExternalLink:  ( { children } ) => children,
        RangeControl:  ( p ) => field( { ...p, type: 'range' } ),
    };
} );

// Deliberately not type=range: jsdom clamps a range input's own value to its
// max, which would hide whether the component clamps at all.
jest.mock( '../../assets/admin/_shared/components/Slider', () => ( {
    __esModule: true,
    default: ( { label, value, onChange, min, max } ) => (
        <input
            aria-label={ label }
            type="text"
            data-min={ String( min ) }
            data-max={ String( max ) }
            value={ String( value ) }
            onInput={ ( e ) => onChange( Number( e.target.value ) ) }
        />
    ),
} ) );

let root = null;

/** Render a block's edit and feed every setAttributes back in, as the editor does. */
function mountBlock( registerDefault, initial ) {
    let settings = null;
    registerDefault( { register: ( name, s ) => { settings = s; } } );

    let attributes = { ...initial };
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );

    const Edit = settings.edit;
    const draw = () => render(
        <Edit
            attributes={ attributes }
            setAttributes={ ( patch ) => { attributes = { ...attributes, ...patch }; draw(); } }
        />,
        root
    );
    draw();

    return { attrs: () => attributes };
}

const input = ( label ) => document.querySelector( `[aria-label="${ label }"]` );

const type = ( label, text ) => {
    let sofar = '';
    for ( const ch of text ) {
        sofar += ch;
        const el = input( label );
        el.value = el.value + ch;
        el.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    }
};

beforeEach( () => {
    document.body.innerHTML = '';
    delete window.fundkitFormsEditor;
} );

describe( 'the payment gateways block', () => {
    const block = () => require( '../../assets/admin/forms/blocks/payment-gateways/index' ).default;

    it( 'will not let the last allowed gateway be turned off', () => {
        window.fundkitFormsEditor = { gateways: [
            { id: 'stripe', label: 'Stripe', enabled: true },
            { id: 'paypal', label: 'PayPal', enabled: true },
        ] };

        const { attrs } = mountBlock( block(), { allowed: [ 'paypal' ] } );

        const paypal = input( 'PayPal' );
        expect( paypal.disabled ).toBe( true );

        paypal.click();
        expect( attrs().allowed ).toEqual( [ 'paypal' ] );
    } );

    it( 'still lets one be turned off while others remain', () => {
        window.fundkitFormsEditor = { gateways: [
            { id: 'stripe', label: 'Stripe', enabled: true },
            { id: 'paypal', label: 'PayPal', enabled: true },
        ] };

        const { attrs } = mountBlock( block(), { allowed: [] } );

        input( 'Stripe' ).click();
        expect( attrs().allowed ).toEqual( [ 'paypal' ] );
    } );
} );

describe( 'a field name typed one character at a time', () => {
    const block = () => require( '../../assets/admin/forms/blocks/text-input/index' ).default;

    it( 'keeps the separator', () => {
        const { attrs } = mountBlock( block(), { label: 'Name', field: '' } );

        type( 'Field name', 'first_name' );

        expect( attrs().field ).toBe( 'first_name' );
    } );

    it( 'shows the separator while it is still trailing', () => {
        mountBlock( block(), { label: 'Name', field: '' } );

        type( 'Field name', 'first_' );

        expect( input( 'Field name' ).value ).toBe( 'first_' );
    } );

    it( 'commits a strict key, never a trailing separator', () => {
        const { attrs } = mountBlock( block(), { label: 'Name', field: '' } );

        type( 'Field name', 'first_' );

        expect( attrs().field ).toBe( 'first' );
    } );
} );

describe( 'multi-select limits', () => {
    const block = () => require( '../../assets/admin/forms/blocks/multi-select/index' ).default;

    const oneOption = { options: [ { label: 'Only', value: 'only' } ], minSelections: 0, maxSelections: 0 };

    const drag = ( label, to ) => {
        const el = input( label );
        el.value = String( to );
        el.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
    };

    it( 'cannot ask for more than there is to pick', () => {
        const { attrs } = mountBlock( block(), oneOption );

        expect( input( 'Minimum selections' ).getAttribute( 'data-max' ) ).toBe( '1' );

        drag( 'Minimum selections', 5 );

        expect( attrs().minSelections ).toBe( 1 );
    } );

    it( 'follows the minimum down when an option is removed', () => {
        const { attrs } = mountBlock( block(), {
            options: [ { label: 'a', value: 'a' }, { label: 'b', value: 'b' }, { label: 'c', value: 'c' } ],
            minSelections: 3,
            maxSelections: 0,
        } );

        const remove = [ ...document.querySelectorAll( 'button' ) ]
            .filter( ( b ) => /remove|delete|×/i.test( b.textContent.trim() ) );
        expect( remove.length ).toBeGreaterThan( 0 );
        remove[ 0 ].click();

        expect( attrs().options.length ).toBe( 2 );
        expect( attrs().minSelections ).toBe( 2 );
    } );

    it( 'never leaves a minimum above the maximum', () => {
        const { attrs } = mountBlock( block(), {
            options: [ { label: 'a', value: 'a' }, { label: 'b', value: 'b' }, { label: 'c', value: 'c' } ],
            minSelections: 0,
            maxSelections: 2,
        } );

        drag( 'Minimum selections', 3 );

        expect( attrs().minSelections ).toBeLessThanOrEqual( attrs().maxSelections );
    } );
} );
