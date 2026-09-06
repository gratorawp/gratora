/**
 * A status slug is a database word. Printed straight onto a screen it is
 * untranslatable and, for a refund, wrong twice over: the colour said "fine" or
 * "in progress" and nothing else, so a failed refund and a settled one were
 * told apart only by an English word nobody localised.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';

import RefundsCard from '../../assets/admin/donations/detail/cards/RefundsCard';
import { planStatusPill } from '../../assets/admin/donors/profile/helpers';
import { STATUS_OPTIONS } from '../../assets/admin/donors/profile/tabs/RecurringTab';

const DONATION = { refunded_cents: 1000, refund_pending_cents: 500, refundable_cents: 0, currency: 'USD' };

const refund = ( id, status ) => ( {
    id,
    status,
    amount_cents: 500,
    currency: 'USD',
    occurred_at: '2026-09-01 10:00:00',
    reason: '',
    gateway_refund_id: `re_${ id }`,
} );

function mountRefunds() {
    document.body.innerHTML = '<div id="root"></div>';
    render(
        <RefundsCard
            donation={ DONATION }
            refunds={ [
                refund( 1, 'succeeded' ),
                refund( 2, 'pending' ),
                refund( 3, 'failed' ),
                refund( 4, 'reversed' ),
            ] }
            onIssue={ null }
            onRelease={ null }
        />,
        document.getElementById( 'root' )
    );
}

const rowPill = ( index ) => document.querySelectorAll( 'tbody tr' )[ index ].querySelector( '.dd-pill' );

describe( 'the refunds table', () => {
    beforeEach( mountRefunds );

    it( 'names every refund state in words', () => {
        const text = document.body.textContent;

        expect( text ).toContain( 'Issued' );
        expect( text ).toContain( 'Not settled yet' );
        expect( text ).toContain( 'Failed' );
        expect( text ).toContain( 'Reversed' );
    } );

    it( 'prints no raw database word', () => {
        const text = document.body.textContent;

        expect( text ).not.toContain( 'succeeded' );
        expect( text ).not.toContain( 'reversed' );
    } );

    it( 'tells a failed refund apart from one still settling', () => {
        expect( rowPill( 2 ).className ).toContain( 'is-error' );
        expect( rowPill( 1 ).className ).toContain( 'is-warn' );
    } );
} );

describe( 'the recurring status vocabulary', () => {
    it( 'says back every status the tab lets an operator filter by', () => {
        for ( const option of STATUS_OPTIONS.filter( ( o ) => o.value !== '' ) ) {
            expect( planStatusPill( option.value ).label ).toBe( option.label );
        }
    } );

    it( 'does not print the database word for an ended plan', () => {
        expect( planStatusPill( 'expired' ).label ).not.toBe( 'expired' );
        expect( planStatusPill( 'expired' ).cls ).toBe( 'is-muted' );
    } );
} );
