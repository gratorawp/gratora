/**
 * Every campaign block is one editor component. A name it reads but never
 * imported is not a missing feature, it is a ReferenceError the moment the
 * block is placed, and it takes the whole editor canvas down with it.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const registered = [];

jest.mock( '@wordpress/blocks', () => ( {
    registerBlockType: ( name, settings ) => registered.push( { name, settings } ),
} ) );

jest.mock( '@wordpress/data', () => ( {
    useSelect: ( mapper ) => mapper( () => ( {
        getEditedPostAttribute: () => ( {} ),
    } ) ),
    useDispatch: () => ( {} ),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
    store: 'core',
    useEntityRecord: () => ( { record: null, hasResolved: true } ),
    useEntityRecords: () => ( { records: [] } ),
} ) );

jest.mock( '@wordpress/block-editor', () => ( {
    InspectorControls: ( { children } ) => <div>{ children }</div>,
    MediaUpload: ( { render: r } ) => <div>{ r ? r( { open: () => {} } ) : null }</div>,
    MediaUploadCheck: ( { children } ) => <div>{ children }</div>,
    RichText: ( { value } ) => <span>{ value }</span>,
    useBlockProps: ( props = {} ) => props,
} ) );

jest.mock(
    '@wordpress/server-side-render',
    () => ( { __esModule: true, default: () => <div data-ssr="1" /> } ),
    { virtual: true }
);

jest.mock( '@wordpress/components', () => {
    const passthrough = ( { children } ) => <div>{ children }</div>;
    return {
        Button:         ( { children, ...rest } ) => <button { ...rest }>{ children }</button>,
        ComboboxControl: ( { label } ) => <input aria-label={ label } data-picker="campaign" />,
        Disabled:       passthrough,
        PanelBody:      passthrough,
        Placeholder:    passthrough,
        RangeControl:   () => <input type="range" />,
        SelectControl:  () => <select />,
        TextControl:    () => <input />,
        ToggleControl:  () => <input type="checkbox" />,
    };
} );

jest.mock( '../../assets/admin/campaign-blocks/LayoutSwitcher', () => ( {} ) );
jest.mock( '../../assets/admin/campaign-blocks/bindings.js', () => ( {
    registerCampaignBindingSource: () => {},
} ) );
jest.mock( '../../assets/admin/_shared/entities', () => ( {
    registerGratoraEntities: () => {},
} ) );

window.gratoraCampaignBlocks = { canManageCampaigns: true, bindingFields: {} };

require( '../../assets/admin/campaign-blocks/index.jsx' );

let root = null;

function renderEdit( settings ) {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );

    const attributes = {};
    for ( const [ key, spec ] of Object.entries( settings.attributes || {} ) ) {
        attributes[ key ] = spec.default;
    }
    attributes.campaignId = 0;

    const Edit = settings.edit;
    render(
        <Edit
            attributes={ attributes }
            setAttributes={ () => {} }
            isSelected={ false }
            clientId="test"
            context={ {} }
        />,
        root
    );
}

test( 'the blocks were captured', () => {
    expect( registered.length ).toBeGreaterThan( 5 );
} );

test( 'every campaign block renders its editor without throwing', () => {
    const broken = [];
    for ( const { name, settings } of registered ) {
        if ( typeof settings.edit !== 'function' ) continue;
        try {
            renderEdit( settings );
        } catch ( e ) {
            broken.push( `${ name }: ${ e.message }` );
        }
    }

    expect( broken ).toEqual( [] );
} );

test( 'an unbound block offers the campaign picker', () => {
    const block = registered.find( ( b ) => b.name === 'gratora/campaign-progress' );
    renderEdit( block.settings );

    expect( root.querySelector( '[data-picker="campaign"]' ) ).not.toBeNull();
} );
