/**
 * A field named only by its label still has a runtime key: the server derives
 * it from the label. The editor's "Show this when" list read the explicit key
 * alone, so those fields could not be targeted at all.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const editorBlocks = { list: [], selected: 'self' };

jest.mock( '@wordpress/data', () => ( {
    useSelect: ( mapper ) => mapper( () => ( {
        getSelectedBlockClientId: () => editorBlocks.selected,
        getBlocks: () => editorBlocks.list,
    } ) ),
} ) );

jest.mock( '@wordpress/components', () => ( {
    PanelBody:     ( { children } ) => children,
    ToggleControl: () => null,
    TextControl:   () => null,
    SelectControl: ( { label, value, options = [], onChange } ) => (
        <select aria-label={ label } value={ value } onChange={ ( e ) => onChange( e.target.value ) }>
            { options.map( ( o ) => <option key={ o.value } value={ o.value }>{ o.label }</option> ) }
        </select>
    ),
} ) );

const { ConditionPanel, DEFAULT_CONDITION } = require( '../../assets/admin/forms/blocks/_shared/condition' );

let root = null;

function mount() {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <ConditionPanel condition={ DEFAULT_CONDITION } onChange={ () => {} } />, root );
}

const sourceValues = () => [ ...document.querySelectorAll( 'option' ) ].map( ( o ) => o.value );
const sourceLabels = () => [ ...document.querySelectorAll( 'option' ) ].map( ( o ) => o.textContent );

beforeEach( () => {
    document.body.innerHTML = '';
    editorBlocks.list = [];
    editorBlocks.selected = 'self';
} );

it( 'offers a field that is named only by its label', () => {
    editorBlocks.list = [
        { clientId: 'a', name: 'fundkit/dropdown', attributes: { label: 'T-shirt size' } },
        { clientId: 'self', name: 'fundkit/text-input', attributes: {} },
    ];

    mount();

    expect( sourceValues() ).toContain( 'custom.t_shirt_size' );
    expect( sourceLabels().join( ' ' ) ).toContain( 'T-shirt size' );
} );

it( 'prefers an explicit key over the label, as the server does', () => {
    editorBlocks.list = [
        { clientId: 'a', name: 'fundkit/dropdown', attributes: { field: 'tee', label: 'T-shirt size' } },
        { clientId: 'self', name: 'fundkit/text-input', attributes: {} },
    ];

    mount();

    expect( sourceValues() ).toContain( 'custom.tee' );
    expect( sourceValues() ).not.toContain( 'custom.t_shirt_size' );
} );

/**
 * The hidden field is the one custom field the server does not derive: its
 * runtime key is the raw attribute, so a key derived from its label is one
 * nothing on the live form ever answers to.
 */
it( 'offers the hidden field under the key it actually answers to', () => {
    editorBlocks.list = [
        { clientId: 'a', name: 'fundkit/hidden', attributes: { field: 'utm_source', label: 'Where from' } },
        { clientId: 'self', name: 'fundkit/text-input', attributes: {} },
    ];

    mount();

    expect( sourceValues() ).toContain( 'custom.utm_source' );
    expect( sourceValues() ).not.toContain( 'custom.where_from' );
} );

it( 'leaves out a hidden field with no key, because a label gives it none', () => {
    editorBlocks.list = [
        { clientId: 'a', name: 'fundkit/hidden', attributes: { label: 'Where from' } },
        { clientId: 'self', name: 'fundkit/text-input', attributes: {} },
    ];

    mount();

    expect( sourceValues().filter( ( v ) => v.startsWith( 'custom.' ) ) ).toEqual( [] );
} );

it( 'leaves out a block with neither a key nor a label', () => {
    editorBlocks.list = [
        { clientId: 'a', name: 'fundkit/dropdown', attributes: {} },
        { clientId: 'self', name: 'fundkit/text-input', attributes: {} },
    ];

    mount();

    expect( sourceValues().filter( ( v ) => v.startsWith( 'custom.' ) ) ).toEqual( [] );
} );
