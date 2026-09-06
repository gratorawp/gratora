/**
 * Two admin screens whose only account of a failed refetch was a red sentence
 * with nothing to press: the dashboard, whose figures then belong to a range
 * nobody picked, and the donation detail, which replaced a screen the operator
 * had just refunded on with one line.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

jest.mock( '../../assets/admin/_shared/extensionTabs', () => ( {
    __esModule: true,
    useExtensionPanels: () => [],
    useExtensionTabs:   () => [],
} ) );

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import Detail from '../../assets/admin/donations/Detail';

const settle = () => new Promise( ( r ) => setTimeout( r, 40 ) );

const DONATION = {
    donation: { id: 1, reference: 'DON-1', status: 'paid', currency: 'USD', amount_cents: 5000, refundable_cents: 5000 },
    donor:    { id: 2, name: 'Nadia' },
    receipts: [],
    refunds:  [],
    related:  [],
    notes:    [],
};

const buttons = () => [ ...document.querySelectorAll( 'button' ) ].map( ( b ) => b.textContent.trim() );

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '<div id="root"></div>';
    window.fundkit = { can: { view_donations: true, refund_donations: true, edit_donations: true, resend_receipt: true } };
} );

afterEach( () => { delete window.fundkit; } );

it( 'offers a retry when the donation cannot be loaded at all', async () => {
    apiFetch.mockRejectedValue( new Error( 'Request failed' ) );

    render( <Detail reference="DON-1" />, document.getElementById( 'root' ) );
    await settle();

    expect( document.body.textContent ).toContain( 'Request failed' );
    expect( buttons() ).toContain( 'Try again' );
    expect( buttons() ).toContain( 'Back to donations' );
} );

it( 'retries into the loaded screen', async () => {
    apiFetch
        .mockRejectedValueOnce( new Error( 'Request failed' ) )
        .mockResolvedValue( DONATION );

    render( <Detail reference="DON-1" />, document.getElementById( 'root' ) );
    await settle();

    [ ...document.querySelectorAll( 'button' ) ]
        .find( ( b ) => b.textContent.trim() === 'Try again' )
        .click();
    await settle();

    expect( document.body.textContent ).toContain( 'DON-1' );
    expect( document.body.textContent ).not.toContain( 'Request failed' );
} );

/** A failure after the screen has data says so above the cards, not instead. */
it( 'keeps the screen when a reload fails on top of data', async () => {
    apiFetch
        .mockResolvedValueOnce( DONATION )
        .mockRejectedValue( new Error( 'Reload failed' ) );

    render( <Detail reference="DON-1" />, document.getElementById( 'root' ) );
    await settle();
    expect( document.body.textContent ).toContain( 'DON-1' );

    // A second load that fails while the first one's data is still in state.
    render( <Detail reference="DON-2" />, document.getElementById( 'root' ) );
    await settle();

    expect( document.body.textContent ).toContain( 'Reload failed' );
    expect( document.body.textContent ).toContain( 'DON-1' );
    expect( buttons() ).toContain( 'Refund' );
} );
