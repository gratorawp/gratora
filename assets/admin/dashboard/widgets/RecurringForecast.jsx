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
                title={ __( 'No recurring plans yet', 'fundraising-toolkit' ) }
                body={ __( 'Enable a recurring frequency on your form to unlock predictable monthly revenue.', 'fundraising-toolkit' ) }
            />
        );
    }

    const { active_plans, mrr_cents, projected_30d_cents, new_this_month, currency } = recurring;

    return (
        <div className="fundkit-recurring">
            <div className="fundkit-recurring__main">
                <div className="fundkit-recurring__mrr">
                    { formatAmount( mrr_cents, currency ) }
                    <span className="fundkit-recurring__suffix">{ __( '/ month', 'fundraising-toolkit' ) }</span>
                </div>
                <div className="fundkit-recurring__sub">
                    { sprintf(
                        /* translators: %d: count */ _n( '%d active plan', '%d active plans', active_plans, 'fundraising-toolkit' ),
                        active_plans
                    ) }
                    { new_this_month > 0 && (
                        <>
                            <span className="fundkit-recurring__dot" aria-hidden="true">·</span>
                            <span className="fundkit-recurring__new">
                                { sprintf(
                                    /* translators: %d: count */ _n( '+%d new this month', '+%d new this month', new_this_month, 'fundraising-toolkit' ),
                                    new_this_month
                                ) }
                            </span>
                        </>
                    ) }
                </div>
            </div>

            <div className="fundkit-recurring__forecast">
                <div className="fundkit-recurring__forecast-label">{ __( 'Projected next 30 days', 'fundraising-toolkit' ) }</div>
                <div className="fundkit-recurring__forecast-value">
                    { formatAmount( projected_30d_cents, currency ) }
                </div>
            </div>
        </div>
    );
}
