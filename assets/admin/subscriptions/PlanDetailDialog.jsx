import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import Dialog from '../_shared/components/Dialog';
import Btn from '../_shared/components/Btn';
import StatusBadge from '../_shared/components/StatusBadge';
import { formatAmount, formatDate } from '../donations/format';
import { actionsFor } from '../_shared/recurring/PlanActions';

// The donation screen owns the failed renewal, including its retry.
function donationHref( reference ) {
    return addQueryArgs( window.location.pathname, {
        page: 'fundkit-donations',
        view: 'detail',
        reference,
    } );
}

function Row( { label, children } ) {
    if ( children === null || children === undefined || children === '' ) return null;

    return (
        <div className="dd-kv">
            <div className="dd-kv__key">{ label }</div>
            <div className="dd-kv__val">{ children }</div>
        </div>
    );
}

/**
 * Everything held about one plan, and the actions that change it.
 *
 * The donor profile still owns the plan's history; this is the same record
 * without leaving the list somebody was working through.
 */
export default function PlanDetailDialog( { plan, onClose, onAction } ) {
    const donorHref = addQueryArgs( window.location.pathname, { page: 'fundkit-donors' } )
        + `#donor/${ plan.donor?.id }`;

    return (
        <Dialog
            title={ plan.reference }
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
            <Row label={ __( 'Donor', 'fundraising-toolkit' ) }>
                <a href={ donorHref }>{ plan.donor?.name || __( 'Unknown', 'fundraising-toolkit' ) }</a>
            </Row>
            <Row label={ __( 'Amount', 'fundraising-toolkit' ) }>
                { formatAmount( plan.amount_cents, plan.currency ) }
            </Row>
            <Row label={ __( 'Status', 'fundraising-toolkit' ) }>
                <StatusBadge status={ plan.status } />
            </Row>
            <Row label={ __( 'Gateway', 'fundraising-toolkit' ) }>
                <span style={ { textTransform: 'capitalize' } }>{ plan.gateway }</span>
            </Row>
            <Row label={ __( 'Subscription ID', 'fundraising-toolkit' ) }>
                { plan.gateway_subscription_id
                    ? <code className="mono">{ plan.gateway_subscription_id }</code>
                    : __( 'Not linked', 'fundraising-toolkit' ) }
            </Row>
            <Row label={ __( 'Started', 'fundraising-toolkit' ) }>
                { plan.started_at ? formatDate( plan.started_at ) : null }
            </Row>
            <Row label={ __( 'Next payment', 'fundraising-toolkit' ) }>
                { plan.next_payment_at ? formatDate( plan.next_payment_at ) : null }
            </Row>
            <Row label={ __( 'Last payment', 'fundraising-toolkit' ) }>
                { plan.last_payment_at ? formatDate( plan.last_payment_at ) : null }
            </Row>
            <Row label={ __( 'Resumes', 'fundraising-toolkit' ) }>
                { plan.resume_at ? formatDate( plan.resume_at ) : null }
            </Row>
            <Row label={ __( 'Cancelled', 'fundraising-toolkit' ) }>
                { plan.cancelled_at ? formatDate( plan.cancelled_at ) : null }
            </Row>
            <Row label={ __( 'Payments', 'fundraising-toolkit' ) }>
                { plan.payments_count }
            </Row>
            <Row label={ __( 'Lifetime', 'fundraising-toolkit' ) }>
                { formatAmount( plan.total_paid_cents, plan.currency ) }
            </Row>
            <Row label={ __( 'Failed renewals', 'fundraising-toolkit' ) }>
                { plan.failed_renewals_count > 0 ? plan.failed_renewals_count : null }
            </Row>
            <Row label={ __( 'Why it failed', 'fundraising-toolkit' ) }>
                { plan.last_failure && (
                    <>
                        <div>{ plan.last_failure.reason || __( 'No reason was recorded.', 'fundraising-toolkit' ) }</div>
                        <a href={ donationHref( plan.last_failure.reference ) }>
                            { plan.last_failure.reference }
                        </a>
                    </>
                ) }
            </Row>
        </Dialog>
    );
}
