/**
 * The parts of the plan table that carry a feature rather than a value.
 *
 * The subscriptions list and the donor profile's recurring tab are the same
 * table with different columns showing. Anything defined in one and copied to
 * the other drifts, which is how the tab came to have no way of reading a
 * plan's problems.
 */

import { __, _n, sprintf } from '@wordpress/i18n';

import notify from '../notify';

/**
 * "month" and "2 week" come straight from the database, so the cell has to
 * translate and pluralise them per unit: a translator needs both forms and the
 * singular is not the column value.
 */
export function intervalLabel( unit, count ) {
    const n = Number( count ) || 1;

    switch ( unit ) {
        case 'day':
            /* translators: %d: number of days between charges. */
            return sprintf( _n( '%d day', '%d days', n, 'gratora' ), n );
        case 'week':
            /* translators: %d: number of weeks between charges. */
            return sprintf( _n( '%d week', '%d weeks', n, 'gratora' ), n );
        case 'month':
            /* translators: %d: number of months between charges. */
            return sprintf( _n( '%d month', '%d months', n, 'gratora' ), n );
        case 'year':
            /* translators: %d: number of years between charges. */
            return sprintf( _n( '%d year', '%d years', n, 'gratora' ), n );
        default:
            return n > 1 ? `${ n } ${ unit }` : String( unit );
    }
}

/**
 * The one health cell both tables use. A declined renewal and a failed
 * operation are different facts, so it names whichever it has rather than
 * folding them into one number, and says OK only when there is neither.
 */
export function renderHealth( item ) {
    if ( item.failed_renewals_count > 0 ) {
        return (
            <span className="gratora-pill gratora-pill--amber">
                { sprintf(
                    /* translators: %d: consecutive failed renewals. */
                    _n( '%d failure', '%d failures', item.failed_renewals_count, 'gratora' ),
                    item.failed_renewals_count
                ) }
            </span>
        );
    }

    const problems = item.errors?.length || 0;
    if ( problems > 0 ) {
        return (
            <span className="gratora-pill gratora-pill--red">
                { sprintf(
                    /* translators: %d: recorded problems on this subscription. */
                    _n( '%d problem', '%d problems', problems, 'gratora' ),
                    problems
                ) }
            </span>
        );
    }

    return <span className="gratora-row__sub">{ __( 'OK', 'gratora' ) }</span>;
}

/**
 * Opens the detail dialog. No eligibility gate and not primary, both
 * deliberate: DataViews drops an ineligible action from the row menu entirely
 * and draws a primary one as an icon button that renders as nothing without an
 * icon, so either flag silently removes it.
 */
export function viewDetailsAction( setDetail ) {
    return {
        id:       'view_details',
        label:    __( 'View details', 'gratora' ),
        callback: ( items ) => setDetail( items[ 0 ] ),
    };
}

/** The handle an admin pastes into the gateway dashboard. */
export function copySubscriptionIdAction() {
    return {
        id:         'copy_subscription_id',
        label:      __( 'Copy subscription id', 'gratora' ),
        isPrimary:  false,
        isEligible: ( item ) => !! item.gateway_subscription_id,
        callback:   async ( [ item ] ) => {
            try {
                // Optional chaining resolves to undefined on an insecure
                // origin, which awaits clean and reports a copy nobody made.
                if ( ! window.navigator?.clipboard ) throw new Error( 'no clipboard' );
                await window.navigator.clipboard.writeText( item.gateway_subscription_id );
                notify.success( __( 'Subscription id copied.', 'gratora' ) );
            } catch ( e ) {
                // No clipboard permission, so show it instead of failing
                // silently: it is a lookup key and reading it is the point.
                notify.error( item.gateway_subscription_id );
            }
        },
    };
}
