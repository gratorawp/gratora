import { __, sprintf } from '@wordpress/i18n';

import { formatAmount } from '../_shared/format';

/**
 * The four steps between a new site and a real donation, read from the facts
 * the server derived. A step offers an action only when taking it would work.
 */
export function firstRunSteps( facts, hrefs ) {
    return [ pageStep( facts, hrefs ), testStep( facts ), paymentsStep( facts, hrefs ), liveStep( facts ) ];
}

/** The step the card puts its one filled button on: the first still to do. */
export function primaryStep( steps ) {
    return steps.find( ( step ) => ! step.done )?.key ?? null;
}

function pageStep( facts, hrefs ) {
    const step = { key: 'page', title: __( 'Create your donation page', 'gratora-donation-platform' ) };

    if ( facts.page === 'live' ) {
        return {
            ...step,
            done:   true,
            text:   facts.page_title,
            action: { kind: 'link', label: __( 'View the page', 'gratora-donation-platform' ), href: facts.page_url, newTab: true },
        };
    }

    if ( facts.page === 'unpublished' ) {
        return {
            ...step,
            done:   false,
            text:   __( 'You have a campaign, but none is published, so no page is taking donations yet.', 'gratora-donation-platform' ),
            action: { kind: 'link', label: __( 'Open campaigns', 'gratora-donation-platform' ), href: hrefs.campaigns },
        };
    }

    return {
        ...step,
        done:   false,
        text:   __( 'One page with a donation form, named after your organization. You can rename it, and delete it if you never use it.', 'gratora-donation-platform' ),
        action: { kind: 'create', label: __( 'Create the page', 'gratora-donation-platform' ) },
    };
}

function testStep( facts ) {
    const step = { key: 'test', title: __( 'Make a test donation', 'gratora-donation-platform' ) };
    const given = facts.test_donation;

    if ( given ) {
        const amount = formatAmount( given.amount_cents, given.currency );

        let text;
        if ( given.donor ) {
            /* translators: 1: an amount, e.g. $25.00. 2: the donor's name. */
            text = sprintf( __( 'A %1$s test donation from %2$s came in.', 'gratora-donation-platform' ), amount, given.donor );
        } else {
            /* translators: %s: an amount, e.g. $25.00. */
            text = sprintf( __( 'A %s test donation came in.', 'gratora-donation-platform' ), amount );
        }

        return {
            ...step,
            done:   true,
            text,
            action: { kind: 'link', label: __( 'View it', 'gratora-donation-platform' ), href: given.url },
        };
    }

    if ( facts.page !== 'live' ) {
        return { ...step, done: false, text: __( 'Needs a donation page first.', 'gratora-donation-platform' ), action: null };
    }

    if ( facts.test_mode ) {
        return {
            ...step,
            done:   false,
            text:   __( 'Give with the Test donation method. No card is charged.', 'gratora-donation-platform' ),
            action: { kind: 'link', label: __( 'Open your donation page', 'gratora-donation-platform' ), href: facts.page_url, newTab: true },
        };
    }

    // Turning test mode on stops a site taking real money, so it is offered
    // only to a site that cannot take any.
    if ( facts.payments ) {
        return { ...step, done: false, text: __( 'This site already takes real donations.', 'gratora-donation-platform' ), action: null };
    }

    return {
        ...step,
        done:   false,
        text:   __( 'Test mode lets you try a donation with no card. This site cannot take a real donation yet, so nothing is lost by turning it on.', 'gratora-donation-platform' ),
        action: { kind: 'test-on', label: __( 'Turn on test mode', 'gratora-donation-platform' ) },
    };
}

function paymentsStep( facts, hrefs ) {
    const step = { key: 'payments', title: __( 'Connect payments', 'gratora-donation-platform' ) };

    if ( facts.payments ) {
        return {
            ...step,
            done:   true,
            /* translators: %s: a list of payment methods, e.g. "Stripe, Offline donations". */
            text:   sprintf( __( 'Ready: %s', 'gratora-donation-platform' ), facts.payment_methods.join( ', ' ) ),
            action: null,
        };
    }

    return {
        ...step,
        done:   false,
        text:   __( 'Add your Stripe or PayPal keys to take cards, or write bank details to take transfers.', 'gratora-donation-platform' ),
        action: { kind: 'link', label: __( 'Connect payments', 'gratora-donation-platform' ), href: hrefs.payments },
    };
}

function liveStep( facts ) {
    const step = { key: 'live', title: __( 'Go live', 'gratora-donation-platform' ) };

    if ( ! facts.test_mode ) {
        return facts.payments
            ? { ...step, done: true, text: __( 'Test mode is off and payments are connected.', 'gratora-donation-platform' ), action: null }
            : { ...step, done: false, text: __( 'Test mode is already off. The site is live as soon as payments are connected.', 'gratora-donation-platform' ), action: null };
    }

    const turnOff = { kind: 'test-off', label: __( 'Turn off test mode', 'gratora-donation-platform' ) };

    return facts.payments
        ? {
            ...step,
            done:   false,
            text:   __( 'Turn off test mode. From then on, every donation is real and counts in your figures.', 'gratora-donation-platform' ),
            action: turnOff,
        }
        : {
            ...step,
            done:   false,
            text:   __( 'Connect payments first. With test mode off and no payment method, the form could take nothing.', 'gratora-donation-platform' ),
            action: { ...turnOff, disabled: true },
        };
}
