import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { IconAlert } from './icons';

function Banner( { variant, children } ) {
    return (
        <div className={ `dd-banner dd-banner--${ variant }` } role="status">
            <IconAlert className="dd-banner__icon" width="20" height="20" />
            <div className="dd-banner__body">{ children }</div>
        </div>
    );
}

export default function Banners( { donation, onRetrySubscription, retryBusy, retryError } ) {
    const isTest       = !! donation.is_test;
    const isDisputed   = donation.status === 'disputed';
    const isFailed     = donation.status === 'failed';
    const isProcessing = donation.status === 'processing' && !! donation.processing_reason;

    const isRecurring = !! donation.frequency && donation.frequency !== 'one_time';
    // Only money the organisation still holds is owed a schedule: a full
    // refund gave it back, a reversal took it away, and anything unsettled
    // never bought a first period.
    const holdsMoney  = [ 'paid', 'partial_refund' ].includes( donation.status );
    const subFailed   = isRecurring
        && holdsMoney
        && ! donation.recurring_plan_id
        && !! donation.flags?.subscription_creation_failed;
    const subFailReason = donation.flags?.subscription_creation_failed_reason;

    // Straight from the payload, not inferred: the server owns what "replaced"
    // means, and this screen is the one place a hidden row is reachable.
    const replacedBy = donation.superseded ? donation.flags?.retried_by : null;

    const isTrashed     = !! donation.trashed_at;
    // Its own banner, and not folded into the one above: a restored row is no
    // longer trashed while its payment stays stopped, and that is the state
    // somebody needs explaining.
    const paymentStopped = !! donation.payment_stopped_at;

    if ( ! isTest && ! isDisputed && ! isFailed && ! isProcessing && ! subFailed && ! replacedBy
        && ! isTrashed && ! paymentStopped ) return null;

    return (
        <>
            { isTrashed && (
                <Banner variant="warn">
                    <strong>{ __( 'In the trash.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'This donation is left out of the donations list, its counts and the CSV export. Nothing has been deleted, and no money total changes.', 'gratora-donation-platform' ) }
                </Banner>
            ) }
            { paymentStopped && (
                <Banner variant="warn">
                    <strong>{ __( 'Payment stopped.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'Whatever the gateway was holding open for this donation has been closed, so nothing further can be collected against it. Restoring the record does not reopen the payment.', 'gratora-donation-platform' ) }
                </Banner>
            ) }
            { isTest && (
                <Banner variant="warn">
                    <strong>{ __( 'Test-mode donation.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'No real money changed hands.', 'gratora-donation-platform' ) }
                </Banner>
            ) }
            { replacedBy && (
                <Banner variant="warn">
                    <strong>{ __( 'Replaced by a later attempt.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'The donor backed out here and started again, so this attempt is left out of the donations list, its counts and the CSV export.', 'gratora-donation-platform' ) }
                    { ' ' }
                    { __( 'It is still pending, and it can still settle if the payment it is waiting on goes through.', 'gratora-donation-platform' ) }
                    <div style={ { marginTop: 6 } }>
                        <a href={ addQueryArgs( window.location.pathname, {
                            page:      'gratora-donations',
                            view:      'detail',
                            reference: replacedBy,
                        } ) }>
                            { sprintf(
                                /* translators: %s: the replacement donation's reference. */
                                __( 'Open %s', 'gratora-donation-platform' ),
                                replacedBy
                            ) }
                        </a>
                    </div>
                </Banner>
            ) }
            { isDisputed && (
                <Banner variant="danger">
                    <strong>{ __( 'Chargeback in progress.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'Review the dispute in your gateway dashboard before refunding.', 'gratora-donation-platform' ) }
                </Banner>
            ) }
            { isProcessing && (
                <Banner variant="warn">
                    <strong>{ __( 'Payment not settled yet.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { donation.processing_reason }
                </Banner>
            ) }
            { isFailed && donation.failure_reason && (
                <Banner variant="danger">
                    <strong>{ __( 'Payment failed.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { donation.failure_reason }
                </Banner>
            ) }
            { subFailed && (
                <Banner variant="danger">
                    <strong>{ __( 'Recurring plan was not created.', 'gratora-donation-platform' ) }</strong>{ ' ' }
                    { __( 'The donor was charged for this donation, but no recurring plan exists at the gateway. Nothing will renew until this is fixed.', 'gratora-donation-platform' ) }
                    { subFailReason && (
                        <div style={ { marginTop: 6 } }>
                            <strong>{ __( 'Gateway reason:', 'gratora-donation-platform' ) }</strong>{ ' ' }
                            { subFailReason }
                        </div>
                    ) }
                    { retryError && (
                        <div style={ { marginTop: 6 } }>
                            <strong>{ __( 'Last attempt failed:', 'gratora-donation-platform' ) }</strong>{ ' ' }
                            { retryError }
                        </div>
                    ) }
                    { onRetrySubscription && (
                        <div style={ { marginTop: 10 } }>
                            <button
                                type="button"
                                className="btn btn--primary btn--sm"
                                onClick={ onRetrySubscription }
                                disabled={ retryBusy }
                            >
                                { retryBusy
                                    ? __( 'Creating plan…', 'gratora-donation-platform' )
                                    : __( 'Create the recurring plan', 'gratora-donation-platform' ) }
                            </button>
                        </div>
                    ) }
                </Banner>
            ) }
        </>
    );
}
