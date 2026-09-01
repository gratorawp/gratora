import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Btn from '../components/Btn';
import Dialog from '../components/Dialog';
import { Switch } from '../components/Switch';
import AmountInput from '../components/AmountInput';

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
        return sprintf( _n( '%d day overdue', '%d days overdue', Math.abs( days ), 'fundkit-fundraising-campaigns' ), Math.abs( days ) );
    }
    if ( days === 0 ) return __( 'today', 'fundkit-fundraising-campaigns' );
    /* translators: %d: days until the next charge. */
    return sprintf( _n( 'in %d day', 'in %d days', days, 'fundkit-fundraising-campaigns' ), days );
}

export function retryActionFor( plan ) {
    if ( isTerminal( plan.status ) ) return null;
    if ( ! plan.can_retry ) return null;
    if ( ! ( plan.failed_renewals_count > 0 || plan.status === 'past_due' ) ) return null;

    return { id: 'retry', label: __( 'Retry payment', 'fundkit-fundraising-campaigns' ) };
}

export function actionsFor( plan ) {
    if ( isTerminal( plan.status ) ) return [];

    const actions = [];
    if ( plan.status === 'paused' ) {
        actions.push( { id: 'resume', label: __( 'Resume', 'fundkit-fundraising-campaigns' ) } );
    } else {
        actions.push( { id: 'pause', label: __( 'Pause', 'fundkit-fundraising-campaigns' ) } );
        actions.push( { id: 'skip_next', label: __( 'Skip next', 'fundkit-fundraising-campaigns' ) } );
    }
    actions.push( { id: 'change_amount', label: __( 'Change amount', 'fundkit-fundraising-campaigns' ) } );
    actions.push( { id: 'cancel', label: __( 'Cancel', 'fundkit-fundraising-campaigns' ), destructive: true } );

    return actions;
}

const TITLES = {
    retry:         __( 'Retry the payment', 'fundkit-fundraising-campaigns' ),
    pause:         __( 'Pause this donation', 'fundkit-fundraising-campaigns' ),
    resume:        __( 'Resume this donation', 'fundkit-fundraising-campaigns' ),
    skip_next:     __( 'Skip the next payment', 'fundkit-fundraising-campaigns' ),
    change_amount: __( 'Change the amount', 'fundkit-fundraising-campaigns' ),
    cancel:        __( 'Cancel this donation', 'fundkit-fundraising-campaigns' ),
};

export default function PlanActionDialog( { plan, action, onClose, onDone } ) {
    const [ busy, setBusy ]     = useState( false );
    const [ error, setError ]   = useState( null );
    const [ approveUrl, setApproveUrl ] = useState( null );
    // Telling the donor is the default: the change was not theirs.
    const [ notify, setNotify ] = useState( true );
    const [ months, setMonths ] = useState( 1 );
    const [ amount, setAmount ] = useState( ( plan.amount_cents || 0 ) / 100 );
    const [ reason, setReason ] = useState( '' );

    const submit = () => {
        const body = { action, notify_donor: notify };
        if ( action === 'pause' ) body.months = Number( months ) || 1;
        if ( action === 'cancel' && reason.trim() ) body.reason = reason.trim();
        if ( action === 'change_amount' ) {
            // AmountInput reports a number, so the separator handling that was
            // here belongs to it now.
            const cents = Math.round( Number( amount ) * 100 );
            if ( ! Number.isFinite( cents ) || cents <= 0 ) {
                setError( __( 'Enter an amount.', 'fundkit-fundraising-campaigns' ) );
                return;
            }
            body.amount_cents = cents;
        }

        setBusy( true );
        setError( null );
        setApproveUrl( null );
        apiFetch( { path: `/fundkit/v1/admin/recurring/${ plan.id }/action`, method: 'POST', data: body } )
            .then( () => { onClose(); if ( onDone ) onDone(); } )
            .catch( ( e ) => {
                setError( e?.message || __( 'That change could not be made.', 'fundkit-fundraising-campaigns' ) );
                // PayPal answers a revision with a link the donor has to open.
                // The API has always returned it and nothing rendered it, so
                // the message said "approve this change" and gave no way to.
                if ( e?.data?.approve_url ) setApproveUrl( e.data.approve_url );
            } )
            .finally( () => setBusy( false ) );
    };

    return (
        <Dialog
            title={ TITLES[ action ] || __( 'Change this donation', 'fundkit-fundraising-campaigns' ) }
            onClose={ () => ( busy ? null : onClose() ) }
            foot={
                <>
                    <Btn variant="secondary" onClick={ onClose } disabled={ busy }>
                        { __( 'Close', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                    <Btn
                        variant="primary"
                        isDestructive={ action === 'cancel' }
                        onClick={ submit }
                        isBusy={ busy }
                        disabled={ busy }
                    >
                        { busy
                            ? __( 'Working…', 'fundkit-fundraising-campaigns' )
                            : ( action === 'retry' ? __( 'Retry now', 'fundkit-fundraising-campaigns' ) : __( 'Apply change', 'fundkit-fundraising-campaigns' ) ) }
                    </Btn>
                </>
            }
        >
            { action === 'change_amount' && (
                <p>
                    <label>
                        { /* The currency is on the control itself, so the label
                             does not name it a second time. */ }
                        <span style={ { display: 'block', marginBottom: 4 } }>
                            { __( 'New amount', 'fundkit-fundraising-campaigns' ) }
                        </span>
                        <AmountInput
                            value={ amount }
                            onChange={ setAmount }
                            currency={ plan.currency }
                            autoFocus
                        />
                    </label>
                </p>
            ) }

            { action === 'pause' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Pause for', 'fundkit-fundraising-campaigns' ) }</span>
                        <select
                            className="fundkit-select"
                            value={ String( months ) }
                            onChange={ ( e ) => setMonths( Number( e.target.value ) ) }
                        >
                            { [ 1, 2, 3, 6, 12 ].map( ( m ) => (
                                <option key={ m } value={ m }>
                                    { sprintf(
                                        /* translators: %d: number of months */
                                        _n( '%d month', '%d months', m, 'fundkit-fundraising-campaigns' ),
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
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Reason (optional)', 'fundkit-fundraising-campaigns' ) }</span>
                        <input
                            type="text"
                            className="fundkit-input"
                            value={ reason }
                            onChange={ ( e ) => setReason( e.target.value ) }
                        />
                    </label>
                </p>
            ) }

            { action === 'retry' && (
                <p>
                    { __( 'The gateway will try to collect the outstanding renewal again now. If it succeeds the donation appears within a few moments, once the gateway confirms it.', 'fundkit-fundraising-campaigns' ) }
                </p>
            ) }

            { action === 'skip_next' && (
                <p>{ __( 'The next payment is skipped and the donation carries on one cycle later. Nothing is charged in between.', 'fundkit-fundraising-campaigns' ) }</p>
            ) }

            { action === 'resume' && (
                <p>{ __( 'Charging restarts on the plan’s normal schedule.', 'fundkit-fundraising-campaigns' ) }</p>
            ) }

            { /* Cancellation always emails through the canceller, so offering
                 the choice on that one action would be a lie. */ }
            { action !== 'cancel' && action !== 'retry' && (
                <div style={ { marginTop: 12, display: 'flex', alignItems: 'center', gap: 8 } }>
                    <Switch
                        checked={ notify }
                        onChange={ setNotify }
                        label={ __( 'Notify donor', 'fundkit-fundraising-campaigns' ) }
                    />
                    <span>{ __( 'Email the donor about this change', 'fundkit-fundraising-campaigns' ) }</span>
                </div>
            ) }

            { error && <p className="dp-error" style={ { marginTop: 12 } }>{ error }</p> }
            { approveUrl && (
                <p style={ { marginTop: 8 } }>
                    <a href={ approveUrl } target="_blank" rel="noreferrer noopener">
                        { __( 'Open the approval page', 'fundkit-fundraising-campaigns' ) }
                    </a>
                    { ' ' }
                    <span className="fundkit-muted">
                        { __( 'The donor has to approve it while signed in to their own account.', 'fundkit-fundraising-campaigns' ) }
                    </span>
                </p>
            ) }
        </Dialog>
    );
}
