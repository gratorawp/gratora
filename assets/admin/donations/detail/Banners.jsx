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

    if ( ! isTest && ! isDisputed && ! isFailed && ! isProcessing && ! subFailed && ! replacedBy ) return null;

    return (
        <>
            { isTest && (
                <Banner variant="warn">
                    <strong>{ __( 'Test-mode donation.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                    { __( 'No real money changed hands.', 'dono-fundraising-platform' ) }
                </Banner>
            ) }
            { replacedBy && (
                <Banner variant="warn">
                    <strong>{ __( 'Replaced by a later attempt.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                    { __( 'The donor backed out here and started again, so this attempt is left out of the donations list, its counts and the CSV export.', 'dono-fundraising-platform' ) }
                    { ' ' }
                    { __( 'It is still pending, and it can still settle if the payment it is waiting on goes through.', 'dono-fundraising-platform' ) }
                    <div style={ { marginTop: 6 } }>
                        <a href={ addQueryArgs( window.location.pathname, {
                            page:      'dono-donations',
                            view:      'detail',
                            reference: replacedBy,
                        } ) }>
                            { sprintf(
                                /* translators: %s: the replacement donation's reference. */
                                __( 'Open %s', 'dono-fundraising-platform' ),
                                replacedBy
                            ) }
                        </a>
                    </div>
                </Banner>
            ) }
            { isDisputed && (
                <Banner variant="danger">
                    <strong>{ __( 'Chargeback in progress.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                    { __( 'Review the dispute in your gateway dashboard before refunding.', 'dono-fundraising-platform' ) }
                </Banner>
            ) }
            { isProcessing && (
                <Banner variant="warn">
                    <strong>{ __( 'Payment not settled yet.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                    { donation.processing_reason }
                </Banner>
            ) }
            { isFailed && donation.failure_reason && (
                <Banner variant="danger">
                    <strong>{ __( 'Payment failed.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                    { donation.failure_reason }
                </Banner>
            ) }
            { subFailed && (
                <Banner variant="danger">
                    <strong>{ __( 'Recurring plan was not created.', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                    { __( 'The donor was charged for this donation, but no recurring plan exists at the gateway. Nothing will renew until this is fixed.', 'dono-fundraising-platform' ) }
                    { subFailReason && (
                        <div style={ { marginTop: 6 } }>
                            <strong>{ __( 'Gateway reason:', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                            { subFailReason }
                        </div>
                    ) }
                    { retryError && (
                        <div style={ { marginTop: 6 } }>
                            <strong>{ __( 'Last attempt failed:', 'dono-fundraising-platform' ) }</strong>{ ' ' }
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
                                    ? __( 'Creating plan…', 'dono-fundraising-platform' )
                                    : __( 'Create the recurring plan', 'dono-fundraising-platform' ) }
                            </button>
                        </div>
                    ) }
                </Banner>
            ) }
        </>
    );
}
