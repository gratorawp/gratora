import { useMemo, useState } from '@wordpress/element';
import { DataViews, filterSortAndPaginate } from '@wordpress/dataviews';
import { __ } from '@wordpress/i18n';
import { Receipt, Download as DownloadIcon } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import { formatDate } from '../helpers';
import { downloadFile } from '../../../_shared/download';
import notify from '../../../_shared/notify';

const RENDERER_LABEL = {
    'generic.v1': __( 'Generic', 'dono' ),
};

export default function ReceiptsTab( { receipts } ) {
    const [ view, setView ] = useState( {
        type:    'table',
        perPage: 25,
        page:    1,
        sort:    { field: 'issued_at', direction: 'desc' },
        filters: [],
        search:  '',
        fields:  [ 'issued_at', 'receipt_number', 'renderer_id', 'sent_to_email_at', 'status' ],
    } );

    const fields = useMemo( () => [
        {
            id:    'issued_at',
            label: __( 'Issued', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => formatDate( item.issued_at ),
        },
        {
            id:    'receipt_number',
            label: __( 'Number', 'dono' ),
            enableSorting: true,
            enableGlobalSearch: true,
            render: ( { item } ) => (
                <span style={ { fontFamily: 'ui-monospace, monospace', fontSize: 12.5 } }>
                    { item.receipt_number }
                </span>
            ),
        },
        {
            id:    'renderer_id',
            label: __( 'Renderer', 'dono' ),
            render: ( { item } ) => RENDERER_LABEL[ item.renderer_id ] || item.renderer_id,
        },
        {
            id:    'sent_to_email_at',
            label: __( 'Sent', 'dono' ),
            enableSorting: true,
            render: ( { item } ) => item.sent_to_email_at ? formatDate( item.sent_to_email_at ) : '-',
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
            id:        'download-pdf',
            label:     __( 'Download PDF', 'dono' ),
            isPrimary: true,
            icon:      () => <DownloadIcon size={ 16 } strokeWidth={ 1.75 } />,
            callback:  ( items ) => {
                items.forEach( ( r ) => downloadFile(
                    `/dono/v1/admin/receipts/${ r.id }/pdf`,
                    `${ r.receipt_number }.pdf`
                ).catch( ( e ) => notify.error( e?.message || __( 'Could not download a receipt.', 'dono' ) ) ) );
            },
        },
    ], [] );

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
                searchLabel={ __( 'Search by number', 'dono' ) }
            />
        </div>
    );
}
