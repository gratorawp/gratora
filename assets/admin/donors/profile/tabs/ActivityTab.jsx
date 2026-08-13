import { __, _n, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';
import { Coins, History } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';

// Deep-link to a donation's detail view (same target the Donations tab uses).
function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, { page: 'dono-donations', view: 'detail', reference } );
}
import { formatAmount, formatDateTime, formatDate, timeAgo, donationStatusPill, eventMeta } from '../helpers';
import {
    IconCheck, IconAlert, IconRotate, IconNote, IconClock, IconRefund, IconFile,
} from '../icons';

export function TimelineDot( { variant } ) {
    const Icon =
        variant === 'is-ok'    ? IconCheck :
        variant === 'is-warn'  ? IconAlert :
        variant === 'is-info'  ? IconFile :
        variant === 'is-violet'? IconRotate :
        variant === 'is-rose'  ? IconNote :
        variant === 'is-error' ? IconRefund :
                                  IconClock;
    return (
        <span className={ `dp-tl-dot ${ variant }` }>
            <Icon width="13" height="13" />
        </span>
    );
}

/**
 * What an event says, in a sentence.
 *
 * Shared by the overview timeline and the paged log so the two cannot drift.
 * The cases are the event types the recorder actually writes: they were keyed
 * off names nothing emitted (donation.paid, recurring_plan.renewed,
 * consent.granted), so every row fell through to a bare label and the amount
 * these branches exist to show was never rendered.
 *
 * `campaignTitle` is passed in because the two callers hold it differently:
 * the overview has a map keyed by id, the log has it on the row.
 */
export function eventTitle( event, campaignTitle ) {
    const meta = eventMeta( event );
    const amount = event.amount_cents !== null && event.amount_cents !== undefined
        ? formatAmount( event.amount_cents, event.currency )
        : null;

    const toCampaign = campaignTitle
        ? <> { __( 'to', 'dono-fundraising-platform' ) } <span className="dp-tl-camp">{ campaignTitle }</span></>
        : null;

    let title = <>{ meta.label }</>;

    switch ( event.type ) {
        case 'donation.completed':
            title = amount
                ? <>{ __( 'Donated', 'dono-fundraising-platform' ) } <strong>{ amount }</strong>{ toCampaign }</>
                : title;
            break;
        case 'donation.intent_created':
            title = amount
                ? <>{ __( 'Started a donation of', 'dono-fundraising-platform' ) } <strong>{ amount }</strong>{ toCampaign }</>
                : title;
            break;
        case 'donation.failed':
            title = amount
                ? <>{ __( 'Payment of', 'dono-fundraising-platform' ) } <strong>{ amount }</strong> { __( 'failed', 'dono-fundraising-platform' ) }</>
                : title;
            break;
        case 'donation.refunded':
            title = amount
                ? <>{ __( 'Refund of', 'dono-fundraising-platform' ) } <strong>{ amount }</strong> { __( 'issued', 'dono-fundraising-platform' ) }</>
                : title;
            break;
        case 'recurring.renewed':
            title = amount
                ? <>{ __( 'Recurring renewal of', 'dono-fundraising-platform' ) } <strong>{ amount }</strong>{ toCampaign }</>
                : title;
            break;
        case 'recurring.failed':
            title = amount
                ? <>{ __( 'Renewal of', 'dono-fundraising-platform' ) } <strong>{ amount }</strong> { __( 'was declined', 'dono-fundraising-platform' ) }</>
                : title;
            break;
        case 'recurring.amount_changed': {
            const from = event.payload?.from_cents;
            const to   = event.payload?.to_cents;
            title = ( from !== undefined && to !== undefined )
                ? (
                    <>
                        { __( 'Recurring amount changed from', 'dono-fundraising-platform' ) } <strong>{ formatAmount( from, event.currency ) }</strong>
                        { ' ' }{ __( 'to', 'dono-fundraising-platform' ) } <strong>{ formatAmount( to, event.currency ) }</strong>
                    </>
                )
                : title;
            break;
        }
        default:
            break;
    }

    return title;
}

/** One line of the donor's history on the overview. */
function TimelineRow( { event, campaigns } ) {
    const meta = eventMeta( event );
    const camp = event.campaign_id ? campaigns?.[ event.campaign_id ] : null;
    const title = eventTitle( event, camp?.title );

    // What the row is about, so it can be found again: the reference for a
    // donation, the number for a receipt, and who made the change when it was
    // not the donor.
    const facts = [];
    if ( event.reference ) {
        facts.push(
            <a key="ref" className="dp-tl-ref" href={ donationHref( event.reference ) }>{ event.reference }</a>
        );
    }
    if ( event.receipt_number ) {
        facts.push( <span key="rec" className="dp-tl-ref">{ event.receipt_number }</span> );
    }
    if ( event.payload?.by === 'admin' ) {
        facts.push( <span key="by">{ __( 'by an admin', 'dono-fundraising-platform' ) }</span> );
    }

    // A note the donor left with the gift, in the same row as the rest so the
    // container's gap separates it. In its own block it butted against the
    // reference with no space.
    if ( event.note ) {
        facts.push( <span key="note" className="dp-tl-note">“{ event.note }”</span> );
    }

    return (
        <div className="dp-tl-row">
            <TimelineDot variant={ meta.dot } />
            <div className="dp-tl-body">
                <div className="dp-tl-title">{ title }</div>
                { /* .dp-tl-sub is already an inline-flex row with a gap, so the
                     items space themselves; separators would double it. */ }
                { facts.length > 0 && <div className="dp-tl-sub">{ facts }</div> }
            </div>
            <div className="dp-tl-when">
                { timeAgo( event.occurred_at ) }
                <time>{ formatDateTime( event.occurred_at ) }</time>
            </div>
        </div>
    );
}

function RecentDonationsCard( { donations, campaigns, donationsTotal, onAllDonations } ) {
    const top5 = donations.slice( 0, 5 );
    return (
        <div className="dp-card">
            <div className="dp-card__body" style={ { padding: '6px 18px' } }>
                { top5.length === 0
                    ? (
                        <EmptyState
                            compact
                            icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'No donations yet', 'dono-fundraising-platform' ) }
                            body={ __( 'This donor’s donations will appear here as they come in.', 'dono-fundraising-platform' ) }
                        />
                    )
                    : (
                        <div className="dp-recent">
                            { top5.map( ( d ) => {
                                const pill = donationStatusPill( d.status );
                                const camp = d.campaign_id ? campaigns?.[ d.campaign_id ] : null;
                                return (
                                    <div key={ d.id } className="dp-recent-row">
                                        <div className="dp-recent-row__main">
                                            <span className="dp-recent-row__top">
                                                <span className="dp-recent-row__amount num">{ formatAmount( d.amount_cents, d.currency ) }</span>
                                                <span className="dp-recent-row__camp">{ camp ? camp.title : '-' }</span>
                                            </span>
                                            <span className="dp-recent-row__sub">
                                                <a href={ donationHref( d.reference ) }>{ d.reference }</a>
                                                { d.is_test && (
                                                    <span className="dono-pill dono-pill--test">{ __( 'Test', 'dono-fundraising-platform' ) }</span>
                                                ) }
                                                <span>{ timeAgo( d.paid_at || d.created_at ) }</span>
                                            </span>
                                        </div>
                                        <span className={ `dp-pill ${ pill.cls }` }>{ pill.label }</span>
                                    </div>
                                );
                            } ) }
                        </div>
                    ) }
            </div>
            <div className="dp-card__foot">
                { /* Counts the rows this card lists, which include test,
                     pending and failed ones. lifetime.count is live paid
                     donations only, so it disagreed with both the list above it
                     and the tab badge that opens the same list. */ }
                <span className="num">{ sprintf( /* translators: %d: how many donations this donor has */ __( '%d in total', 'dono-fundraising-platform' ), donationsTotal ) }</span>
                <a
                    href="#donations"
                    onClick={ ( e ) => { e.preventDefault(); onAllDonations?.(); } }
                >
                    { __( 'All donations →', 'dono-fundraising-platform' ) }
                </a>
            </div>
        </div>
    );
}

function ActivePlanCard( { plans } ) {
    const active = plans.find( ( p ) => p.status === 'active' || p.status === 'past_due' );
    if ( ! active ) {
        return (
            <div className="dp-card">
                <div className="dp-card__body" style={ { padding: '14px 18px' } }>
                    <p className="dp-empty" style={ { padding: '8px 0' } }>{ __( 'No active recurring plan.', 'dono-fundraising-platform' ) }</p>
                </div>
            </div>
        );
    }
    const pill = active.status === 'past_due'
        ? { cls: 'is-warn', label: __( 'Past due', 'dono-fundraising-platform' ) }
        : { cls: 'is-ok',   label: __( 'Active', 'dono-fundraising-platform' ) };
    return (
        <div className="dp-card">
            <div className="dp-card__body" style={ { padding: '14px 18px' } }>
                <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 12 } }>
                    <strong className="num" style={ { fontSize: 15 } }>
                        { formatAmount( active.amount_cents, active.currency ) } / { active.interval_unit }
                    </strong>
                    <span className={ `dp-pill ${ pill.cls }` }>{ pill.label }</span>
                </div>
                <Row label={ __( 'Next attempt', 'dono-fundraising-platform' ) }       value={ formatDate( active.next_payment_at ) } />
                <Row label={ __( 'Last successful', 'dono-fundraising-platform' ) }    value={ active.last_payment_at ? formatDate( active.last_payment_at ) : '-' } />
                <Row label={ __( 'Lifetime on plan', 'dono-fundraising-platform' ) }   value={ formatAmount( active.total_paid_cents, active.currency ) } strong />
            </div>
        </div>
    );
}

function Row( { label, value, strong = false } ) {
    return (
        <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 8 } }>
            <span style={ { fontSize: 12, color: 'var(--text-muted, #6b7280)' } }>{ label }</span>
            { strong
                ? <strong className="num" style={ { fontSize: 13 } }>{ value }</strong>
                : <span    className="num" style={ { fontSize: 13 } }>{ value }</span> }
        </div>
    );
}

const OVERVIEW_EVENTS = 10;

export default function ActivityTab( { donations, events, eventsTotal, campaigns, recurring, donationsTotal, onAllDonations, onSeeAllActivity } ) {
    // The overview is a preview; the Activity tab holds the full, paged log.
    const recentEvents = events.slice( 0, OVERVIEW_EVENTS );
    return (
        <div className="dp-activity">
            <div className="dp-activity-grid">
                <div className="dp-activity-main">
                    <RecentDonationsCard
                        donations={ donations }
                        campaigns={ campaigns }
                        donationsTotal={ donationsTotal }
                        onAllDonations={ onAllDonations }
                    />

                    <div className="dp-card">
                        <div className="dp-card__body" style={ { padding: '6px 18px 12px' } }>
                            { events.length === 0
                                ? (
                                    <EmptyState
                                        compact
                                        icon={ <History size={ 22 } strokeWidth={ 1.75 } /> }
                                        title={ __( 'No events yet', 'dono-fundraising-platform' ) }
                                        body={ __( 'Status changes, refunds, and admin notes show up here over time.', 'dono-fundraising-platform' ) }
                                    />
                                )
                                : (
                                    <div className="dp-timeline">
                                        { recentEvents.map( ( e ) => (
                                            <TimelineRow key={ e.id } event={ e } campaigns={ campaigns } />
                                        ) ) }
                                    </div>
                                ) }
                        </div>
                        { /* Same footer shape as the donations card above it:
                             the count on the left, the way out on the right. */ }
                        { events.length > 0 && (
                            <div className="dp-card__foot">
                                <span className="num">
                                    { sprintf(
                                        /* translators: %d: total number of recorded events for this donor. */
                                        _n( '%d event', '%d events', eventsTotal, 'dono-fundraising-platform' ),
                                        eventsTotal
                                    ) }
                                </span>
                                { onSeeAllActivity && (
                                    <a
                                        href="#activity"
                                        onClick={ ( e ) => { e.preventDefault(); onSeeAllActivity(); } }
                                    >
                                        { __( 'All activity →', 'dono-fundraising-platform' ) }
                                    </a>
                                ) }
                            </div>
                        ) }
                    </div>
                </div>

                <aside className="dp-activity-side">
                    <ActivePlanCard plans={ recurring?.plans || [] } />
                </aside>
            </div>
        </div>
    );
}
