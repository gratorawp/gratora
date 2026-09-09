import { __, sprintf, _n } from '@wordpress/i18n';
import { RotateCw } from 'lucide-react';

import EmptyState from '../../_shared/components/EmptyState';
import { formatAmount } from '../../_shared/format';

export default function RecurringForecast( { recurring } ) {
    if ( ! recurring || recurring.active_plans === 0 ) {
        return (
            <EmptyState
                compact
                icon={ <RotateCw size={ 22 } strokeWidth={ 1.75 } /> }
                title={ __( 'No recurring plans yet', 'gratora' ) }
                body={ __( 'Enable a recurring frequency on your form to unlock predictable monthly revenue.', 'gratora' ) }
            />
        );
    }

    const { active_plans, mrr_cents, projected_30d_cents, new_this_month, currency } = recurring;

    return (
        <div className="gratora-recurring">
            <div className="gratora-recurring__main">
                <div className="gratora-recurring__mrr">
                    { formatAmount( mrr_cents, currency ) }
                    <span className="gratora-recurring__suffix">{ __( '/ month', 'gratora' ) }</span>
                </div>
                <div className="gratora-recurring__sub">
                    { sprintf(
                        /* translators: %d: count */ _n( '%d active plan', '%d active plans', active_plans, 'gratora' ),
                        active_plans
                    ) }
                    { new_this_month > 0 && (
                        <>
                            <span className="gratora-recurring__dot" aria-hidden="true">·</span>
                            <span className="gratora-recurring__new">
                                { sprintf(
                                    /* translators: %d: count */ _n( '+%d new this month', '+%d new this month', new_this_month, 'gratora' ),
                                    new_this_month
                                ) }
                            </span>
                        </>
                    ) }
                </div>
            </div>

            <div className="gratora-recurring__forecast">
                <div className="gratora-recurring__forecast-label">{ __( 'Projected next 30 days', 'gratora' ) }</div>
                <div className="gratora-recurring__forecast-value">
                    { formatAmount( projected_30d_cents, currency ) }
                </div>
            </div>
        </div>
    );
}
