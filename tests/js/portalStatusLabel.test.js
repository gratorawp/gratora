/**
 * Recurring plan statuses reach the donor portal exactly as the gateways store
 * them. A donor whose renewal was declined follows the dunning email straight
 * to this list, so the one status that brought them there cannot be the one
 * that renders as a database token.
 */

import { recurringStatusLabel } from '../../assets/donor-portal/statusLabels';

test( 'a failed renewal reads as words, not as the stored token', () => {
    expect( recurringStatusLabel( 'past_due' ) ).toBe( 'Past due' );
} );

test( 'the statuses the portal already knew are unchanged', () => {
    expect( recurringStatusLabel( 'active' ) ).toBe( 'Active' );
    expect( recurringStatusLabel( 'paused' ) ).toBe( 'Paused' );
    expect( recurringStatusLabel( 'cancelled' ) ).toBe( 'Cancelled' );
    expect( recurringStatusLabel( 'expired' ) ).toBe( 'Expired' );
} );

test( 'a status this list has not learned yet is still read as words', () => {
    expect( recurringStatusLabel( 'awaiting_mandate' ) ).toBe( 'awaiting mandate' );
    expect( recurringStatusLabel( undefined ) ).toBe( '' );
} );
