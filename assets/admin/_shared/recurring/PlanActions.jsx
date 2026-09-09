import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Btn from '../components/Btn';
import Dialog from '../components/Dialog';
import { Switch } from '../components/Switch';
import AmountInput from '../components/AmountInput';
import { userCan } from '../caps';

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
 * Changing what a donor is charged is refund-grade authority, and the routes
 * behind these actions are gated on it.
 */
export const canManagePlans = () => userCan( 'refund_donations' );

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
        return sprintf( _n( '%d day overdue', '%d days overdue', Math.abs( days ), 'gratora' ), Math.abs( days ) );
    }
    if ( days === 0 ) return __( 'today', 'gratora' );
    /* translators: %d: days until the next charge. */
    return sprintf( _n( 'in %d day', 'in %d days', days, 'gratora' ), days );
}

export function retryActionFor( plan ) {
    if ( ! canManagePlans() ) return null;
    if ( isTerminal( plan.status ) ) return null;
    if ( ! plan.can_retry ) return null;
    if ( ! ( plan.failed_renewals_count > 0 || plan.status === 'past_due' ) ) return null;

    return { id: 'retry', label: __( 'Retry payment', 'gratora' ) };
}

export function actionsFor( plan ) {
    if ( ! canManagePlans() ) return [];
    if ( isTerminal( plan.status ) ) return [];

    const actions = [];
    if ( plan.status === 'paused' ) {
        actions.push( { id: 'resume', label: __( 'Resume', 'gratora' ) } );
    } else {
        actions.push( { id: 'pause', label: __( 'Pause', 'gratora' ) } );
        actions.push( { id: 'skip_next', label: __( 'Skip next', 'gratora' ) } );
    }
    actions.push( { id: 'change_amount', label: __( 'Change amount', 'gratora' ) } );
    // Most processors mint a mandate against a fixed cadence, so this is a
    // capability the row carries rather than something every plan can do.
    if ( plan.can_change_interval ) {
        actions.push( { id: 'change_interval', label: __( 'Change schedule', 'gratora' ) } );
    }
    actions.push( { id: 'cancel', label: __( 'Cancel subscription', 'gratora' ), destructive: true } );

    return actions;
}

/** The five this product can name, in the order a donor reads them. */
const FREQUENCY_LABELS = {
    weekly:    __( 'Every week', 'gratora' ),
    biweekly:  __( 'Every 2 weeks', 'gratora' ),
    monthly:   __( 'Every month', 'gratora' ),
    quarterly: __( 'Every 3 months', 'gratora' ),
    yearly:    __( 'Every year', 'gratora' ),
};

const TITLES = {
    retry:         __( 'Retry the payment', 'gratora' ),
    pause:         __( 'Pause this donation', 'gratora' ),
    resume:        __( 'Resume this donation', 'gratora' ),
    skip_next:     __( 'Skip the next payment', 'gratora' ),
    change_amount:   __( 'Change the amount', 'gratora' ),
    change_interval: __( 'Change the schedule', 'gratora' ),
    cancel:        __( 'Cancel this donation', 'gratora' ),
};

/** The confirm button names the act, not the dialog. */
const CONFIRM_LABELS = {
    retry:  __( 'Retry now', 'gratora' ),
    cancel: __( 'Cancel subscription', 'gratora' ),
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
    // Not defaulted to monthly: a plan on a cadence this product cannot name
    // would show "Every month" as its current schedule, and one click on Apply
    // would retime the donor to it.
    const currentFrequency = plan.frequency || '';
    const [ frequency, setFrequency ] = useState( currentFrequency );

    const submit = () => {
        const body = { action, notify_donor: notify };
        if ( action === 'pause' ) body.months = Number( months ) || 1;
        if ( action === 'cancel' && reason.trim() ) body.reason = reason.trim();
        if ( action === 'change_interval' ) {
            if ( frequency === '' ) {
                setError( __( 'Choose a schedule.', 'gratora' ) );
                return;
            }
            if ( frequency === currentFrequency ) {
                setError( __( 'That is the schedule it is on already.', 'gratora' ) );
                return;
            }
            body.frequency = frequency;
        }
        if ( action === 'change_amount' ) {
            // AmountInput reports a number, so the separator handling that was
            // here belongs to it now.
            const cents = Math.round( Number( amount ) * 100 );
            if ( ! Number.isFinite( cents ) || cents <= 0 ) {
                setError( __( 'Enter an amount.', 'gratora' ) );
                return;
            }
            body.amount_cents = cents;
        }

        setBusy( true );
        setError( null );
        setApproveUrl( null );
        apiFetch( { path: `/gratora/v1/admin/recurring/${ plan.id }/action`, method: 'POST', data: body } )
            .then( () => { onClose(); if ( onDone ) onDone(); } )
            .catch( ( e ) => {
                setError( e?.message || __( 'That change could not be made.', 'gratora' ) );
                // PayPal answers a revision with a link the donor has to open.
                // The API has always returned it and nothing rendered it, so
                // the message said "approve this change" and gave no way to.
                if ( e?.data?.approve_url ) setApproveUrl( e.data.approve_url );
            } )
            .finally( () => setBusy( false ) );
    };

    return (
        <Dialog
            title={ TITLES[ action ] || __( 'Change this donation', 'gratora' ) }
            onClose={ () => ( busy ? null : onClose() ) }
            foot={
                <>
                    <Btn variant="secondary" onClick={ onClose } disabled={ busy }>
                        { __( 'Close', 'gratora' ) }
                    </Btn>
                    <Btn
                        variant={ action === 'cancel' ? 'danger' : 'primary' }
                        onClick={ submit }
                        isBusy={ busy }
                        disabled={ busy }
                    >
                        { busy
                            ? __( 'Working…', 'gratora' )
                            : ( CONFIRM_LABELS[ action ] ?? __( 'Apply change', 'gratora' ) ) }
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
                            { __( 'New amount', 'gratora' ) }
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

            { action === 'change_interval' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>
                            { __( 'Charge this donation', 'gratora' ) }
                        </span>
                        <select
                            className="gratora-select"
                            value={ frequency }
                            onChange={ ( e ) => setFrequency( e.target.value ) }
                            autoFocus
                        >
                            { currentFrequency === '' && (
                                <option value="">{ __( 'Choose a schedule', 'gratora' ) }</option>
                            ) }
                            { ( plan.frequency_options || [] ).map( ( f ) => (
                                <option key={ f } value={ f }>{ FREQUENCY_LABELS[ f ] || f }</option>
                            ) ) }
                        </select>
                    </label>
                    <span className="gratora-row__sub" style={ { display: 'block', marginTop: 6 } }>
                        { __( 'The donor stays paid up to their current date. The new schedule starts from the charge after that.', 'gratora' ) }
                    </span>
                </p>
            ) }

            { action === 'pause' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Pause for', 'gratora' ) }</span>
                        <select
                            className="gratora-select"
                            value={ String( months ) }
                            onChange={ ( e ) => setMonths( Number( e.target.value ) ) }
                        >
                            { [ 1, 2, 3, 6, 12 ].map( ( m ) => (
                                <option key={ m } value={ m }>
                                    { sprintf(
                                        /* translators: %d: number of months */
                                        _n( '%d month', '%d months', m, 'gratora' ),
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
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Reason (optional)', 'gratora' ) }</span>
                        <input
                            type="text"
                            className="gratora-input"
                            value={ reason }
                            onChange={ ( e ) => setReason( e.target.value ) }
                        />
                    </label>
                </p>
            ) }

            { action === 'retry' && (
                <p>
                    { __( 'The gateway will try to collect the outstanding renewal again now. If it succeeds the donation appears within a few moments, once the gateway confirms it.', 'gratora' ) }
                </p>
            ) }

            { action === 'skip_next' && (
                <p>{ __( 'The next payment is skipped and the donation carries on one cycle later. Nothing is charged in between.', 'gratora' ) }</p>
            ) }

            { action === 'resume' && (
                <p>{ __( 'Charging restarts on the plan’s normal schedule.', 'gratora' ) }</p>
            ) }

            { /* Cancellation always emails through the canceller, so offering
                 the choice on that one action would be a lie. */ }
            { action !== 'cancel' && action !== 'retry' && (
                <div style={ { marginTop: 12, display: 'flex', alignItems: 'center', gap: 8 } }>
                    <Switch
                        checked={ notify }
                        onChange={ setNotify }
                        label={ __( 'Notify donor', 'gratora' ) }
                    />
                    <span>{ __( 'Email the donor about this change', 'gratora' ) }</span>
                </div>
            ) }

            { error && <p className="dp-error" style={ { marginTop: 12 } }>{ error }</p> }
            { approveUrl && (
                <p style={ { marginTop: 8 } }>
                    <a href={ approveUrl } target="_blank" rel="noreferrer noopener">
                        { __( 'Open the approval page', 'gratora' ) }
                    </a>
                    { ' ' }
                    <span className="gratora-muted">
                        { __( 'The donor has to approve it while signed in to their own account.', 'gratora' ) }
                    </span>
                </p>
            ) }
        </Dialog>
    );
}
