import { __ } from '@wordpress/i18n';

import MetricCard from '../../_shared/widgets/MetricCard';
import { formatAmount } from '../../_shared/format';
import { IconCoins, IconHeart, IconUsers, IconActivity } from '../../_shared/widgets/icons';
import { RANGE_OPTIONS } from '../../_shared/widgets/SectionBar';

function rangeLabel( range ) {
    const opt = RANGE_OPTIONS.find( ( r ) => r.value === range );
    return opt ? opt.label : '';
}

export default function KpiRow( { kpi, compareOn, range, includesTest = false, loading = false } ) {
    const cmp = compareOn ? ( kpi.comparison?.change_percent ?? null ) : null;
    const currency = kpi.currency || 'USD';
    // The page banner explains the toggle, but these four cards are what gets
    // screenshotted, and a figure that leaves the page loses the caveat with it.
    const testSub  = includesTest ? __( 'incl. test', 'dono-fundraising-platform' ) : '';
    const withTest = ( base ) => [ base, testSub ].filter( Boolean ).join( ' · ' );

    const periodSub = withTest( rangeLabel( range ) );
    const donationsSub = withTest(
        rangeLabel( range )
            ? `${ rangeLabel( range ) } · ${ __( 'paid only', 'dono-fundraising-platform' ) }`
            : __( 'paid only', 'dono-fundraising-platform' )
    );

    return (
        <div className="dono-overview__metrics">
            <MetricCard
                label={ __( 'Amount raised', 'dono-fundraising-platform' ) }
                value={ formatAmount( kpi.amount_raised_cents, currency ) }
                changePct={ cmp?.amount_raised_cents }
                sub={ periodSub }
                icon={ <IconCoins /> }
                skeleton={ loading }
            />
            <MetricCard
                label={ __( 'Donations', 'dono-fundraising-platform' ) }
                value={ String( kpi.donations_count ) }
                changePct={ cmp?.donations_count }
                sub={ donationsSub }
                icon={ <IconHeart /> }
                skeleton={ loading }
            />
            <MetricCard
                label={ __( 'Donors', 'dono-fundraising-platform' ) }
                value={ String( kpi.donors_count ) }
                changePct={ cmp?.donors_count }
                sub={ periodSub }
                icon={ <IconUsers /> }
                skeleton={ loading }
            />
            <MetricCard
                label={ __( 'Average donation', 'dono-fundraising-platform' ) }
                value={ formatAmount( kpi.avg_donation_cents, currency ) }
                changePct={ cmp?.avg_donation_cents }
                sub={ periodSub }
                icon={ <IconActivity /> }
                skeleton={ loading }
            />
        </div>
    );
}
