/**
 * A record screen has two failures that look identical once the resolution
 * ends: the record is gone, or the site could not be asked. Telling a reader
 * their form does not exist when the server 500'd sends them to recreate work
 * that is still there.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const mockEntity = { record: null, hasResolved: true, isResolving: false, thrown: null };

jest.mock( '@wordpress/core-data', () => ( {
    store: 'core',
    useEntityRecord: () => ( {
        ...mockEntity,
        editedRecord: {},
        edit: () => {},
        save: async () => {},
        hasEdits: false,
    } ),
} ) );

jest.mock( '@wordpress/data', () => ( {
    useSelect: ( mapper ) => mapper( () => ( {
        isSavingEntityRecord:  () => false,
        getEntityRecordEdits:  () => ( {} ),
        getResolutionError:    () => mockEntity.thrown,
    } ) ),
    useDispatch: () => ( {
        editEntityRecord: () => {},
        saveEntityRecord: () => {},
        invalidateResolution: () => {},
    } ),
} ) );

const { useFundKitRecord } = require( '../../assets/admin/_shared/useFundKitRecord' );

beforeEach( () => {
    mockEntity.record = null;
    mockEntity.hasResolved = true;
    mockEntity.thrown = null;
} );

it( 'reads a 404 as a record that is gone', () => {
    mockEntity.thrown = { code: 'rest_post_invalid_id', message: 'Invalid ID.', data: { status: 404 } };

    const r = useFundKitRecord( 'form', 12 );

    expect( r.notFound ).toBe( true );
    expect( r.loadError ).toBeNull();
} );

it( 'reads a server failure as a failure to ask, and keeps its words', () => {
    mockEntity.thrown = { code: 'internal_server_error', message: 'gateway timeout', data: { status: 500 } };

    const r = useFundKitRecord( 'form', 12 );

    expect( r.notFound ).toBe( false );
    expect( r.loadError ).toEqual( { status: 500, message: 'gateway timeout' } );
} );

it( 'reads a dropped connection, which carries no status, as a failure to ask', () => {
    mockEntity.thrown = { message: 'Failed to fetch' };

    const r = useFundKitRecord( 'form', 12 );

    expect( r.notFound ).toBe( false );
    expect( r.loadError ).toEqual( { status: 0, message: 'Failed to fetch' } );
} );

it( 'says neither when the record is simply there', () => {
    mockEntity.record = { id: 12, title: 'Donate' };

    const r = useFundKitRecord( 'form', 12 );

    expect( r.notFound ).toBe( false );
    expect( r.loadError ).toBeNull();
} );

it( 'still says not found when the resolution ended with no record and no error', () => {
    const r = useFundKitRecord( 'form', 12 );

    expect( r.notFound ).toBe( true );
    expect( r.loadError ).toBeNull();
} );
