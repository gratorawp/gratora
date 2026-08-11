import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __, _n, sprintf } from '@wordpress/i18n';
import { Receipt, Download as DownloadIcon, Mail as MailIcon } from 'lucide-react';

import Btn from '../../../_shared/components/Btn';
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

// 2000 is the earliest year the statement endpoint will build.
function yearOf( iso ) {
    const n = Number( String( iso || '' ).slice( 0, 4 ) );
    return Number.isInteger( n ) && n >= 2000 ? n : 0;
}

/**
 * The donations prop is the profile's most recent slice, so a frequent donor's
 * earlier years are not in it. Their first and last donation dates span the
 * whole history, and the statement endpoint builds any year from it.
 */
function statementYears( donor, donations ) {
    const found = new Set();
    ( donations || [] ).forEach( ( d ) => {
        if ( d.status !== 'paid' && d.status !== 'partial_refund' ) return;
        const when = d.paid_at || d.created_at;
        if ( when ) found.add( yearOf( when ) );
    } );

    const first = yearOf( donor?.first_donation_at );
    const last  = Math.max( first, yearOf( donor?.last_donation_at ) );
    if ( first ) {
        for ( let y = first; y <= last; y++ ) found.add( y );
    }

    return [ ...found ].filter( Boolean ).sort( ( a, b ) => b - a );
}

/**
 * Year-end tax statement. The document the donor downloads from the portal is a
 * giving summary; this is the one with the org's tax id and the goods-and-
 * services line on it, which is what a donor needs at tax time and only staff
 * can issue.
 */
function TaxStatement( { donor, donations } ) {
    const donorId = donor.id;
    const years = useMemo( () => statementYears( donor, donations ), [ donor, donations ] );

    const [ year, setYear ] = useState( null );
    const [ busy, setBusy ] = useState( false );
    const chosen = year ?? years[ 0 ];

    if ( ! years.length ) return null;

    return (
        <div className="dp-tax-statement">
            <div className="dp-tax-statement__text">
                <strong>{ __( 'Annual tax statement', 'dono' ) }</strong>
                <span>{ __( 'Every paid donation for the year on one document, net of refunds.', 'dono' ) }</span>
            </div>
            <select
                className="dono-input dp-tax-statement__year"
                value={ chosen }
                onChange={ ( e ) => setYear( Number( e.target.value ) ) }
                aria-label={ __( 'Statement year', 'dono' ) }
            >
                { years.map( ( y ) => <option key={ y } value={ y }>{ y }</option> ) }
            </select>
            <Btn
                variant="secondary"
                icon={ <DownloadIcon size={ 16 } strokeWidth={ 1.75 } /> }
                disabled={ busy }
                isBusy={ busy }
                onClick={ async () => {
                    setBusy( true );
                    try {
                        await downloadFile(
                            `/dono/v1/reports/donor/${ donorId }/tax-statement/${ chosen }`,
                            `tax-statement-${ chosen }.pdf`
                        );
                    } catch ( err ) {
                        notify.error( err?.message || __( 'Could not build the statement.', 'dono' ) );
                    } finally {
                        setBusy( false );
                    }
                } }
            >
                { __( 'Download statement', 'dono' ) }
            </Btn>
        </div>
    );
}

export default function ReceiptsTab( { receipts, donations, donor, redacted } ) {
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

    const statement = ! redacted && donor?.id
        ? <TaxStatement donor={ donor } donations={ donations } />
        : null;

    if ( ! receipts.length ) {
        return (
            <>
                { statement }
                <div className="dp-card">
                <EmptyState
                    compact
                    icon={ <Receipt size={ 22 } strokeWidth={ 1.75 } /> }
                    title={ __( 'No receipts yet', 'dono' ) }
                    body={ __( 'Receipts are issued automatically once a donation lands as paid.', 'dono' ) }
                    />
                </div>
            </>
        );
    }

    return (
        <div className="dono-dataviews dp-receipts-dv">
            { statement }
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
