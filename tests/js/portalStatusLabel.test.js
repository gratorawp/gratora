/**
 * Recurring plan statuses reach the donor portal exactly as the gateways store
 * them. A donor whose renewal was declined follows the dunning email straight
 * to this list, so the one status that brought them there cannot be the one
 * that renders as a database token.
 *
 * The words come with the page, from the side that writes the statuses. Kept
 * here instead, the list went stale the moment a status was added: a PayPal
 * plan waiting on activation had no word in any language.
 */

import { recurringStatusLabel } from '../../assets/donor-portal/statusLabels';

/** What the shortcode puts on the page. */
const asServed = () => {
    window.gratoraPortal = {
        planStatuses: [
            { value: 'active', label: 'Active', variant: 'green' },
            { value: 'pending', label: 'Pending', variant: 'amber' },
            { value: 'past_due', label: 'Past due', variant: 'amber' },
            { value: 'paused', label: 'Paused', variant: 'gray' },
            { value: 'cancelled', label: 'Cancelled', variant: 'gray' },
            { value: 'expired', label: 'Expired', variant: 'gray' },
        ],
    };
};

test( 'a failed renewal reads as words, not as the stored token', () => {
    asServed();

    expect( recurringStatusLabel( 'past_due' ) ).toBe( 'Past due' );
} );

test( 'every status the page was handed reads as words', () => {
    asServed();

    const tokens = window.gratoraPortal.planStatuses
        .map( ( s ) => s.value )
        .filter( ( value ) => recurringStatusLabel( value ) === value );

    expect( tokens ).toEqual( [] );
} );

test( 'a status the page was handed no word for is still read as words', () => {
    asServed();

    expect( recurringStatusLabel( 'awaiting_mandate' ) ).toBe( 'awaiting mandate' );
    expect( recurringStatusLabel( undefined ) ).toBe( '' );
} );

/**
 * A page rendered without the config is a broken enqueue rather than a
 * supported path, but the donor still must not be shown an underscore.
 */
test( 'and a page served without the list shows no token either', () => {
    window.gratoraPortal = {};

    expect( recurringStatusLabel( 'past_due' ) ).toBe( 'past due' );
} );
