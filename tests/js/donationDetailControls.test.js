/**
 * A donation stops being 'paid' the moment part of it goes back, and three
 * controls on the detail screen tested for 'paid' alone. The server does not:
 * DonationService::refund accepts partial_refund, and the receipt for a partly
 * refunded donation is still the donor's.
 *
 * Also here: the two places an operator types money, which must not offer a
 * precision the storage cannot hold.
 */

import { render } from 'preact';

import {
    isSettled,
    canRefundDonation,
    canResendReceipt,
    isDonorRedacted,
} from '../../assets/admin/donations/detail/helpers';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const paid = { status: 'paid', refundable_cents: 5000, currency: 'USD' };
const partly = { status: 'partial_refund', refundable_cents: 2500, currency: 'USD' };
const spent = { status: 'partial_refund', refundable_cents: 0, currency: 'USD' };

describe( 'a partly refunded donation is still a settled one', () => {
    test( 'money still on it can still be refunded', () => {
        expect( canRefundDonation( paid ) ).toBe( true );
        expect( canRefundDonation( partly ) ).toBe( true );
        expect( canRefundDonation( spent ) ).toBe( false );
        expect( canRefundDonation( { status: 'pending', refundable_cents: 5000 } ) ).toBe( false );
    } );

    test( 'the receipt is still the donor\'s', () => {
        expect( canResendReceipt( paid, null ) ).toBe( true );
        expect( canResendReceipt( partly, null ) ).toBe( true );
        // Nothing was captured, so there is no receipt to send.
        expect( canResendReceipt( { status: 'pending' }, null ) ).toBe( false );
    } );

    test( 'an erased donor has nowhere to send it', () => {
        expect( canResendReceipt( partly, { redacted: true } ) ).toBe( false );
        expect( isDonorRedacted( partly, { redacted: true } ) ).toBe( true );
        expect( isDonorRedacted( { donor: { redacted: true } }, null ) ).toBe( true );
        expect( isDonorRedacted( partly, null ) ).toBe( false );
    } );

    test( 'a fully refunded donation is not settled', () => {
        expect( isSettled( { status: 'refunded' } ) ).toBe( false );
    } );
} );

describe( 'money entry never offers more precision than storage holds', () => {
    // Amounts are stored as major x 100 for every currency, so a third decimal
    // is rounded away between the box and the gateway.
    const { amountEntry } = require( '../../assets/admin/_shared/format' );

    test( 'a three-decimal currency is entered in hundredths', () => {
        expect( amountEntry( 'BHD' ) ).toEqual( { dp: 2, step: '0.01' } );
        expect( amountEntry( 'KWD' ).step ).toBe( '0.01' );
    } );

    test( 'a two-decimal currency is unchanged', () => {
        expect( amountEntry( 'USD' ) ).toEqual( { dp: 2, step: '0.01' } );
    } );

    test( 'a currency with no minor unit still steps whole', () => {
        expect( amountEntry( 'JPY' ) ).toEqual( { dp: 0, step: '1' } );
    } );
} );

/**
 * The "Mark donation as failed" dialog promises the reason will be shown in the
 * donation timeline, and the timeline had no failed branch at all: the text
 * only ever reached the red banner at the top of the page.
 */
describe( 'a failed donation says so on its own timeline', () => {
    const TimelineCard = require( '../../assets/admin/donations/detail/cards/TimelineCard' ).default;

    const mount = ( donation ) => {
        document.body.innerHTML = '<div id="root"></div>';
        render(
            <TimelineCard
                donation={ donation }
                receipts={ [] }
                refunds={ [] }
                notes={ [] }
            />,
            document.getElementById( 'root' )
        );
        return document.getElementById( 'root' ).textContent;
    };

    const failed = {
        status:         'failed',
        created_at:     '2026-04-06 09:00:00',
        updated_at:     '2026-04-06 09:30:00',
        failure_reason: 'Card issuer declined',
    };

    test( 'the reason the operator typed is on it', () => {
        const text = mount( failed );
        expect( text ).toContain( 'Marked as failed' );
        expect( text ).toContain( 'Card issuer declined' );
    } );

    test( 'a failure with no reason given still appears', () => {
        expect( mount( { ...failed, failure_reason: null } ) ).toContain( 'Marked as failed' );
    } );

    test( 'a donation that did not fail carries no failed event', () => {
        const text = mount( {
            status:     'paid',
            created_at: '2026-04-06 09:00:00',
            updated_at: '2026-04-06 09:30:00',
            paid_at:    '2026-04-06 09:05:00',
            gateway:    'stripe',
        } );
        expect( text ).not.toContain( 'Marked as failed' );
    } );
} );
