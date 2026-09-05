/**
 * The form editor removes the admin bar and the menu, so what it renders is the
 * whole screen. A failed load resolved the same way a deleted form does, and
 * the reader was told "Form not found." with nothing to click.
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

jest.mock( '../../assets/admin/_shared/useFundKitRecord', () => ( {
    __esModule: true,
    useFundKitRecord: () => global.__record,
} ) );

const { render } = require( 'preact' );
const Editor = require( '../../assets/admin/forms/Editor' ).default;

const base = {
    record: {}, savedRecord: null, edits: {}, isEdited: () => false, edit: () => {},
    value: ( k, f = '' ) => f, bind: () => ( {} ), bindNumber: () => ( {} ), setValue: () => {},
    save: async () => {}, saveEntity: async () => {}, discard: () => {},
    isDirty: false, isSaving: false, isLoading: false,
    notFound: false, loadError: null, reload: jest.fn(),
};

let root = null;

function mount() {
    if ( root ) render( null, root );
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <Editor id={ 12 } />, root );
}

const labels = () => [ ...document.querySelectorAll( 'button, a' ) ]
    .map( ( b ) => b.textContent.trim() );

beforeEach( () => {
    document.body.innerHTML = '';
} );

it( 'says the load failed, and offers a retry and a way back', () => {
    global.__record = { ...base, loadError: { message: 'gateway timeout' } };
    mount();

    expect( document.body.textContent ).toContain( 'gateway timeout' );
    expect( document.body.textContent ).not.toContain( 'Form not found' );
    expect( labels() ).toContain( 'Try again' );
    expect( labels() ).toContain( 'Back to forms' );
} );

it( 'asks again when told to', () => {
    const reload = jest.fn();
    global.__record = { ...base, loadError: { message: 'gateway timeout' }, reload };
    mount();

    [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === 'Try again' )
        .click();

    expect( reload ).toHaveBeenCalled();
} );

it( 'still says not found when the form is gone, with a way back', () => {
    global.__record = { ...base, notFound: true };
    mount();

    expect( document.body.textContent ).toContain( 'Form not found' );
    expect( labels() ).toContain( 'Back to forms' );
    expect( labels() ).not.toContain( 'Try again' );
} );
