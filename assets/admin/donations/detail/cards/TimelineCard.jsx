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

function buildEvents( { donation, receipts, refunds, notes } ) {
    const events = [];

    if ( donation.created_at ) {
        events.push( {
            id:    'created',
            time:  donation.created_at,
            dot:   'is-info',
            title: __( 'Donation created', 'gratora-donation-platform' ),
            sub:   donation.form?.title
                ? <>{ __( 'Through', 'gratora-donation-platform' ) } <strong>{ donation.form.title }</strong></>
                : null,
        } );
    }
    if ( donation.paid_at ) {
        events.push( {
            id:    'paid',
            time:  donation.paid_at,
            dot:   'is-ok',
            title: __( 'Payment captured', 'gratora-donation-platform' ),
            sub:   donation.gateway_intent_id
                ? <><span>{ donation.gateway_label || donation.gateway }</span>{ ' · ' }<span className="mono">{ donation.gateway_intent_id }</span></>
                : <span>{ donation.gateway_label || donation.gateway }</span>,
        } );
    }
    if ( donation.status === 'failed' ) {
        // There is no failed_at column, so updated_at stands in for it: failing
        // is the last transition most of these rows make. Anything that writes
        // the row afterwards moves this timestamp, so it is the closest
        // available answer rather than the exact moment.
        events.push( {
            id:    'failed',
            time:  donation.updated_at,
            dot:   'is-error',
            title: __( 'Marked as failed', 'gratora-donation-platform' ),
            sub:   donation.failure_reason
                ? <em>&quot;{ donation.failure_reason }&quot;</em>
                : null,
        } );
    }
    if ( donation.payment_stopped_at ) {
        // Its own entry, and not folded into the trash: restoring the record
        // leaves this true, so the timeline has to keep saying it.
        events.push( {
            id:    'payment-stopped',
            time:  donation.payment_stopped_at,
            dot:   'is-warn',
            title: __( 'Payment stopped', 'gratora-donation-platform' ),
            // The stored reason is a key, not a sentence, so it is named here
            // rather than printed raw.
            sub:   donation.payment_stopped_reason === 'nothing_was_open_to_close'
                ? __( 'There was nothing open at the gateway to close.', 'gratora-donation-platform' )
                : __( 'Closed at the gateway, so nothing further can be collected.', 'gratora-donation-platform' ),
        } );
    }
    ( receipts || [] ).forEach( ( r, ri ) => {
        events.push( {
            id:    `receipt-${ ri }`,
            time:  r.issued_at,
            dot:   'is-info',
            title: __( 'Receipt issued', 'gratora-donation-platform' ),
            sub:   <><span className="mono">{ r.receipt_number }</span>{ r.sent_to_email_at && <> · { __( 'emailed', 'gratora-donation-platform' ) } { formatDateTime( r.sent_to_email_at ) }</> }</>,
        } );
        if ( r.voided && r.voided_at ) {
            events.push( {
                id:    `receipt-void-${ ri }`,
                time:  r.voided_at,
                dot:   'is-muted',
                title: __( 'Receipt voided', 'gratora-donation-platform' ),
                sub:   <span className="mono">{ r.receipt_number }</span>,
            } );
        }
    } );
    ( refunds || [] ).forEach( ( r, ri ) => {
        events.push( {
            id:    `refund-${ ri }`,
            time:  r.occurred_at,
            dot:   r.status === 'succeeded' ? 'is-warn' : 'is-error',
            title: <>{ __( 'Refund', 'gratora-donation-platform' ) } <strong>{ formatAmount( r.amount_cents, r.currency ) }</strong></>,
            sub:   r.reason ? <em>&quot;{ r.reason }&quot;</em> : null,
        } );
    } );
    ( notes || [] ).forEach( ( n, ni ) => {
        events.push( {
            id:    `note-${ ni }`,
            time:  n.created_at,
            dot:   'is-muted',
            title: __( 'Note added', 'gratora-donation-platform' ),
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
