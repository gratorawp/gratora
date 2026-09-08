/**
 * The campaign blocks register for every block-editor user, but the campaign
 * list is gated on a FundKit capability. A refused fetch was read as a campaign
 * that no longer exists, so every block on a campaign's own page collapsed to
 * "choose a campaign" for an Editor.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const store = { postMetaId: 0, record: null, hasResolved: true, records: [], queries: [] };

jest.mock( '@wordpress/data', () => ( {
    useSelect: ( mapper ) => mapper( () => ( {
        getEditedPostAttribute: () => ( { _fundkit_campaign_id: store.postMetaId } ),
    } ) ),
    useDispatch: () => ( {} ),
} ) );

jest.mock( '@wordpress/core-data', () => ( {
    store: 'core',
    useEntityRecord: ( kind, name, id ) => ( {
        record: id > 0 ? store.record : null,
        hasResolved: store.hasResolved,
    } ),
    useEntityRecords: ( kind, name, query ) => {
        store.queries.push( query );
        return { records: store.records };
    },
} ) );

jest.mock( '@wordpress/components', () => ( {
    ComboboxControl: ( { label, value, options = [], onFilterValueChange } ) => (
        <div data-combobox={ label } data-value={ String( value ) }>
            <input aria-label={ label } onInput={ ( e ) => onFilterValueChange( e.target.value ) } />
            { options.map( ( o ) => <span key={ o.value } data-option={ String( o.value ) }>{ o.label }</span> ) }
        </div>
    ),
} ) );

const mod = require( '../../assets/admin/campaign-blocks/campaign-field' );

async function settle() {
    for ( let i = 0; i < 5; i++ ) {
        await new Promise( ( r ) => requestAnimationFrame( () => r() ) );
        await new Promise( ( r ) => setTimeout( r, 0 ) );
    }
}

let root = null;

function renderPicker( props = {} ) {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <mod.CampaignPicker value={ 0 } onChange={ () => {} } { ...props } />, root );
}

beforeEach( () => {
    document.body.innerHTML = '';
    store.postMetaId = 0;
    store.record = null;
    store.hasResolved = true;
    store.records = [];
    store.queries = [];
    window.fundkitCampaignBlocks = { canManageCampaigns: true };
} );

describe( 'the campaign picker', () => {
    it( 'asks the server to narrow the list rather than paging in the browser', async () => {
        renderPicker();

        const box = document.querySelector( '[aria-label="Campaign"]' );
        box.value = 'water';
        box.dispatchEvent( new window.Event( 'input', { bubbles: true } ) );
        await settle();

        expect( store.queries.some( ( q ) => q.search === 'water' ) ).toBe( true );
    } );

    it( 'offers the bound campaign even when it is outside the current page', () => {
        store.records = [ { id: 2, title: 'Something else' } ];
        store.record  = { id: 991, title: 'Bound but far away' };

        renderPicker( { value: 991 } );

        const offered = [ ...document.querySelectorAll( '[data-option]' ) ]
            .map( ( n ) => n.getAttribute( 'data-option' ) );
        expect( offered ).toContain( '991' );
        expect( document.querySelector( '[data-combobox]' ).getAttribute( 'data-value' ) ).toBe( '991' );
    } );

    it( 'tells a reader who cannot list campaigns why the list is empty', () => {
        window.fundkitCampaignBlocks = { canManageCampaigns: false };
        // What a 403 actually leaves: the resolver fails and the store holds
        // null, never an empty array, which is why this sentence was
        // unreachable for the one reader it was written for.
        store.records = null;

        renderPicker();

        expect( document.body.textContent ).toContain( 'do not have permission' );
    } );

    /** And a reader who can list them, with none to show, still gets the other branch. */
    it( 'tells a reader who can list them that there are none yet', () => {
        window.fundkitCampaignBlocks = { canManageCampaigns: true };
        store.records = [];

        renderPicker();

        expect( document.body.textContent ).not.toContain( 'do not have permission' );
    } );
} );

describe( 'a page that names its own campaign', () => {
    it( 'is not treated as orphaned when the reader simply cannot fetch it', () => {
        window.fundkitCampaignBlocks = { canManageCampaigns: false };
        store.postMetaId = 7;
        store.record = null;
        store.hasResolved = true;

        const bound = mod.useBoundCampaign( 0 );

        expect( bound.onCampaignPage ).toBe( true );
        expect( bound.resolvedId ).toBe( 7 );
    } );

    it( 'is still orphaned when a reader who can fetch it finds nothing', () => {
        window.fundkitCampaignBlocks = { canManageCampaigns: true };
        store.postMetaId = 7;
        store.record = null;
        store.hasResolved = true;

        expect( mod.useBoundCampaign( 0 ).onCampaignPage ).toBe( false );
    } );
} );
