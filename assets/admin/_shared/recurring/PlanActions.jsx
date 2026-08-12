import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Btn from '../components/Btn';
import Dialog from '../components/Dialog';
import { Switch } from '../components/Switch';

/**
 * The five plan actions, in one place.
 *
 * The donor profile's Recurring tab and the Subscriptions list both offer
 * them. Written twice they would drift, which is exactly what happened to the
 * server side of this feature before RecurringPlanActions pulled it together.
 */

/** A cancelled or expired plan takes no further action. */
export const isTerminal = ( status ) => status === 'cancelled' || status === 'expired';

/**
 * Retry is deliberately not in the menu.
 *
 * It is the one thing an admin opens a failing subscription to do, and it is
 * only offered where it can actually work: PayPal owns its own retry schedule
 * and exposes no endpoint to force one, so `can_retry` comes from the server
 * rather than being assumed from the status.
 */
/**
 * How far off the next charge is. The shared timeAgo() clamps its diff at zero
 * because it describes the past, so every future date came out as "just now".
 */
export function dueIn( iso ) {
    if ( ! iso ) return '';
    const then = new Date( String( iso ).replace( ' ', 'T' ) + 'Z' ).getTime();
    if ( Number.isNaN( then ) ) return '';

    const days = Math.round( ( then - Date.now() ) / 86400000 );
    if ( days < 0 ) {
        /* translators: %d: days a renewal is overdue by. */
        return sprintf( _n( '%d day overdue', '%d days overdue', Math.abs( days ), 'dono-fundraising-platform' ), Math.abs( days ) );
    }
    if ( days === 0 ) return __( 'today', 'dono-fundraising-platform' );
    /* translators: %d: days until the next charge. */
    return sprintf( _n( 'in %d day', 'in %d days', days, 'dono-fundraising-platform' ), days );
}

export function retryActionFor( plan ) {
    if ( isTerminal( plan.status ) ) return null;
    if ( ! plan.can_retry ) return null;
    if ( ! ( plan.failed_renewals_count > 0 || plan.status === 'past_due' ) ) return null;

    return { id: 'retry', label: __( 'Retry payment', 'dono-fundraising-platform' ) };
}

export function actionsFor( plan ) {
    if ( isTerminal( plan.status ) ) return [];

    const actions = [];
    if ( plan.status === 'paused' ) {
        actions.push( { id: 'resume', label: __( 'Resume', 'dono-fundraising-platform' ) } );
    } else {
        actions.push( { id: 'pause', label: __( 'Pause', 'dono-fundraising-platform' ) } );
        actions.push( { id: 'skip_next', label: __( 'Skip next', 'dono-fundraising-platform' ) } );
    }
    actions.push( { id: 'change_amount', label: __( 'Change amount', 'dono-fundraising-platform' ) } );
    actions.push( { id: 'cancel', label: __( 'Cancel', 'dono-fundraising-platform' ), destructive: true } );

    return actions;
}

const TITLES = {
    retry:         __( 'Retry the payment', 'dono-fundraising-platform' ),
    pause:         __( 'Pause this donation', 'dono-fundraising-platform' ),
    resume:        __( 'Resume this donation', 'dono-fundraising-platform' ),
    skip_next:     __( 'Skip the next payment', 'dono-fundraising-platform' ),
    change_amount: __( 'Change the amount', 'dono-fundraising-platform' ),
    cancel:        __( 'Cancel this donation', 'dono-fundraising-platform' ),
};

export default function PlanActionDialog( { plan, action, onClose, onDone } ) {
    const [ busy, setBusy ]     = useState( false );
    const [ error, setError ]   = useState( null );
    // Telling the donor is the default: the change was not theirs.
    const [ notify, setNotify ] = useState( true );
    const [ months, setMonths ] = useState( 1 );
    const [ amount, setAmount ] = useState( ( ( plan.amount_cents || 0 ) / 100 ).toFixed( 2 ) );
    const [ reason, setReason ] = useState( '' );

    const submit = () => {
        const body = { action, notify_donor: notify };
        if ( action === 'pause' ) body.months = Number( months ) || 1;
        if ( action === 'cancel' && reason.trim() ) body.reason = reason.trim();
        if ( action === 'change_amount' ) {
            const cents = Math.round( parseFloat( String( amount ).replace( ',', '.' ) ) * 100 );
            if ( ! Number.isFinite( cents ) || cents <= 0 ) {
                setError( __( 'Enter an amount.', 'dono-fundraising-platform' ) );
                return;
            }
            body.amount_cents = cents;
        }

        setBusy( true );
        setError( null );
        apiFetch( { path: `/dono/v1/admin/recurring/${ plan.id }/action`, method: 'POST', data: body } )
            .then( () => { onClose(); if ( onDone ) onDone(); } )
            .catch( ( e ) => setError( e?.message || __( 'That change could not be made.', 'dono-fundraising-platform' ) ) )
            .finally( () => setBusy( false ) );
    };

    return (
        <Dialog
            title={ TITLES[ action ] || __( 'Change this donation', 'dono-fundraising-platform' ) }
            onClose={ () => ( busy ? null : onClose() ) }
            foot={
                <>
                    <Btn variant="secondary" onClick={ onClose } disabled={ busy }>
                        { __( 'Close', 'dono-fundraising-platform' ) }
                    </Btn>
                    <Btn
                        variant="primary"
                        isDestructive={ action === 'cancel' }
                        onClick={ submit }
                        isBusy={ busy }
                        disabled={ busy }
                    >
                        { busy
                            ? __( 'Working…', 'dono-fundraising-platform' )
                            : ( action === 'retry' ? __( 'Retry now', 'dono-fundraising-platform' ) : __( 'Apply change', 'dono-fundraising-platform' ) ) }
                    </Btn>
                </>
            }
        >
            { action === 'change_amount' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>
                            { sprintf(
                                /* translators: %s: currency code, e.g. USD */
                                __( 'New amount (%s)', 'dono-fundraising-platform' ),
                                plan.currency
                            ) }
                        </span>
                        <input
                            type="text"
                            inputMode="decimal"
                            className="dono-input"
                            value={ amount }
                            onChange={ ( e ) => setAmount( e.target.value ) }
                        />
                    </label>
                </p>
            ) }

            { action === 'pause' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Pause for', 'dono-fundraising-platform' ) }</span>
                        <select
                            className="dono-select"
                            value={ String( months ) }
                            onChange={ ( e ) => setMonths( Number( e.target.value ) ) }
                        >
                            { [ 1, 2, 3, 6, 12 ].map( ( m ) => (
                                <option key={ m } value={ m }>
                                    { sprintf(
                                        /* translators: %d: number of months */
                                        _n( '%d month', '%d months', m, 'dono-fundraising-platform' ),
                                        m
                                    ) }
                                </option>
                            ) ) }
                        </select>
                    </label>
                </p>
            ) }

            { action === 'cancel' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Reason (optional)', 'dono-fundraising-platform' ) }</span>
                        <input
                            type="text"
                            className="dono-input"
                            value={ reason }
                            onChange={ ( e ) => setReason( e.target.value ) }
                        />
                    </label>
                </p>
            ) }

            { action === 'retry' && (
                <p>
                    { __( 'The gateway will try to collect the outstanding renewal again now. If it succeeds the donation appears within a few moments, once the gateway confirms it.', 'dono-fundraising-platform' ) }
                </p>
            ) }

            { action === 'skip_next' && (
                <p>{ __( 'The next payment is skipped and the donation carries on one cycle later. Nothing is charged in between.', 'dono-fundraising-platform' ) }</p>
            ) }

            { action === 'resume' && (
                <p>{ __( 'Charging restarts on the plan’s normal schedule.', 'dono-fundraising-platform' ) }</p>
            ) }

            { /* Cancellation always emails through the canceller, so offering
                 the choice on that one action would be a lie. */ }
            { action !== 'cancel' && action !== 'retry' && (
                <div style={ { marginTop: 12, display: 'flex', alignItems: 'center', gap: 8 } }>
                    <Switch
                        checked={ notify }
                        onChange={ setNotify }
                        label={ __( 'Notify donor', 'dono-fundraising-platform' ) }
                    />
                    <span>{ __( 'Email the donor about this change', 'dono-fundraising-platform' ) }</span>
                </div>
            ) }

            { error && <p className="dp-error" style={ { marginTop: 12 } }>{ error }</p> }
        </Dialog>
    );
}
