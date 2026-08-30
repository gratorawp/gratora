import { __ } from '@wordpress/i18n';

import { formatAmount, formatDateTime, timeAgo } from '../helpers';

function Row( { time, dotCls, title, sub } ) {
    return (
        <div className="dd-tl-row">
            <div className="dd-tl-row__time">
                { timeAgo( time ) }
                <small>{ formatDateTime( time ) }</small>
            </div>
            <div className="dd-tl-row__dot">
                <span className={ `dd-tl-row__dot-inner ${ dotCls }` } />
            </div>
            <div className="dd-tl-row__body">
                <div className="dd-tl-row__title">{ title }</div>
                { sub && <div className="dd-tl-row__sub">{ sub }</div> }
            </div>
        </div>
    );
}

// Build timeline events client-side from the payload: donation lifecycle,
// receipts, refunds, notes. Sorted newest-first.
function buildEvents( { donation, receipts, refunds, notes } ) {
    const events = [];

    if ( donation.created_at ) {
        events.push( {
            id:    'created',
            time:  donation.created_at,
            dot:   'is-info',
            title: __( 'Donation created', 'fundkit-fundraising-campaigns' ),
            sub:   donation.form?.title
                ? <>{ __( 'Through', 'fundkit-fundraising-campaigns' ) } <strong>{ donation.form.title }</strong></>
                : null,
        } );
    }
    if ( donation.paid_at ) {
        events.push( {
            id:    'paid',
            time:  donation.paid_at,
            dot:   'is-ok',
            title: __( 'Payment captured', 'fundkit-fundraising-campaigns' ),
            sub:   donation.gateway_intent_id
                ? <><span style={ { textTransform: 'capitalize' } }>{ donation.gateway }</span>{ ' · ' }<span className="mono">{ donation.gateway_intent_id }</span></>
                : <span style={ { textTransform: 'capitalize' } }>{ donation.gateway }</span>,
        } );
    }
    if ( donation.status === 'failed' ) {
        // There is no failed_at column, and there does not need to be: failing
        // is the last transition a row makes, and the only way back out
        // (markPaid) clears failure_reason, so updated_at is the moment it
        // failed for as long as the reason exists to show.
        events.push( {
            id:    'failed',
            time:  donation.updated_at,
            dot:   'is-error',
            title: __( 'Marked as failed', 'fundkit-fundraising-campaigns' ),
            sub:   donation.failure_reason
                ? <em>&quot;{ donation.failure_reason }&quot;</em>
                : null,
        } );
    }
    ( receipts || [] ).forEach( ( r, ri ) => {
        events.push( {
            id:    `receipt-${ ri }`,
            time:  r.issued_at,
            dot:   'is-info',
            title: __( 'Receipt issued', 'fundkit-fundraising-campaigns' ),
            sub:   <><span className="mono">{ r.receipt_number }</span>{ r.sent_to_email_at && <> · { __( 'emailed', 'fundkit-fundraising-campaigns' ) } { formatDateTime( r.sent_to_email_at ) }</> }</>,
        } );
        if ( r.voided && r.voided_at ) {
            events.push( {
                id:    `receipt-void-${ ri }`,
                time:  r.voided_at,
                dot:   'is-muted',
                title: __( 'Receipt voided', 'fundkit-fundraising-campaigns' ),
                sub:   <span className="mono">{ r.receipt_number }</span>,
            } );
        }
    } );
    ( refunds || [] ).forEach( ( r, ri ) => {
        events.push( {
            id:    `refund-${ ri }`,
            time:  r.occurred_at,
            dot:   r.status === 'succeeded' ? 'is-warn' : 'is-error',
            title: <>{ __( 'Refund', 'fundkit-fundraising-campaigns' ) } <strong>{ formatAmount( r.amount_cents, r.currency ) }</strong></>,
            sub:   r.reason ? <em>&quot;{ r.reason }&quot;</em> : null,
        } );
    } );
    ( notes || [] ).forEach( ( n, ni ) => {
        events.push( {
            id:    `note-${ ni }`,
            time:  n.created_at,
            dot:   'is-muted',
            title: __( 'Note added', 'fundkit-fundraising-campaigns' ),
            sub:   <em>&quot;{ n.body.length > 120 ? n.body.slice( 0, 117 ) + '…' : n.body }&quot;</em>,
        } );
    } );

    return events.sort( ( a, b ) => ( b.time || '' ).localeCompare( a.time || '' ) );
}

export default function TimelineCard( props ) {
    const events = buildEvents( props );
    if ( ! events.length ) return null;
    return (
        <div className="dd-card">
            <div className="dd-card__body">
                <div className="dd-timeline">
                    { events.map( ( e ) => (
                        <Row key={ e.id } time={ e.time } dotCls={ e.dot } title={ e.title } sub={ e.sub } />
                    ) ) }
                </div>
            </div>
        </div>
    );
}
