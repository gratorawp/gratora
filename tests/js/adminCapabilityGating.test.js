/**
 * Every one of these controls has a route behind it that answers 403 for a
 * reader who does not hold the capability. Offering them anyway means the
 * reader finds out after filling in a dialog, or after a download that turns
 * out to be an error page.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

import ActionsCard from '../../assets/admin/donations/detail/rail/ActionsCard';
import Header from '../../assets/admin/donations/detail/Header';
import NotesCard from '../../assets/admin/donations/detail/cards/NotesCard';
import ReceiptCard from '../../assets/admin/donations/detail/cards/ReceiptCard';
import ExportTab from '../../assets/admin/tools/tabs/ExportTab';
import MaintenanceTab from '../../assets/admin/tools/tabs/MaintenanceTab';
import LogsTab from '../../assets/admin/tools/tabs/LogsTab';
import DonorHeader from '../../assets/admin/donors/profile/Header';
import IdentityCard from '../../assets/admin/donors/profile/IdentityCard';
import ConsentTab from '../../assets/admin/donors/profile/tabs/ConsentTab';
import NotesTab from '../../assets/admin/donors/profile/tabs/NotesTab';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// The log tab renders a DataViews table, which subscribes to breakpoints the
// moment it mounts. None of these tests are about the table.
window.matchMedia = () => ( {
    matches: false, addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
} );

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: () => <div data-dataviews="1" />,
} ) );

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

describe( 'the donor profile', () => {
    const DONOR = {
        id: 9,
        name: 'Nadia Rahman',
        email: 'nadia@example.test',
        is_anonymous: false,
        redacted_at: null,
        first_donation_at: null,
        country: '',
        public_hidden_at: null,
    };

    const screens = () => (
        <>
            <DonorHeader
                donor={ DONOR }
                recurring={ { plans: [] } }
                banners={ [] }
                onBack={ () => {} }
                onEdit={ () => {} }
                onTabSwitch={ () => {} }
            />
            <IdentityCard donor={ DONOR } />
            <ConsentTab donor={ DONOR } consents={ [] } onChanged={ () => {} } />
            <NotesTab donor={ DONOR } notes={ [] } onChanged={ () => {} } />
        </>
    );

    const CONTROLS = [ 'Edit details', 'Create a sign-in link', 'Add note', 'Export personal data', 'Redact donor' ];

    it( 'offers nothing a view-only reader cannot do', () => {
        window.fundkit = { can: { view_donors: true } };
        mount( screens() );

        for ( const control of CONTROLS ) {
            expect( labels() ).not.toContain( control );
        }
    } );

    it( 'offers each control to whoever holds its capability', () => {
        window.fundkit = { can: {
            view_donors: true, edit_donors: true, export_donors: true, redact_donors: true,
        } };
        mount( screens() );

        for ( const control of CONTROLS ) {
            expect( labels() ).toContain( control );
        }
    } );
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

/**
 * The page head offers the same two actions with no gate of its own: it asks
 * the shared predicate, so the capability has to live there.
 */
describe( 'the donation detail head', () => {
    const head = () => (
        <Header
            donation={ DONATION }
            donor={ { name: 'Nadia' } }
            onResendReceipt={ () => {} }
            onRefund={ () => {} }
            onBack={ () => {} }
        />
    );

    it( 'offers a view-only reader neither action', () => {
        window.fundkit = { can: {} };
        mount( head() );

        expect( labels() ).not.toContain( 'Refund' );
        expect( labels() ).not.toContain( 'Resend receipt' );
    } );

    it( 'offers each to whoever holds its capability', () => {
        window.fundkit = { can: { refund_donations: true, resend_receipt: true } };
        mount( head() );

        expect( labels() ).toContain( 'Refund' );
        expect( labels() ).toContain( 'Resend receipt' );
    } );
} );

/**
 * The rail is not the only way to reach these. The cards under it offer the
 * same actions, so gating the rail alone leaves each one still on the page.
 */
describe( 'the cards beside the rail', () => {
    const notes = () => (
        <NotesCard
            donationRef="DON-1"
            notes={ [ { id: 1, body: 'Called to thank them', author: 'Sam', created_at: '2026-09-01 10:00:00' } ] }
            onChanged={ () => {} }
        />
    );

    const receipt = () => (
        <ReceiptCard
            donation={ DONATION }
            receipts={ [ { id: 4, receipt_number: 'R-4', voided: false, created_at: '2026-09-01 10:00:00' } ] }
            onResend={ () => {} }
        />
    );

    it( 'offers a view-only reader no way to write a note', () => {
        window.fundkit = { can: {} };
        mount( notes() );

        expect( document.querySelector( 'textarea' ) ).toBeNull();
        expect( document.querySelector( '.dd-note__delete' ) ).toBeNull();
        expect( document.body.textContent ).toContain( 'Called to thank them' );
    } );

    it( 'offers the note form to a reader who may annotate', () => {
        window.fundkit = { can: { edit_donations: true } };
        mount( notes() );

        expect( document.querySelector( 'textarea' ) ).not.toBeNull();
        expect( document.querySelector( '.dd-note__delete' ) ).not.toBeNull();
    } );

    it( 'offers a view-only reader neither the resend nor the PDF', () => {
        window.fundkit = { can: {} };
        mount( receipt() );

        expect( labels() ).toEqual( [] );
        expect( document.body.textContent ).toContain( 'R-4' );
    } );

    it( 'offers each receipt control to whoever holds its capability', () => {
        window.fundkit = { can: { resend_receipt: true, view_donors: true } };
        mount( receipt() );

        expect( labels() ).toEqual( expect.arrayContaining( [ 'Resend', 'PDF' ] ) );
    } );
} );

describe( 'the maintenance tab', () => {
    const INFO = {
        pending_upgrades: [ { id: 'x', description: 'Recalculating totals.' } ],
        test_data:        { donations: 3, recurring_plans: 1, donors: 2 },
    };

    const tab = () => (
        <MaintenanceTab
            info={ INFO }
            infoError={ null }
            active
            loadInfo={ () => {} }
            setNotice={ () => {} }
        />
    );

    it( 'keeps the site-wide cards away from a reader who cannot run them', () => {
        window.fundkit = { can: { view_reports: true } };
        mount( tab() );

        expect( document.body.textContent ).not.toContain( 'Data updates are outstanding' );
        expect( document.body.textContent ).not.toContain( 'Test data' );
    } );

    it( 'shows them to an administrator', () => {
        window.fundkit = { can: { manage_options: true } };
        mount( tab() );

        expect( document.body.textContent ).toContain( 'Data updates are outstanding' );
        expect( document.body.textContent ).toContain( 'Test data' );
    } );
} );

describe( 'the log tab', () => {
    const serveLogs = () => apiFetch.mockImplementation( () => Promise.resolve( {
        items: [], total: 4, types: [],
    } ) );

    it( 'does not offer the clear to a reader who cannot run it', async () => {
        window.fundkit = { can: { view_reports: true } };
        serveLogs();
        mount( <LogsTab active setNotice={ () => {} } /> );
        await settle();

        expect( labels() ).not.toContain( 'Clear log' );
        expect( labels() ).toContain( 'Refresh' );
    } );

    it( 'offers it to an administrator', async () => {
        window.fundkit = { can: { manage_options: true } };
        serveLogs();
        mount( <LogsTab active setNotice={ () => {} } /> );
        await settle();

        expect( labels() ).toContain( 'Clear log' );
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
