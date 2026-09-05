/**
 * Every one of these controls has a route behind it that answers 403 for a
 * reader who does not hold the capability. Offering them anyway means the
 * reader finds out after filling in a dialog, or after a download that turns
 * out to be an error page.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import ActionsCard from '../../assets/admin/donations/detail/rail/ActionsCard';
import ExportTab from '../../assets/admin/tools/tabs/ExportTab';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: jest.fn(), error: jest.fn(), info: jest.fn() },
    default: { success: jest.fn(), error: jest.fn(), info: jest.fn() },
} ) );

const DONATION = {
    id: 1,
    reference: 'DON-1',
    status: 'paid',
    currency: 'USD',
    amount_cents: 5000,
    refundable_cents: 5000,
    donor: { name: 'Nadia' },
};

const settle = () => new Promise( ( resolve ) => setTimeout( resolve, 40 ) );

function mount( node ) {
    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( node, root );
    return root;
}

const labels = () => [ ...document.querySelectorAll( 'button, a' ) ]
    .map( ( b ) => b.textContent.trim() );

beforeEach( () => {
    apiFetch.mockReset();
    document.body.innerHTML = '';
    delete window.fundkit;
} );

describe( 'the donation detail rail', () => {
    const rail = () => (
        <ActionsCard
            donation={ DONATION }
            donor={ { name: 'Nadia' } }
            receipts={ [ { id: 4, receipt_number: 'R-4', voided: false } ] }
            onRefund={ () => {} }
            onResend={ () => {} }
            onAddNote={ () => {} }
            onMarkPaid={ () => {} }
            onMarkFailed={ () => {} }
        />
    );

    it( 'offers nothing a view-only reader cannot do', () => {
        window.fundkit = { can: {} };
        mount( rail() );

        expect( labels() ).toEqual( [] );
    } );

    it( 'offers each control to whoever holds its capability', () => {
        window.fundkit = { can: {
            refund_donations: true,
            resend_receipt:   true,
            edit_donations:   true,
            view_donors:      true,
        } };
        mount( rail() );

        expect( labels() ).toEqual( expect.arrayContaining( [
            'Refund donation',
            'Resend receipt',
            'Download receipt PDF',
            'Add note',
        ] ) );
    } );

    it( 'offers only the note to a reader who may only annotate', () => {
        window.fundkit = { can: { edit_donations: true } };
        mount( rail() );

        expect( labels() ).toEqual( [ 'Add note' ] );
    } );
} );

describe( 'the export tab', () => {
    const options = {
        current_year:  2026,
        current_month: '2026-09',
        first_month:   '2026-01',
        years:         [ 2026 ],
        campaigns:     [],
        donor_columns: [
            { key: 'first_name', label: 'First name' },
            { key: 'email',      label: 'Email' },
        ],
    };

    const serve = () => apiFetch.mockImplementation( () => Promise.resolve( options ) );

    const exportTab = () => (
        <ExportTab setNotice={ () => {} } busy="" setBusy={ () => {} } />
    );

    it( 'shows a donor exporter only the export it holds', async () => {
        window.fundkit = { can: { export_donors: true } };
        serve();
        mount( exportTab() );
        await waitFor( () => document.body.textContent.includes( 'Columns' ) );

        const text = document.body.textContent;
        expect( text ).toContain( 'Columns' );
        expect( text ).not.toContain( 'Revenue report' );
        expect( text ).not.toContain( 'Every donation as a CSV' );
        expect( text ).not.toContain( 'Every Fundraising Toolkit setting' );
    } );

    it( 'will not generate a donor file with no columns picked', async () => {
        window.fundkit = { can: { export_donors: true } };
        serve();
        mount( exportTab() );
        await waitFor( () => document.body.textContent.includes( 'Columns' ) );
        await settle();

        const generate = () => [ ...document.querySelectorAll( 'button' ) ]
            .find( ( b ) => b.textContent.trim() === 'Generate CSV' );
        expect( generate().disabled ).toBe( false );

        [ ...document.querySelectorAll( 'input[type=checkbox]' ) ].forEach( ( box ) => {
            if ( box.checked ) box.click();
        } );
        await settle();

        expect( generate().disabled ).toBe( true );
        expect( document.body.textContent ).toContain( 'Pick at least one column' );
    } );

    it( 'shows an administrator everything', async () => {
        window.fundkit = { can: {
            manage_options: true,
            export_donors:  true,
            view_reports:   true,
            view_donations: true,
        } };
        serve();
        mount( exportTab() );
        await waitFor( () => document.body.textContent.includes( 'Columns' ) );

        const text = document.body.textContent;
        expect( text ).toContain( 'Every donation as a CSV' );
        expect( text ).toContain( 'Revenue report' );
        expect( text ).toContain( 'Every Fundraising Toolkit setting' );
    } );
} );
