import { __, _n, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Spinner } from '@wordpress/components';

import Btn from '../components/Btn';
import Dialog from '../components/Dialog';
import Notice from '../components/Notice';
import { Switch } from '../components/Switch';
import AmountInput from '../components/AmountInput';
import { userCan } from '../caps';
import { isPlanUnstarted } from '../statuses';

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
        return sprintf( _n( '%d day overdue', '%d days overdue', Math.abs( days ), 'gratora-donation-platform' ), Math.abs( days ) );
    }
    if ( days === 0 ) return __( 'today', 'gratora-donation-platform' );
    /* translators: %d: days until the next charge. */
    return sprintf( _n( 'in %d day', 'in %d days', days, 'gratora-donation-platform' ), days );
}

/** A plan with a renewal the gateway has not collected: the row's own complaint. */
const owesARenewal = ( plan ) => ! isTerminal( plan.status )
    && ( plan.failed_renewals_count > 0 || plan.status === 'past_due' );

/**
 * Retry sits outside the menu because it is the one thing an admin opens a
 * failing subscription to do, and it is offered only where it can work:
 * PayPal owns its own retry schedule and exposes no endpoint to force one, so
 * `can_retry` comes from the server rather than from the status.
 */
export function retryActionFor( plan ) {
    if ( ! canManagePlans() ) return null;
    if ( ! owesARenewal( plan ) ) return null;
    if ( ! plan.can_retry ) return null;

    return { id: 'retry', label: __( 'Retry payment', 'gratora-donation-platform' ) };
}

/**
 * The sentence for a row that is asking to be collected and cannot be.
 *
 * Without it the row reads "past due, 3 failures" and offers nothing at all,
 * which looks like a missing feature rather than a gateway that is not set up
 * or does not take the instruction.
 *
 * @param {Object} plan The plan row as the server sends it.
 * @return {?string} Why this one cannot be retried, or null.
 */
export function retryRefusalFor( plan ) {
    if ( ! canManagePlans() ) return null;
    if ( ! owesARenewal( plan ) ) return null;
    if ( plan.can_retry ) return null;

    return plan.retry_blocked || null;
}

/**
 * One action across a selection of plans.
 *
 * A request each, because each one is its own call out to the processor and a
 * single route taking the batch would hold the connection open for all of
 * them. Settled rather than raced: one plan the gateway refuses must not
 * abandon the rest, and the caller reports what actually happened.
 *
 * @param {string} action The action id, as the per-plan route names it.
 * @param {Array}  plans  The plans to act on.
 * @param {Object} extra  Anything the action needs beyond its name.
 * @return {Promise<{ok: number, failed: string[]}>} What happened.
 */
export async function applyToPlans( action, plans, extra = {} ) {
    const results = await Promise.allSettled( plans.map( ( plan ) => apiFetch( {
        path:   `/gratora/v1/admin/recurring/${ plan.id }/action`,
        method: 'POST',
        // Telling the donor is the default here too: the change was not theirs.
        data:   { action, notify_donor: true, ...extra },
    } ) ) );

    return {
        ok: results.filter( ( r ) => r.status === 'fulfilled' ).length,
        failed: results
            .filter( ( r ) => r.status === 'rejected' )
            .map( ( r ) => r.reason?.message )
            .filter( Boolean ),
    };
}

export function actionsFor( plan ) {
    if ( ! canManagePlans() ) return [];
    if ( isTerminal( plan.status ) ) return [];

    const cancel = { id: 'cancel', label: __( 'Cancel subscription', 'gratora-donation-platform' ), destructive: true };

    // A subscription the gateway has not started has no schedule to pause,
    // skip or re-price, and the routes behind those refuse it. Ending it is
    // the one thing that can be done, and it has to stay available: the donor
    // approved it, so it is already against their card.
    if ( isPlanUnstarted( plan.status ) ) {
        return [ cancel ];
    }

    const actions = [];
    if ( plan.status === 'paused' ) {
        actions.push( { id: 'resume', label: __( 'Resume', 'gratora-donation-platform' ) } );
    } else {
        actions.push( { id: 'pause', label: __( 'Pause', 'gratora-donation-platform' ) } );
        actions.push( { id: 'skip_next', label: __( 'Skip next', 'gratora-donation-platform' ) } );
    }
    actions.push( { id: 'change_amount', label: __( 'Change amount', 'gratora-donation-platform' ) } );
    // Most processors mint a mandate against a fixed cadence, so this is a
    // capability the row carries rather than something every plan can do.
    if ( plan.can_change_interval ) {
        actions.push( { id: 'change_interval', label: __( 'Change schedule', 'gratora-donation-platform' ) } );
    }
    actions.push( cancel );

    return actions;
}

/** The five this product can name, in the order a donor reads them. */
const FREQUENCY_LABELS = {
    weekly:    __( 'Every week', 'gratora-donation-platform' ),
    biweekly:  __( 'Every 2 weeks', 'gratora-donation-platform' ),
    monthly:   __( 'Every month', 'gratora-donation-platform' ),
    quarterly: __( 'Every 3 months', 'gratora-donation-platform' ),
    yearly:    __( 'Every year', 'gratora-donation-platform' ),
};

const TITLES = {
    retry:         __( 'Retry the payment', 'gratora-donation-platform' ),
    pause:         __( 'Pause this donation', 'gratora-donation-platform' ),
    resume:        __( 'Resume this donation', 'gratora-donation-platform' ),
    skip_next:     __( 'Skip the next payment', 'gratora-donation-platform' ),
    change_amount:   __( 'Change the amount', 'gratora-donation-platform' ),
    change_interval: __( 'Change the schedule', 'gratora-donation-platform' ),
    cancel:        __( 'Cancel this donation', 'gratora-donation-platform' ),
};

/** The confirm button names the act, not the dialog. */
const CONFIRM_LABELS = {
    retry:  __( 'Retry now', 'gratora-donation-platform' ),
    cancel: __( 'Cancel subscription', 'gratora-donation-platform' ),
};

export default function PlanActionDialog( { plan, action, blocked = [], onClose, onDone } ) {
    const [ busy, setBusy ]     = useState( false );
    const [ error, setError ]   = useState( null );
    // Retry is the one action whose answer is not the end of the story: the
    // gateway takes the instruction and its webhook confirms the money later,
    // so closing on the 200 left an admin with no word either way about the
    // thing they came here to do. The others report by the row changing.
    const [ asked, setAsked ]   = useState( false );
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

    const isRetry = action === 'retry';
    const gateway = plan.gateway_label || plan.gateway || '';

    const submit = () => {
        const body = { action, notify_donor: notify };
        if ( action === 'pause' ) body.months = Number( months ) || 1;
        if ( action === 'cancel' && reason.trim() ) body.reason = reason.trim();
        if ( action === 'change_interval' ) {
            if ( frequency === '' ) {
                setError( __( 'Choose a schedule.', 'gratora-donation-platform' ) );
                return;
            }
            if ( frequency === currentFrequency ) {
                setError( __( 'That is the schedule it is on already.', 'gratora-donation-platform' ) );
                return;
            }
            body.frequency = frequency;
        }
        if ( action === 'change_amount' ) {
            // AmountInput reports a number, so the separator handling that was
            // here belongs to it now.
            const cents = Math.round( Number( amount ) * 100 );
            if ( ! Number.isFinite( cents ) || cents <= 0 ) {
                setError( __( 'Enter an amount.', 'gratora-donation-platform' ) );
                return;
            }
            body.amount_cents = cents;
        }

        setBusy( true );
        setError( null );
        setApproveUrl( null );
        apiFetch( { path: `/gratora/v1/admin/recurring/${ plan.id }/action`, method: 'POST', data: body } )
            .then( () => {
                if ( onDone ) onDone();
                if ( isRetry ) {
                    setAsked( true );
                    return;
                }
                onClose();
            } )
            .catch( ( e ) => {
                setError( e?.message || __( 'That change could not be made.', 'gratora-donation-platform' ) );
                // PayPal answers a revision with a link the donor has to open.
                // The API has always returned it and nothing rendered it, so
                // the message said "approve this change" and gave no way to.
                if ( e?.data?.approve_url ) setApproveUrl( e.data.approve_url );
            } )
            .finally( () => setBusy( false ) );
    };

    // Nothing here is a decision to take, so the dialog carries the reason and
    // a way out and no confirm beside it.
    if ( blocked.length > 0 ) {
        return (
            <Dialog
                title={ _n(
                    'This payment cannot be retried',
                    'These payments cannot be retried',
                    blocked.length,
                    'gratora-donation-platform'
                ) }
                onClose={ onClose }
                foot={
                    <Btn variant="primary" onClick={ onClose }>
                        { __( 'Close', 'gratora-donation-platform' ) }
                    </Btn>
                }
            >
                { blocked.map( ( why ) => (
                    <Notice key={ why } status="warning" isDismissible={ false }>
                        { why }
                    </Notice>
                ) ) }
            </Dialog>
        );
    }

    return (
        <Dialog
            title={ TITLES[ action ] || __( 'Change this donation', 'gratora-donation-platform' ) }
            onClose={ () => ( busy ? null : onClose() ) }
            foot={
                asked ? (
                    <Btn variant="primary" onClick={ onClose }>
                        { __( 'Close', 'gratora-donation-platform' ) }
                    </Btn>
                ) : (
                    <>
                        <Btn variant="secondary" onClick={ onClose } disabled={ busy }>
                            { __( 'Close', 'gratora-donation-platform' ) }
                        </Btn>
                        <Btn
                            variant={ action === 'cancel' ? 'danger' : 'primary' }
                            onClick={ submit }
                            isBusy={ busy }
                            disabled={ busy }
                        >
                            { busy
                                ? __( 'Working…', 'gratora-donation-platform' )
                                : ( CONFIRM_LABELS[ action ] ?? __( 'Apply change', 'gratora-donation-platform' ) ) }
                        </Btn>
                    </>
                )
            }
        >
            { action === 'change_amount' && (
                <p>
                    <label>
                        { /* The currency is on the control itself, so the label
                             does not name it a second time. */ }
                        <span style={ { display: 'block', marginBottom: 4 } }>
                            { __( 'New amount', 'gratora-donation-platform' ) }
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
                            { __( 'Charge this donation', 'gratora-donation-platform' ) }
                        </span>
                        <select
                            className="gratora-select"
                            value={ frequency }
                            onChange={ ( e ) => setFrequency( e.target.value ) }
                            autoFocus
                        >
                            { currentFrequency === '' && (
                                <option value="">{ __( 'Choose a schedule', 'gratora-donation-platform' ) }</option>
                            ) }
                            { ( plan.frequency_options || [] ).map( ( f ) => (
                                <option key={ f } value={ f }>{ FREQUENCY_LABELS[ f ] || f }</option>
                            ) ) }
                        </select>
                    </label>
                    <span className="gratora-row__sub" style={ { display: 'block', marginTop: 6 } }>
                        { __( 'The donor stays paid up to their current date. The new schedule starts from the charge after that.', 'gratora-donation-platform' ) }
                    </span>
                </p>
            ) }

            { action === 'pause' && (
                <p>
                    <label>
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Pause for', 'gratora-donation-platform' ) }</span>
                        <select
                            className="gratora-select"
                            value={ String( months ) }
                            onChange={ ( e ) => setMonths( Number( e.target.value ) ) }
                        >
                            { [ 1, 2, 3, 6, 12 ].map( ( m ) => (
                                <option key={ m } value={ m }>
                                    { sprintf(
                                        /* translators: %d: number of months */
                                        _n( '%d month', '%d months', m, 'gratora-donation-platform' ),
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
                        <span style={ { display: 'block', marginBottom: 4 } }>{ __( 'Reason (optional)', 'gratora-donation-platform' ) }</span>
                        <input
                            type="text"
                            className="gratora-input"
                            value={ reason }
                            onChange={ ( e ) => setReason( e.target.value ) }
                        />
                    </label>
                </p>
            ) }

            { isRetry && ! busy && ! asked && (
                <p>
                    { __( 'The gateway will try to collect the outstanding renewal again now. If it succeeds the donation appears within a few moments, once the gateway confirms it.', 'gratora-donation-platform' ) }
                </p>
            ) }

            { /* The round trip is to the processor, so it is long enough to
                 look like nothing happened. */ }
            { isRetry && busy && (
                <div
                    aria-live="polite"
                    style={ { display: 'flex', alignItems: 'center', gap: 12, padding: '8px 0' } }
                >
                    <Spinner />
                    <div>
                        { gateway
                            ? sprintf(
                                /* translators: %s: the payment gateway's name, such as PayPal. */
                                __( 'Asking %s to collect it…', 'gratora-donation-platform' ),
                                gateway
                            )
                            : __( 'Asking the gateway to collect it…', 'gratora-donation-platform' ) }
                    </div>
                </div>
            ) }

            { /* Taken, not paid: the gateway confirms a collection by webhook,
                 and calling its 200 a payment would report money that has not
                 arrived. */ }
            { asked && (
                <Notice status="success" isDismissible={ false }>
                    { gateway
                        ? sprintf(
                            /* translators: %s: the payment gateway's name, such as PayPal. */
                            __( '%s has been asked to collect it. If it goes through, the donation appears on this plan within a few moments.', 'gratora-donation-platform' ),
                            gateway
                        )
                        : __( 'The gateway has been asked to collect it. If it goes through, the donation appears on this plan within a few moments.', 'gratora-donation-platform' ) }
                </Notice>
            ) }

            { action === 'skip_next' && (
                <p>{ __( 'The next payment is skipped and the donation carries on one cycle later. Nothing is charged in between.', 'gratora-donation-platform' ) }</p>
            ) }

            { action === 'resume' && (
                <p>{ __( 'Charging restarts on the plan’s normal schedule.', 'gratora-donation-platform' ) }</p>
            ) }

            { /* Cancellation always emails through the canceller, so offering
                 the choice on that one action would be a lie. */ }
            { action !== 'cancel' && action !== 'retry' && (
                <div style={ { marginTop: 12, display: 'flex', alignItems: 'center', gap: 8 } }>
                    <Switch
                        checked={ notify }
                        onChange={ setNotify }
                        label={ __( 'Notify donor', 'gratora-donation-platform' ) }
                    />
                    <span>{ __( 'Email the donor about this change', 'gratora-donation-platform' ) }</span>
                </div>
            ) }

            { /* A component, not a bare class: this dialog is shared by two
                 screens with their own stylesheets, and a class defined in one
                 of them renders as ordinary body text on the other. The Notice
                 carries its own styling and announces itself to a screen
                 reader, which a paragraph does not. */ }
            { error && (
                <div style={ { marginTop: 12 } }>
                    <Notice status="error" isDismissible={ false }>
                        { error }
                        { /* The remedy for this particular refusal, so it sits
                             inside it rather than below as a loose line. */ }
                        { approveUrl && (
                            <div style={ { marginTop: 8 } }>
                                <a href={ approveUrl } target="_blank" rel="noreferrer noopener">
                                    { __( 'Open the approval page', 'gratora-donation-platform' ) }
                                </a>
                                { ' ' }
                                { __( 'The donor has to approve it while signed in to their own account.', 'gratora-donation-platform' ) }
                            </div>
                        ) }
                    </Notice>
                </div>
            ) }
        </Dialog>
    );
}
