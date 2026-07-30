/**
 * Donation detail view.
 *
 * GET /dono/v1/admin/donations/{reference} returns:
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
import { __ } from '@wordpress/i18n';

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
    return addQueryArgs( window.location.pathname, { page: 'dono-donations' } );
}

function donorHref( donorId ) {
    return addQueryArgs( window.location.pathname, { page: 'dono-donors' } ) + `#donor/${ donorId }`;
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

    const load = useCallback( () => {
        setLoading( true );
        return apiFetch( { path: `/dono/v1/admin/donations/${ reference }` } )
            .then( ( d ) => { setPayload( d ); setError( null ); } )
            .catch( ( e ) => setError( e?.message || __( 'Could not load donation.', 'dono' ) ) )
            .finally( () => setLoading( false ) );
    }, [ reference ] );

    useEffect( () => { load(); }, [ load ] );

    if ( loading && ! payload ) return <p className="dd-loading">{ __( 'Loading donation…', 'dono' ) }</p>;
    if ( error )                return <Notice status="error">{ error }</Notice>;
    if ( ! payload )            return null;

    const { donation, donor, receipts, refunds, related, notes } = payload;
    const back = () => { window.location.href = listHref(); };

    const resendReceipt = async () => {
        try {
            await apiFetch( {
                path:   `/dono/v1/admin/donations/${ donation.reference }/resend-receipt`,
                method: 'POST',
            } );
            notify.success( __( 'Receipt re-queued.', 'dono' ) );
            load();
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not resend receipt.', 'dono' ) );
        }
    };

    const markPaid = () => {
        setConfirm( {
            title:        __( 'Mark donation as paid', 'dono' ),
            message:      __( 'Mark this donation as paid? This issues the receipt and updates donor totals.', 'dono' ),
            confirmLabel: __( 'Mark as paid', 'dono' ),
            onConfirm: async () => {
                try {
                    await apiFetch( {
                        path:   `/dono/v1/admin/donations/${ donation.reference }/mark-paid`,
                        method: 'POST',
                    } );
                    notify.success( __( 'Donation marked as paid.', 'dono' ) );
                    load();
                } catch ( err ) {
                    notify.error( err?.message || __( 'Could not mark donation as paid.', 'dono' ) );
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
                path:   `/dono/v1/admin/donations/${ donation.reference }/mark-failed`,
                method: 'POST',
                data:   reason ? { reason } : {},
            } );
            setFailOpen( false );
            notify.success( __( 'Donation marked as failed.', 'dono' ) );
            load();
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not update donation.', 'dono' ) );
        } finally {
            setFailBusy( false );
        }
    };

    const openRefund  = () => setShowRefund( true );
    const closeRefund = () => setShowRefund( false );
    const refundDone  = () => {
        setShowRefund( false );
        notify.success( __( 'Refund issued.', 'dono' ) );
        load();
    };

    const scrollToNotes = () => {
        const el = document.querySelector( '[data-dd-notes]' );
        if ( el ) el.scrollIntoView( { behavior: 'smooth', block: 'start' } );
    };

    return (
        <div className="dd-shell">
            <Header
                donation={ donation }
                donor={ donor }
                onBack={ back }
                onResendReceipt={ resendReceipt }
                onRefund={ openRefund }
            />

            <Banners donation={ donation } />

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
                    <RefundsCard donation={ donation } refunds={ refunds } onIssue={ openRefund } />
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
                <RefundDialog donation={ donation } onClose={ closeRefund } onSuccess={ refundDone } />
            ) }

            { failOpen && (
                <Dialog
                    title={ __( 'Mark donation as failed', 'dono' ) }
                    onClose={ () => setFailOpen( false ) }
                    foot={
                        <>
                            <Btn variant="secondary" onClick={ () => setFailOpen( false ) } disabled={ failBusy }>
                                { __( 'Cancel', 'dono' ) }
                            </Btn>
                            <Btn variant="danger" onClick={ submitFailed } isBusy={ failBusy }>
                                { __( 'Mark as failed', 'dono' ) }
                            </Btn>
                        </>
                    }
                >
                    <p style={ { marginTop: 0 } }>
                        { __( 'Mark this donation as failed? Optionally add a reason (shown in the donation timeline). It will be excluded from totals.', 'dono' ) }
                    </p>
                    <textarea
                        className="dono-textarea"
                        value={ failReason }
                        onChange={ ( e ) => setFailReason( e.target.value ) }
                        placeholder={ __( 'Reason (optional)', 'dono' ) }
                        rows={ 3 }
                        style={ { width: '100%' } }
                    />
                </Dialog>
            ) }

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
