// The donation columns, shared by the live list and the Trash view.
//
// Both screens are the same table with different columns showing. A renderer
// defined in one and copied to the other drifts, and the two then disagree
// about what a row says while claiming to be the same list.

import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import StatusBadge from '../_shared/components/StatusBadge';
import { rowLinkProps } from '../_shared/rowLink';
import { formatAmount, formatDate, STATUS_LABEL } from './format';
import { timeAgo, detailHref as campaignDetailHref, formEditorHref } from '../_shared/format';

export const STATUS_OPTIONS = Object.entries( STATUS_LABEL ).map( ( [ value, label ] ) => ( {
    value,
    label,
} ) );

/**
 * The stored frequency as a short badge. Values come from the column, so an
 * unknown one is shown rather than swallowed.
 */
export function frequencyLabel( frequency ) {
    switch ( frequency ) {
        case 'monthly':   return __( 'Monthly', 'gratora-donation-platform' );
        case 'yearly':    return __( 'Yearly', 'gratora-donation-platform' );
        case 'weekly':    return __( 'Weekly', 'gratora-donation-platform' );
        case 'quarterly': return __( 'Quarterly', 'gratora-donation-platform' );
        default:          return __( 'Recurring', 'gratora-donation-platform' );
    }
}

// 'recurring' is the useful default question ("which of these repeat?");
// the individual cadences are there for orgs that run more than one.
export const FREQUENCY_OPTIONS = [
    { value: 'recurring', label: __( 'Recurring (any)', 'gratora-donation-platform' ) },
    { value: 'one_time',  label: __( 'One time', 'gratora-donation-platform' ) },
    { value: 'monthly',   label: __( 'Monthly', 'gratora-donation-platform' ) },
    { value: 'yearly',    label: __( 'Yearly', 'gratora-donation-platform' ) },
    { value: 'weekly',    label: __( 'Weekly', 'gratora-donation-platform' ) },
    { value: 'quarterly', label: __( 'Quarterly', 'gratora-donation-platform' ) },
];

export function detailHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page:      'gratora-donations',
        view:      'detail',
        reference,
    } );
}

export function trashHref( extra = {} ) {
    return addQueryArgs( window.location.pathname, {
        page: 'gratora-donations',
        view: 'trash',
        ...extra,
    } );
}

/**
 * Every column either screen can show, keyed by id so each picks its own.
 *
 * A factory rather than a constant: the campaign and gateway filters are built
 * from what the site actually has, which is fetched.
 */
/**
 * What to ask the server to order by.
 *
 * A saved view outlives the column it names, and the server falls back without
 * saying so, which leaves the header drawing an arrow over an order the rows
 * are not in. Anything not on this list goes back to the default rather than
 * travelling as a request that cannot be honoured.
 */
const SORT_COLUMNS = {
    reference:  'reference',
    status:     'status',
    amount:     'amount_cents',
    created_at: 'created_at',
    paid_at:    'paid_at',
    trashed_at: 'trashed_at',
};

/** The ids this table can ask the server to order by. @since 1.0.0 */
export const SORTABLE_FIELD_IDS = Object.keys( SORT_COLUMNS );

/** @since 1.0.0 */
export function sortColumn( field, fallback = 'created_at' ) {
    return SORT_COLUMNS[ field ] || fallback;
}

export function donationFields( { campaigns = [], gatewayOptions = [] } = {} ) {
    return [
        {
            id:            'reference',
            label:         __( 'Reference', 'gratora-donation-platform' ),
            enableSorting: true,
            // The badge rides the reference rather than occupying a column of
            // its own: on a live-only list that column is the same value on
            // every row, and the thing worth knowing is that this particular
            // donation took no money.
            render: ( { item } ) => (
                <span className="gratora-ref-cell">
                    <a className="gratora-mono-link" href={ detailHref( item.reference ) } { ...rowLinkProps }>
                        { item.reference }
                    </a>
                    { item.is_test && (
                        <span className="gratora-pill gratora-pill--test">{ __( 'Test', 'gratora-donation-platform' ) }</span>
                    ) }
                    { item.superseded && (
                        <span className="gratora-pill gratora-pill--gray" title={ __( 'The donor started again on another gateway. Nothing they do now can collect this attempt.', 'gratora-donation-platform' ) }>
                            { __( 'Replaced', 'gratora-donation-platform' ) }
                        </span>
                    ) }
                </span>
            ),
        },
        {
            id:    'frequency',
            enableSorting: false,
            label: __( 'Frequency', 'gratora-donation-platform' ),
            // Nothing on the row said whether the money came from a standing
            // recurring or a one-off, which is the first thing asked of it.
            elements: FREQUENCY_OPTIONS,
            filterBy: { operators: [ 'is' ] },
            // Not StatusBadge: "monthly" is a cadence, not a lifecycle status,
            // so it has no entry in that map and would come out grey.
            render: ( { item } ) => (
                item.frequency && item.frequency !== 'one_time'
                    ? <span className="gratora-pill gratora-pill--blue">{ frequencyLabel( item.frequency ) }</span>
                    : <span className="gratora-pill gratora-pill--gray">{ __( 'One time', 'gratora-donation-platform' ) }</span>
            ),
        },
        {
            id:    'donor',
            enableSorting: false,
            label: __( 'Donor', 'gratora-donation-platform' ),
            render: ( { item } ) => {
                const d = item.donor;
                if ( ! d ) return <span className="gratora-row__sub">-</span>;
                const name = d.name || __( '(no name)', 'gratora-donation-platform' );
                return (
                    <div className="gratora-row">
                        <div className="gratora-row__body">
                            <div className="gratora-row__name">{ name }</div>
                            { d.email && <div className="gratora-row__sub gratora-row__sub--mono">{ d.email }</div> }
                        </div>
                    </div>
                );
            },
        },
        {
            id:            'amount',
            label:         __( 'Amount', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => {
                const showBase =
                    item.base_amount_cents != null &&
                    item.base_currency &&
                    item.base_currency !== item.currency;
                return (
                    <span className={ `gratora-amount${ item.status === 'refunded' ? ' gratora-amount--strike' : '' }` }>
                        { formatAmount( item.amount_cents, item.currency ) }
                        { showBase && (
                            <span className="gratora-amount__base">
                                { '≈ ' }{ formatAmount( item.base_amount_cents, item.base_currency ) }
                            </span>
                        ) }
                    </span>
                );
            },
        },
        {
            id:            'status',
            label:         __( 'Status', 'gratora-donation-platform' ),
            elements:      STATUS_OPTIONS,
            filterBy:      { operators: [ 'is' ] },
            enableSorting: true,
            render:        ( { item } ) => <StatusBadge status={ item.status } />,
        },
        {
            id:       'gateway',
            enableSorting: false,
            label:    __( 'Gateway', 'gratora-donation-platform' ),
            elements: gatewayOptions,
            filterBy: { operators: [ 'is' ] },
            // The row carries its own name, so this reads the same before the
            // filter options have arrived as it does after.
            render: ( { item } ) => {
                if ( ! item.gateway ) return <span>-</span>;
                return <span>{ item.gateway_label || item.gateway }</span>;
            },
        },
        {
            id:       'campaign',
            enableSorting: false,
            label:    __( 'Campaign', 'gratora-donation-platform' ),
            elements: campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title || `#${ c.id }` } ) ),
            filterBy: { operators: [ 'is' ] },
            render: ( { item } ) => {
                if ( ! item.campaign?.title ) {
                    return <span className="gratora-row__sub">-</span>;
                }

                return (
                    <div className="gratora-row">
                        <div className="gratora-row__body">
                            <a className="gratora-row__link" href={ campaignDetailHref( item.campaign.id ) } { ...rowLinkProps }>
                                { item.campaign.title }
                            </a>
                            { /* Who inside the campaign it came through, when
                                 something owns that idea. The campaign alone
                                 does not say whether a donation arrived
                                 through somebody raising for it. */ }
                            { item.attributed_to?.label && (
                                <div className="gratora-row__sub">{ item.attributed_to.label }</div>
                            ) }
                        </div>
                    </div>
                );
            },
        },
        {
            id:     'form',
            enableSorting: false,
            label:  __( 'Form', 'gratora-donation-platform' ),
            render: ( { item } ) => (
                item.form?.title
                    ? <a className="gratora-row__link" href={ formEditorHref( item.form.id ) } { ...rowLinkProps }>{ item.form.title }</a>
                    : <span className="gratora-row__sub">-</span>
            ),
        },
        {
            id:            'created_at',
            label:         __( 'Created', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                <span className="gratora-time" title={ formatDate( item.created_at ) }>
                    <span className="gratora-time__rel">{ timeAgo( item.created_at ) }</span>
                    <span className="gratora-time__abs">{ formatDate( item.created_at ) }</span>
                </span>
            ),
        },
        {
            id:            'trashed_at',
            label:         __( 'Trashed', 'gratora-donation-platform' ),
            enableSorting: true,
            render: ( { item } ) => (
                item.trashed_at
                    ? (
                        <span className="gratora-time" title={ formatDate( item.trashed_at ) }>
                            <span className="gratora-time__rel">{ timeAgo( item.trashed_at ) }</span>
                            <span className="gratora-time__abs">{ formatDate( item.trashed_at ) }</span>
                        </span>
                    )
                    : <span className="gratora-row__sub">-</span>
            ),
        },
        {
            id:    'trashed_by_name',
            enableSorting: false,
            label: __( 'Trashed by', 'gratora-donation-platform' ),
            render: ( { item } ) => (
                item.trashed_by_name
                    ? <span>{ item.trashed_by_name }</span>
                    : <span className="gratora-row__sub">-</span>
            ),
        },
    ];
}
