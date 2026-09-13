import { __, sprintf } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import Dialog from '../_shared/components/Dialog';
import Btn from '../_shared/components/Btn';
import StatusBadge from '../_shared/components/StatusBadge';
import { planStatusMeta } from '../_shared/statuses';
import { formatAmount, formatDate } from '../donations/format';
import { actionsFor } from '../_shared/recurring/PlanActions';
import { cadenceLabel } from '../_shared/recurring/planColumns';

function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page: 'gratora-donations',
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
    const donorHref = addQueryArgs( window.location.pathname, { page: 'gratora-donors' } )
        + `#donor/${ plan.donor?.id }`;

    // Named after the provider, because the dialog title is also a subscription
    // id and the two never match.
    const gatewayName = plan.gateway_label || plan.gateway || '';
    let providerIdLabel = __( 'Provider ID', 'gratora-donation-platform' );
    if ( gatewayName ) {
        /* translators: %s: payment gateway name, e.g. Stripe. */
        providerIdLabel = sprintf( __( '%s ID', 'gratora-donation-platform' ), gatewayName );
    }

    return (
        <Dialog
            /* translators: %d: subscription id */
            title={ sprintf( __( 'Subscription #%d', 'gratora-donation-platform' ), plan.id ) }
            onClose={ onClose }
            size="wide"
            foot={
                <>
                    <Btn className="sd-foot__close" variant="secondary" onClick={ onClose }>
                        { __( 'Close', 'gratora-donation-platform' ) }
                    </Btn>
                    { actionsFor( plan ).map( ( a ) => (
                        <Btn
                            key={ a.id }
                            variant={ a.destructive ? 'danger' : 'secondary' }
                            onClick={ () => onAction( a.id ) }
                        >
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
                        { ' / ' }{ cadenceLabel( plan ) }
                    </span>
                </div>
                <StatusBadge status={ plan.status } { ...planStatusMeta( plan.status ) } />
            </div>

            { plan.donor?.name && (
                <p className="sd-head__donor">
                    <a href={ donorHref }>{ plan.donor.name }</a>
                </p>
            ) }

            { plan.last_failure && (
                <div className="sd-failure">
                    <div className="sd-failure__reason">
                        { plan.last_failure.reason || __( 'The gateway gave no reason.', 'gratora-donation-platform' ) }
                    </div>
                    <a href={ donationHref( plan.last_failure.reference ) }>
                        { plan.last_failure.reference }
                    </a>
                </div>
            ) }

            <Section
                title={ __( 'Schedule', 'gratora-donation-platform' ) }
                rows={ [
                    { label: __( 'Next payment', 'gratora-donation-platform' ), value: plan.next_payment_at && formatDate( plan.next_payment_at ) },
                    { label: __( 'Last payment', 'gratora-donation-platform' ), value: plan.last_payment_at && formatDate( plan.last_payment_at ) },
                    { label: __( 'Started', 'gratora-donation-platform' ), value: plan.started_at && formatDate( plan.started_at ) },
                    { label: __( 'Resumes', 'gratora-donation-platform' ), value: plan.resume_at && formatDate( plan.resume_at ) },
                    { label: __( 'Cancelled', 'gratora-donation-platform' ), value: plan.cancelled_at && formatDate( plan.cancelled_at ) },
                    { label: __( 'Reason', 'gratora-donation-platform' ), value: plan.cancellation_reason },
                ] }
            />

            <Section
                title={ __( 'Giving', 'gratora-donation-platform' ) }
                rows={ [
                    { label: __( 'Payments', 'gratora-donation-platform' ), value: plan.payments_count || null },
                    { label: __( 'Lifetime', 'gratora-donation-platform' ), value: formatAmount( plan.total_paid_cents, plan.currency ) },
                    { label: __( 'Failed renewals', 'gratora-donation-platform' ), value: plan.failed_renewals_count || null },
                ] }
            />

            { plan.errors?.length > 0 && (
                <div className="sd-group">
                    <h4 className="sd-group__title">{ __( 'Problems', 'gratora-donation-platform' ) }</h4>
                    <ul className="sd-errors">
                        { plan.errors.map( ( e, i ) => (
                            <li className="sd-errors__row" key={ `${ e.at }-${ i }` }>
                                <span className="sd-errors__when">{ formatDate( e.at ) }</span>
                                <span className="sd-errors__msg">{ e.message }</span>
                                <span className="sd-errors__src" title={ e.source }>{ e.origin || e.source }</span>
                            </li>
                        ) ) }
                    </ul>
                </div>
            ) }

            <Section
                title={ __( 'Payment provider', 'gratora-donation-platform' ) }
                rows={ [
                    { label: __( 'Gateway', 'gratora-donation-platform' ), value: gatewayName },
                    {
                        label: providerIdLabel,
                        value: plan.gateway_subscription_id
                            ? <code className="sd-mono">{ plan.gateway_subscription_id }</code>
                            : <span className="sd-muted">{ __( 'Not linked', 'gratora-donation-platform' ) }</span>,
                    },
                ] }
            />
        </Dialog>
    );
}
