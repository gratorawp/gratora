import { __, sprintf } from '@wordpress/i18n';
import { Coins, History } from 'lucide-react';

import EmptyState from '../../../_shared/components/EmptyState';
import { formatAmount, formatDateTime, formatDate, timeAgo, donationStatusPill, eventMeta } from '../helpers';
import {
    IconCheck, IconAlert, IconRotate, IconNote, IconClock, IconRefund, IconFile,
} from '../icons';

function TimelineDot( { variant } ) {
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

function TimelineRow( { event, campaigns } ) {
    const meta = eventMeta( event );
    const camp = event.campaign_id ? campaigns?.[ event.campaign_id ] : null;
    const amount = event.amount_cents !== null && event.amount_cents !== undefined
        ? formatAmount( event.amount_cents, event.currency )
        : null;

    let title = <>{ meta.label }</>;
    let sub   = null;

    if ( event.type === 'donation.paid' && amount ) {
        title = (
            <>
                { __( 'Donated', 'dono' ) } <strong>{ amount }</strong>
                { camp && <> { __( 'to', 'dono' ) } <a href="#">{ camp.title }</a></> }
            </>
        );
    } else if ( event.type === 'recurring_plan.renewed' && amount ) {
        title = (
            <>
                { __( 'Recurring renewal paid', 'dono' ) } <strong>{ amount }</strong>
                { camp && <> { __( 'to', 'dono' ) } <a href="#">{ camp.title }</a></> }
            </>
        );
    } else if ( event.type === 'donation.refunded' && amount ) {
        title = <>{ __( 'Refund of', 'dono' ) } <strong>{ amount }</strong> { __( 'issued', 'dono' ) }</>;
    } else if ( event.type === 'recurring_plan.created' ) {
        title = <>{ __( 'Recurring plan created', 'dono' ) }</>;
    } else if ( event.type === 'recurring_plan.cancelled' ) {
        title = <>{ __( 'Recurring plan cancelled', 'dono' ) }</>;
    } else if ( event.type === 'consent.granted' ) {
        title = <>{ __( 'Marketing consent', 'dono' ) } <strong>{ __( 'granted', 'dono' ) }</strong></>;
    } else if ( event.type === 'consent.revoked' ) {
        title = <>{ __( 'Marketing consent', 'dono' ) } <strong>{ __( 'revoked', 'dono' ) }</strong></>;
    } else if ( event.type === 'donor.created' ) {
        title = <>{ __( 'Donor record created', 'dono' ) }</>;
    }

    return (
        <div className="dp-tl-row">
            <TimelineDot variant={ meta.dot } />
            <div className="dp-tl-body">
                <div className="dp-tl-title">{ title }</div>
                { sub && <div className="dp-tl-sub">{ sub }</div> }
            </div>
            <div className="dp-tl-when">
                { timeAgo( event.occurred_at ) }
                <time>{ formatDateTime( event.occurred_at ) }</time>
            </div>
        </div>
    );
}

function RecentDonationsCard( { donations, campaigns, lifetime, onAllDonations } ) {
    const top5 = donations.slice( 0, 5 );
    return (
        <div className="dp-card">
            <div className="dp-card__body" style={ { padding: '6px 18px' } }>
                { top5.length === 0
                    ? (
                        <EmptyState
                            compact
                            icon={ <Coins size={ 22 } strokeWidth={ 1.75 } /> }
                            title={ __( 'No donations yet', 'dono' ) }
                            body={ __( 'This donor’s donations will appear here as they come in.', 'dono' ) }
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
                                                <a href="#">{ d.reference }</a>
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
                <span className="num">{ sprintf( /* translators: %d: lifetime donation count */ __( '%d lifetime', 'dono' ), lifetime.count ) }</span>
                <a
                    href="#donations"
                    onClick={ ( e ) => { e.preventDefault(); onAllDonations?.(); } }
                >
                    { __( 'All donations →', 'dono' ) }
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
                    <p className="dp-empty" style={ { padding: '8px 0' } }>{ __( 'No active recurring plan.', 'dono' ) }</p>
                </div>
            </div>
        );
    }
    const pill = active.status === 'past_due'
        ? { cls: 'is-warn', label: __( 'Past due', 'dono' ) }
        : { cls: 'is-ok',   label: __( 'Active', 'dono' ) };
    return (
        <div className="dp-card">
            <div className="dp-card__body" style={ { padding: '14px 18px' } }>
                <div style={ { display: 'flex', justifyContent: 'space-between', alignItems: 'baseline', marginBottom: 12 } }>
                    <strong className="num" style={ { fontSize: 15 } }>
                        { formatAmount( active.amount_cents, active.currency ) } / { active.interval_unit }
                    </strong>
                    <span className={ `dp-pill ${ pill.cls }` }>{ pill.label }</span>
                </div>
                <Row label={ __( 'Next attempt', 'dono' ) }       value={ formatDate( active.next_payment_at ) } />
                <Row label={ __( 'Last successful', 'dono' ) }    value={ active.last_payment_at ? formatDate( active.last_payment_at ) : '-' } />
                <Row label={ __( 'Lifetime on plan', 'dono' ) }   value={ formatAmount( active.total_paid_cents, active.currency ) } strong />
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

export default function ActivityTab( { donations, events, campaigns, recurring, lifetime, onAllDonations } ) {
    return (
        <div className="dp-activity">
            <div className="dp-activity-grid">
                <div className="dp-activity-main">
                    <RecentDonationsCard
                        donations={ donations }
                        campaigns={ campaigns }
                        lifetime={ lifetime }
                        onAllDonations={ onAllDonations }
                    />

                    <div className="dp-card">
                        <div className="dp-card__body" style={ { padding: '6px 18px 12px' } }>
                            { events.length === 0
                                ? (
                                    <EmptyState
                                        compact
                                        icon={ <History size={ 22 } strokeWidth={ 1.75 } /> }
                                        title={ __( 'No events yet', 'dono' ) }
                                        body={ __( 'Status changes, refunds, and admin notes show up here over time.', 'dono' ) }
                                    />
                                )
                                : (
                                    <div className="dp-timeline">
                                        { events.map( ( e ) => (
                                            <TimelineRow key={ e.id } event={ e } campaigns={ campaigns } />
                                        ) ) }
                                    </div>
                                ) }
                        </div>
                    </div>
                </div>

                <aside className="dp-activity-side">
                    <ActivePlanCard plans={ recurring?.plans || [] } />
                </aside>
            </div>
        </div>
    );
}
