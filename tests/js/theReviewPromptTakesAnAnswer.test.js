/**
 * The dashboard asks for a review once. Whatever the answer, the question
 * leaves the screen at once and the server is told, so it does not come back
 * on the next load.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
// The real Button renders a link when it is handed an address, and a button otherwise.
jest.mock( '@wordpress/components', () => ( {
    __esModule: true,
    Button: ( { children, variant, href, ...rest } ) => ( href
        ? <a href={ href } { ...rest }>{ children }</a>
        : <button type="button" { ...rest }>{ children }</button> ),
} ) );

import ReviewPrompt from '../../assets/admin/dashboard/ReviewPrompt';

let root;
let answered;

beforeEach( () => {
    apiFetch.mockReset();
    apiFetch.mockResolvedValue( { review_prompt: false } );
    answered = 0;
    document.body.innerHTML = '<div id="root"></div>';
    root = document.getElementById( 'root' );
    render( <ReviewPrompt onAnswered={ () => answered++ } />, root );
} );

const control = ( text ) => [ ...root.querySelectorAll( 'a, button' ) ].find( ( el ) => el.textContent === text );
const sent = () => apiFetch.mock.calls.map( ( [ request ] ) => [ request.path, request.method, request.data.answer ] );

it( 'links the review to the plugin\'s review form on WordPress.org, in a new tab', () => {
    const link = control( 'Leave a review' );

    expect( link.getAttribute( 'href' ) ).toBe( 'https://wordpress.org/support/plugin/gratora-donation-platform/reviews/#new-post' );
    expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
} );

it.each( [
    [ 'Leave a review', 'reviewed' ],
    [ 'Maybe later', 'later' ],
    [ 'Do not ask again', 'never' ],
    [ '×', 'later' ],
] )( '"%s" is recorded as %s and ends the question', ( text, answer ) => {
    control( text ).click();

    expect( sent() ).toEqual( [ [ '/gratora/v1/admin/me/review-prompt', 'POST', answer ] ] );
    expect( answered ).toBe( 1 );
} );

it( 'still leaves the screen when the answer cannot be saved', async () => {
    apiFetch.mockRejectedValue( new Error( 'offline' ) );

    control( 'Maybe later' ).click();
    await Promise.resolve();

    expect( answered ).toBe( 1 );
} );
