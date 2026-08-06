import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Receipt, Download as DownloadIcon, Mail as MailIcon } from 'lucide-react';

import ConfirmDialog from '../../../_shared/components/ConfirmDialog';
import EmptyState from '../../../_shared/components/EmptyState';
import { formatDateTime, timeAgo } from '../helpers';
import { downloadFile } from '../../../_shared/download';
import { notify } from '../../../_shared/notify';

function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, { page: 'dono-donations', view: 'detail', reference } );
}

function StackedDate( { iso } ) {
    if ( ! iso ) return '-';
    return (
        <div className="dono-row">
            <div className="dono-row__body">
                <div className="dono-row__name">{ timeAgo( iso ) }</div>
                <div className="dono-row__sub">{ formatDateTime( iso ) }</div>
            </div>
        </div>
    );
}

export default function ReceiptsTab( { receipts, redacted } ) {
    const [ confirm, setConfirm ] = useState( null );
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'issued_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'receipt_number', 'donation_reference', 'issued_at', 'sent_to_email_at', 'status' ],
    } );

    const fields = useMemo( () => [
        {
            id:    'receipt_number',
            label: __( 'Receipt', 'dono' ),
            enableSorting: true,
            enableGlobalSearch: true,
            // Plain mono, not a link: a receipt has no page of its own, and the
            // PDF is behind the row menu.
            render: ( { item } ) => <span className="dono-mono">{ item.receipt_number }</span>,
        },
        {
            id:    'donation_reference',
            label: __( 'Donation', 'dono' ),
            enableSorting: true,
            enableGlobalSearch: true,
            render: ( { item } ) => item.donation_reference
                ? (
                    <a className="dono-mono-link" href={ donationHref( item.donation_reference ) }>
                        { item.donation_reference }
                    </a>
                )
                : '-',
        },
        {
            id:    'issued_at',
            label: __( 'Issued', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => <StackedDate iso={ item.issued_at } />,
        },
        {
            id:    'sent_to_email_at',
            label: __( 'Sent', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => <StackedDate iso={ item.sent_to_email_at } />,
        },
        {
            id:    'status',
            label: __( 'Status', 'dono' ),
            enableSorting: false,
            getValue: ( { item } ) => item.voided ? 'voided' : 'issued',
            render: ( { item } ) => item.voided
                ? <span className="dp-pill is-muted">{ __( 'Voided', 'dono' ) }</span>
                : <span className="dp-pill is-ok">{ __( 'Issued', 'dono' ) }</span>,
        },
    ], [] );

    const { data: rows, paginationInfo } = useMemo(
        () => filterSortAndPaginate( receipts, view, fields ),
        [ receipts, view, fields ]
    );

    const actions = useMemo( () => [
        {
            id:       'download-pdf',
            label:    __( 'Download PDF', 'dono' ),
            icon:     () => <DownloadIcon size={ 16 } strokeWidth={ 1.75 } />,
            callback: ( items ) => {
                items.forEach( ( r ) => downloadFile(
                    `/dono/v1/admin/receipts/${ r.id }/pdf`,
                    `${ r.receipt_number }.pdf`
                ).catch( ( e ) => notify.error( e?.message || __( 'Could not download a receipt.', 'dono' ) ) ) );
            },
        },
        {
            id:           'resend',
            label:        __( 'Resend receipt', 'dono' ),
            icon:         () => <MailIcon size={ 16 } strokeWidth={ 1.75 } />,
            supportsBulk: true,
            // Resend goes out over the donation, so a receipt with no reference
            // has nothing to send against. An erased donor has no address left,
            // and a voided receipt is one we have withdrawn.
            isEligible:   ( item ) => !! item.donation_reference && ! item.voided && ! redacted,
            callback: ( items ) => {
                // DataViews hands the callback the whole selection rather than
                // the eligible subset, so isEligible has to be repeated here.
                const targets = items.filter( ( r ) => r.donation_reference && ! r.voided && ! redacted );
                if ( ! targets.length ) return;
                const n = targets.length;
                const message = n === 1
                    ? __( 'Resend this receipt to the donor?', 'dono' )
                    : sprintf(
                        /* translators: %d: receipt count */
                        _n( 'Resend %d receipt to the donor?', 'Resend %d receipts to the donor?', n, 'dono' ),
                        n
                    );
                setConfirm( {
                    title:        __( 'Resend receipts', 'dono' ),
                    message,
                    confirmLabel: __( 'Resend', 'dono' ),
                    onConfirm: async () => {
                        // Silence reads as nothing happening, so admins press it
                        // again and the donor gets the receipt twice.
                        const results = await Promise.allSettled( targets.map( ( r ) => apiFetch( {
                            path:   `/dono/v1/admin/donations/${ encodeURIComponent( r.donation_reference ) }/resend-receipt`,
                            method: 'POST',
                        } ) ) );

                        const sent   = results.filter( ( x ) => x.status === 'fulfilled' ).length;
                        const failed = results.length - sent;

                        if ( sent > 0 ) {
                            notify.success( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt resent.', '%d receipts resent.', sent, 'dono' ),
                                sent
                            ) );
                        }
                        if ( failed > 0 ) {
                            notify.error( sprintf(
                                /* translators: %d: receipt count */
                                _n( '%d receipt could not be resent.', '%d receipts could not be resent.', failed, 'dono' ),
                                failed
                            ) );
                        }
                    },
                } );
            },
        },
    ], [ redacted ] );

    if ( ! receipts.length ) {
        return (
            <div className="dp-card">
                <EmptyState
                    compact
                    icon={ <Receipt size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No receipts yet', 'dono' ) }
                    body={ __( 'Receipts are issued automatically once a donation lands as paid.', 'dono' ) }
                />
            </div>
        );
    }

    return (
        <div className="dono-dataviews dp-receipts-dv">
            <DataViews
                data={ rows }
                isLoading={ false }
                fields={ fields }
                view={ view }
                onChangeView={ setView }
                actions={ actions }
                paginationInfo={ paginationInfo }
                defaultLayouts={ { table: {} } }
                getItemId={ ( item ) => String( item.id ) }
                searchLabel={ __( 'Search receipts', 'dono' ) }
            />
            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
