/**
 * Donation detail view.
 *
 * GET /fundkit/v1/admin/donations/{reference} returns:
 *   { donation, donor, receipts, refunds, related, notes }
 */

import { useState, useEffect, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import Notice from '../_shared/components/Notice';
import ConfirmDialog from '../_shared/components/ConfirmDialog';
import Dialog from '../_shared/components/Dialog';
import Btn from '../_shared/components/Btn';
import notify from '../_shared/notify';
import { useExtensionTabs, ExtensionTabPanel } from '../_shared/extensionTabs';
import { __, sprintf } from '@wordpress/i18n';

import Header   from './detail/Header';
import Banners  from './detail/Banners';
import RefundDialog from './detail/RefundDialog';
import OverviewCard         from './detail/cards/OverviewCard';
import DonorCard            from './detail/cards/DonorCard';
import ReceiptCard          from './detail/cards/ReceiptCard';
import PaymentDetailsCard   from './detail/cards/PaymentDetailsCard';
import RefundsCard          from './detail/cards/RefundsCard';
import TimelineCard         from './detail/cards/TimelineCard';
import NotesCard            from './detail/cards/NotesCard';
import RelatedDonationsCard from './detail/cards/RelatedDonationsCard';
import QuickStatsCard       from './detail/rail/QuickStatsCard';
import ActionsCard          from './detail/rail/ActionsCard';
import MetadataCard         from './detail/rail/MetadataCard';

import './donations.scss';

function listHref() {
    return addQueryArgs( window.location.pathname, { page: 'fundkit-donations' } );
}

function donorHref( donorId ) {
    return addQueryArgs( window.location.pathname, { page: 'fundkit-donors' } ) + `#donor/${ donorId }`;
}

export default function Detail( { reference } ) {
    const [ payload, setPayload ] = useState( null );
    const [ loading, setLoading ] = useState( true );
    const [ error, setError ]     = useState( null );
    const [ showRefund, setShowRefund ] = useState( false );

    // This surface is a card stack rather than a tab bar, so a registered
    // panel renders as another card in the main column. The registry contract
    // is "mount a node with context", which is presentation-agnostic; only
    // the name says tab.
    const extPanels = useExtensionTabs( 'donation' );
    const [ confirm, setConfirm ] = useState( null );
    const [ failOpen, setFailOpen ]     = useState( false );
    const [ failReason, setFailReason ] = useState( '' );
    const [ failBusy, setFailBusy ]     = useState( false );
    const [ retryBusy, setRetryBusy ]   = useState( false );
    const [ retryError, setRetryError ] = useState( null );

    const load = useCallback( () => {
        setLoading( true );
        setError( null );
        return apiFetch( { path: `/fundkit/v1/admin/donations/${ reference }` } )
            .then( ( d ) => { setPayload( d ); setError( null ); } )
            .catch( ( e ) => setError( e?.message || __( 'Could not load donation.', 'fundraising-toolkit' ) ) )
            .finally( () => setLoading( false ) );
    }, [ reference ] );

    useEffect( () => { load(); }, [ load ] );

    const back = () => { window.location.href = listHref(); };

    if ( loading && ! payload ) return <p className="dd-loading">{ __( 'Loading donation…', 'fundraising-toolkit' ) }</p>;

    // Only when there is nothing to fall back to: a reload that fails after a
    // refund must not replace the screen the refund is on with one sentence.
    if ( error && ! payload ) {
        return (
            <div className="dd-shell">
                <Notice status="error" isDismissible={ false }>{ error }</Notice>
                <p>
                    <Btn variant="secondary" onClick={ load }>{ __( 'Try again', 'fundraising-toolkit' ) }</Btn>
                    { ' ' }
                    <Btn onClick={ back }>{ __( 'Back to donations', 'fundraising-toolkit' ) }</Btn>
                </p>
            </div>
        );
    }

    if ( ! payload )            return null;

    const { donation, donor, receipts, refunds, related, notes } = payload;

    const resendReceipt = async () => {
        try {
            await apiFetch( {
                path:   `/fundkit/v1/admin/donations/${ donation.reference }/resend-receipt`,
                method: 'POST',
            } );
            notify.success( __( 'Receipt re-queued.', 'fundraising-toolkit' ) );
            load();
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not resend receipt.', 'fundraising-toolkit' ) );
        }
    };

    const markPaid = () => {
        setConfirm( {
            title:        __( 'Mark donation as paid', 'fundraising-toolkit' ),
            message:      __( 'Mark this donation as paid? This issues the receipt and updates donor totals.', 'fundraising-toolkit' ),
            confirmLabel: __( 'Mark as paid', 'fundraising-toolkit' ),
            onConfirm: async () => {
                try {
                    await apiFetch( {
                        path:   `/fundkit/v1/admin/donations/${ donation.reference }/mark-paid`,
                        method: 'POST',
                    } );
                    notify.success( __( 'Donation marked as paid.', 'fundraising-toolkit' ) );
                    load();
                } catch ( err ) {
                    notify.error( err?.message || __( 'Could not mark donation as paid.', 'fundraising-toolkit' ) );
                }
            },
        } );
    };

    // Only Stripe reports a refund the gateway gave up on. Everywhere else the
    // held balance would stand for good, so the operator says so by hand.
    const releaseRefund = ( refund ) => {
        setConfirm( {
            title:        __( 'Release the held amount', 'fundraising-toolkit' ),
            message:      __( 'Say this refund never reached the donor? The amount goes back to what can be refunded. Do this only once the gateway shows it did not go through, or the donor could be repaid twice.', 'fundraising-toolkit' ),
            confirmLabel: __( 'It never arrived', 'fundraising-toolkit' ),
            onConfirm: async () => {
                try {
                    await apiFetch( {
                        path:   `/fundkit/v1/admin/donations/${ donation.reference }/release-refund`,
                        method: 'POST',
                        data:   { gateway_refund_id: refund.gateway_refund_id },
                    } );
                    notify.success( __( 'The held amount is refundable again.', 'fundraising-toolkit' ) );
                    load();
                } catch ( err ) {
                    notify.error( err?.message || __( 'Could not release the held amount.', 'fundraising-toolkit' ) );
                }
            },
        } );
    };

    const retrySubscription = () => {
        setConfirm( {
            title:        __( 'Create the recurring plan', 'fundraising-toolkit' ),
            message:      __( 'Create the recurring plan at the gateway from this donation? The donor is not charged again today. The schedule restarts from now, so any renewal that fell due since this donation was made is not collected.', 'fundraising-toolkit' ),
            confirmLabel: __( 'Create plan', 'fundraising-toolkit' ),
            onConfirm: async () => {
                setRetryBusy( true );
                setRetryError( null );
                try {
                    await apiFetch( {
                        path:   `/fundkit/v1/admin/donations/${ donation.reference }/retry-subscription`,
                        method: 'POST',
                    } );
                    notify.success( __( 'Recurring plan created.', 'fundraising-toolkit' ) );
                    await load();
                } catch ( err ) {
                    // The gateway message is the diagnostic, so it goes through unedited.
                    const reason = err?.message || __( 'Could not create the recurring plan.', 'fundraising-toolkit' );
                    setRetryError( reason );
                    notify.error( reason );
                } finally {
                    setRetryBusy( false );
                }
            },
        } );
    };

    const markFailed = () => {
        setFailReason( '' );
        setFailOpen( true );
    };

    const submitFailed = async () => {
        const reason = failReason.trim();
        setFailBusy( true );
        try {
            await apiFetch( {
                path:   `/fundkit/v1/admin/donations/${ donation.reference }/mark-failed`,
                method: 'POST',
                data:   reason ? { reason } : {},
            } );
            setFailOpen( false );
            notify.success( __( 'Donation marked as failed.', 'fundraising-toolkit' ) );
            load();
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not update donation.', 'fundraising-toolkit' ) );
        } finally {
            setFailBusy( false );
        }
    };

    const openRefund  = () => setShowRefund( true );
    const closeRefund = () => setShowRefund( false );
    const refundDone  = ( result ) => {
        setShowRefund( false );
        // The gateway can accept a refund without settling it (a bank refund,
        // a PayPal eCheck). Calling that "issued" reads as money the donor has
        // back, and the donation stays paid until the gateway says otherwise.
        const settled = result?.refund?.status !== 'pending';
        notify.success(
                result?.plan?.stopped
                    ? ( settled
                        ? __( 'Refund issued, and the recurring schedule is stopped.', 'fundraising-toolkit' )
                        : __( 'Refund accepted by the gateway, and the recurring schedule is stopped.', 'fundraising-toolkit' ) )
                    : ( settled
                        ? __( 'Refund issued.', 'fundraising-toolkit' )
                        : __( 'Refund accepted by the gateway.', 'fundraising-toolkit' ) )
            );
        if ( ! settled ) {
            notify.info(
                __( 'It has not settled yet, so the donor does not have the money back and the donation stays paid. This amount is already off the refundable balance.', 'fundraising-toolkit' ),
                { duration: 0 }
            );
        }
        // Sticky: the money moved and the schedule did not stop, so this has to
        // survive long enough to be acted on.
        if ( result?.plan && ! result.plan.stopped ) {
            notify.error(
                result.plan.error
                    ? sprintf(
                        /* translators: %s: why the schedule could not be cancelled */
                        __( 'The refund went through, but the recurring schedule was not cancelled. Cancel it from the Subscriptions screen. Reason: %s', 'fundraising-toolkit' ),
                        result.plan.error
                    )
                    : __( 'The refund went through, but the recurring schedule was not cancelled. Cancel it from the Subscriptions screen.', 'fundraising-toolkit' ),
                { duration: 0 }
            );
        }
        load();
    };

    const scrollToNotes = () => {
        const el = document.querySelector( '[data-dd-notes]' );
        if ( el ) el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
    };

    return (
        <div className="dd-shell">
            { /* A reload that failed while the screen still has data: said
                 above the cards rather than instead of them. */ }
            { error && (
                <Notice status="error" onRemove={ () => setError( null ) }>{ error }</Notice>
            ) }
            <Header
                donation={ donation }
                donor={ donor }
                onBack={ back }
                onResendReceipt={ resendReceipt }
                onRefund={ openRefund }
            />

            <Banners
                donation={ donation }
                onRetrySubscription={ retrySubscription }
                retryBusy={ retryBusy }
                retryError={ retryError }
            />

            <div className="dd-layout">
                <div className="dd-main">
                    <OverviewCard donation={ donation } />
                    <DonorCard
                        donor={ donor }
                        donationName={ donation.donor_name_given }
                        isAnonymous={ donation.is_anonymous }
                        onOpenDonor={ ( id ) => { window.location.href = donorHref( id ); } }
                    />
                    <ReceiptCard donation={ donation } receipts={ receipts } onResend={ resendReceipt } />
                    <PaymentDetailsCard donation={ donation } />
                    <RefundsCard donation={ donation } refunds={ refunds } onIssue={ openRefund } onRelease={ releaseRefund } />
                    <TimelineCard donation={ donation } receipts={ receipts } refunds={ refunds } notes={ notes } />
                    <div data-dd-notes>
                        <NotesCard donationRef={ donation.reference } notes={ notes } onChanged={ load } />
                    </div>
                    <RelatedDonationsCard donor={ donor } related={ related } />

                    { extPanels.map( ( panel ) => (
                        <ExtensionTabPanel
                            key={ panel.id }
                            tab={ panel }
                            context={ { donation, donor, receipts, refunds } }
                        />
                    ) ) }
                </div>

                <aside className="dd-rail">
                    <QuickStatsCard donor={ donor } donation={ donation } related={ related } />
                    <ActionsCard
                        donation={ donation }
                        donor={ donor }
                        receipts={ receipts }
                        onRefund={ openRefund }
                        onResend={ resendReceipt }
                        onAddNote={ scrollToNotes }
                        onMarkPaid={ markPaid }
                        onMarkFailed={ markFailed }
                    />
                    <MetadataCard donation={ donation } />
                </aside>
            </div>

            { showRefund && (
                <RefundDialog
                    donation={ donation }
                    plan={ payload.recurring_plan || null }
                    onClose={ closeRefund }
                    onSuccess={ refundDone }
                />
            ) }

            { failOpen && (
                <Dialog
                    title={ __( 'Mark donation as failed', 'fundraising-toolkit' ) }
                    onClose={ () => setFailOpen( false ) }
                    foot={
                        <>
                            <Btn variant="secondary" onClick={ () => setFailOpen( false ) } disabled={ failBusy }>
                                { __( 'Cancel', 'fundraising-toolkit' ) }
                            </Btn>
                            <Btn variant="danger" onClick={ submitFailed } isBusy={ failBusy }>
                                { __( 'Mark as failed', 'fundraising-toolkit' ) }
                            </Btn>
                        </>
                    }
                >
                    <p style={ { marginTop: 0 } }>
                        { __( 'Mark this donation as failed? Optionally add a reason (shown in the donation timeline). It will be excluded from totals.', 'fundraising-toolkit' ) }
                    </p>
                    <textarea
                        className="fundkit-textarea"
                        value={ failReason }
                        onChange={ ( e ) => setFailReason( e.target.value ) }
                        placeholder={ __( 'Reason (optional)', 'fundraising-toolkit' ) }
                        rows={ 3 }
                        style={ { width: '100%' } }
                    />
                </Dialog>
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
