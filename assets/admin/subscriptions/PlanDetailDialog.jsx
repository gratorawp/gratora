import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import Dialog from '../_shared/components/Dialog';
import Btn from '../_shared/components/Btn';
import StatusBadge from '../_shared/components/StatusBadge';
import { formatAmount, formatDate } from '../donations/format';
import { actionsFor } from '../_shared/recurring/PlanActions';
import { intervalLabel } from './List';

// The donation screen owns the failed renewal, including its retry.
function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page: 'fundkit-donations',
        view: 'detail',
        reference,
    } );
}

/** Empty rows are dropped, so a section never renders a column of dashes. */
function Section( { title, rows } ) {
    const present = rows.filter( ( r ) => r.value !== null && r.value !== undefined && r.value !== '' && r.value !== false );
    if ( ! present.length ) return null;

    return (
        <div className="sd-group">
            <h4 className="sd-group__title">{ title }</h4>
            <dl className="sd-kv">
                { present.map( ( r ) => (
                    <div className="sd-kv__row" key={ r.label }>
                        <dt className="sd-kv__lbl">{ r.label }</dt>
                        <dd className="sd-kv__val">{ r.value }</dd>
                    </div>
                ) ) }
            </dl>
        </div>
    );
}

export default function PlanDetailDialog( { plan, onClose, onAction } ) {
    const donorHref = addQueryArgs( window.location.pathname, { page: 'fundkit-donors' } )
        + `#donor/${ plan.donor?.id }`;

    // Named after the provider, because the dialog title is also a subscription
    // id and the two never match.
    const gatewayName = plan.gateway ? plan.gateway.charAt( 0 ).toUpperCase() + plan.gateway.slice( 1 ) : '';
    let providerIdLabel = __( 'Provider ID', 'fundraising-toolkit' );
    if ( gatewayName ) {
        /* translators: %s: payment gateway name, e.g. Stripe. */
        providerIdLabel = sprintf( __( '%s ID', 'fundraising-toolkit' ), gatewayName );
    }

    return (
        <Dialog
            /* translators: %d: subscription id */
            title={ sprintf( __( 'Subscription %d', 'fundraising-toolkit' ), plan.id ) }
            onClose={ onClose }
            foot={
                <>
                    <Btn variant="secondary" onClick={ onClose }>{ __( 'Close', 'fundraising-toolkit' ) }</Btn>
                    { actionsFor( plan ).map( ( a ) => (
                        <Btn key={ a.id } variant="secondary" onClick={ () => onAction( a.id ) }>
                            { a.label }
                        </Btn>
                    ) ) }
                </>
            }
        >
            <div className="sd-head">
                <div className="sd-head__amount">
                    { formatAmount( plan.amount_cents, plan.currency ) }
                    <span className="sd-head__interval">
                        { ' / ' }{ intervalLabel( plan.interval_unit, plan.interval_count ) }
                    </span>
                </div>
                <StatusBadge status={ plan.status } />
            </div>

            { plan.donor?.name && (
                <p className="sd-head__donor">
                    <a href={ donorHref }>{ plan.donor.name }</a>
                </p>
            ) }

            { plan.last_failure && (
                <div className="sd-failure">
                    <div className="sd-failure__reason">
                        { plan.last_failure.reason || __( 'The gateway gave no reason.', 'fundraising-toolkit' ) }
                    </div>
                    <a href={ donationHref( plan.last_failure.reference ) }>
                        { plan.last_failure.reference }
                    </a>
                </div>
            ) }

            <Section
                title={ __( 'Schedule', 'fundraising-toolkit' ) }
                rows={ [
                    { label: __( 'Next payment', 'fundraising-toolkit' ), value: plan.next_payment_at && formatDate( plan.next_payment_at ) },
                    { label: __( 'Last payment', 'fundraising-toolkit' ), value: plan.last_payment_at && formatDate( plan.last_payment_at ) },
                    { label: __( 'Started', 'fundraising-toolkit' ), value: plan.started_at && formatDate( plan.started_at ) },
                    { label: __( 'Resumes', 'fundraising-toolkit' ), value: plan.resume_at && formatDate( plan.resume_at ) },
                    { label: __( 'Cancelled', 'fundraising-toolkit' ), value: plan.cancelled_at && formatDate( plan.cancelled_at ) },
                ] }
            />

            <Section
                title={ __( 'Giving', 'fundraising-toolkit' ) }
                rows={ [
                    { label: __( 'Payments', 'fundraising-toolkit' ), value: plan.payments_count || null },
                    { label: __( 'Lifetime', 'fundraising-toolkit' ), value: formatAmount( plan.total_paid_cents, plan.currency ) },
                    { label: __( 'Failed renewals', 'fundraising-toolkit' ), value: plan.failed_renewals_count || null },
                ] }
            />

            { plan.errors?.length > 0 && (
                <div className="sd-group">
                    <h4 className="sd-group__title">{ __( 'Problems', 'fundraising-toolkit' ) }</h4>
                    <ul className="sd-errors">
                        { plan.errors.map( ( e, i ) => (
                            <li className="sd-errors__row" key={ `${ e.at }-${ i }` }>
                                <span className="sd-errors__when">{ formatDate( e.at ) }</span>
                                <span className="sd-errors__msg">{ e.message }</span>
                                <span className="sd-errors__src">{ e.source }</span>
                            </li>
                        ) ) }
                    </ul>
                </div>
            ) }

            <Section
                title={ __( 'Payment provider', 'fundraising-toolkit' ) }
                rows={ [
                    { label: __( 'Gateway', 'fundraising-toolkit' ), value: <span className="sd-cap">{ plan.gateway }</span> },
                    {
                        label: providerIdLabel,
                        value: plan.gateway_subscription_id
                            ? <code className="sd-mono">{ plan.gateway_subscription_id }</code>
                            : <span className="sd-muted">{ __( 'Not linked', 'fundraising-toolkit' ) }</span>,
                    },
                ] }
            />
        </Dialog>
    );
}
