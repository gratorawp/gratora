import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatAmount } from '@dono/ui/utils/format';
import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

export default function MaintenanceTab( { info, active, loadInfo, setNotice } ) {
    const [ recalcScope, setRecalcScope ]     = useState( 'all' );
    const [ recalcRunning, setRecalcRunning ] = useState( false );
    const [ recalcResult, setRecalcResult ]   = useState( null );
    const [ upgrading, setUpgrading ]         = useState( false );

    const scopes = info?.recalc_scopes?.length
        ? info.recalc_scopes
        : [ { value: 'all', label: __( 'Everything', 'dono' ) } ];

    // Tabs are hidden rather than unmounted. Saving a currency on another
    // screen changes which donations are stranded, so refetch on each visit.
    useEffect( () => { if ( active ) loadInfo(); }, [ active, loadInfo ] );

    const doRunUpgrades = async () => {
        setUpgrading( true );
        setNotice( null );
        try {
            const res  = await apiFetch( { path: '/dono/v1/admin/tools/run-upgrades', method: 'POST' } );
            const left = res?.remaining?.length || 0;
            setNotice( {
                type: 'success',
                text: left > 0
                    ? __( 'Progress made. There is more to do, run it again.', 'dono' )
                    : __( 'Data updates finished.', 'dono' ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not finish the data updates.', 'dono' ) } );
        } finally {
            setUpgrading( false );
        }
    };

    const doRecalculate = async () => {
        setRecalcRunning( true );
        setRecalcResult( null );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path:   '/dono/v1/admin/tools/recalculate',
                method: 'POST',
                data:   { scope: recalcScope },
            } );
            setRecalcResult( res?.counts || {} );
            setNotice( { type: 'success', text: __( 'Aggregates recomputed.', 'dono' ) } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Recalculation failed.', 'dono' ) } );
        } finally {
            setRecalcRunning( false );
        }
    };

    return (
        <div className="dono-panel">
            { info?.pending_upgrades?.length > 0 && (
                <Card
                    title={ __( 'Data updates are outstanding', 'dono' ) }
                    sub={ __( 'These run by themselves in the background. If they are still here after a few minutes, this site\'s scheduled tasks are not running and you can finish them here.', 'dono' ) }
                >
                    <ul className="dono-advanced-cron">
                        { info.pending_upgrades.map( ( u ) => (
                            <li key={ u.id }>
                                { u.description }
                                { u.failure && (
                                    <div className="dono-advanced-notice dono-advanced-notice--error" style={ { marginTop: 6 } }>
                                        { sprintf(
                                            /* translators: 1: error message, 2: number of attempts */
                                            _n(
                                                'Stopped with: %1$s (failed %2$d time)',
                                                'Stopped with: %1$s (failed %2$d times)',
                                                u.failure.attempts,
                                                'dono'
                                            ),
                                            u.failure.message,
                                            u.failure.attempts
                                        ) }
                                    </div>
                                ) }
                            </li>
                        ) ) }
                    </ul>
                    <div className="dono-advanced-actions" style={ { marginTop: 12 } }>
                        <Btn variant="primary" onClick={ doRunUpgrades } disabled={ upgrading } isBusy={ upgrading }>
                            { upgrading ? __( 'Working…', 'dono' ) : __( 'Run them now', 'dono' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            { info?.unconverted_donations?.length > 0 && (
                <Card
                    title={ __( 'Donations missing from your totals', 'dono' ) }
                    sub={ __( 'A donation is never refused for want of an exchange rate, so these were recorded in their own currency and left out of every total. Add a rate for the currency, then recalculate to bring them in.', 'dono' ) }
                >
                    <ul className="dono-advanced-cron">
                        { info.unconverted_donations.map( ( row ) => (
                            <li key={ row.currency }>
                                <strong>{ row.currency }</strong>
                                { ' ' }
                                { sprintf(
                                    /* translators: 1: how many donations, 2: their total in that currency. */
                                    _n( '%1$s donation, %2$s', '%1$s donations, %2$s', row.count, 'dono' ),
                                    row.count,
                                    formatAmount( row.amount_cents, row.currency )
                                ) }
                            </li>
                        ) ) }
                    </ul>
                </Card>
            ) }

            <Card
                title={ __( 'Recalculate aggregates', 'dono' ) }
                sub={ __( 'Re-derive donor, fund, campaign and form counters from the donation rows. Safe to run any time; donations are only read.', 'dono' ) }
            >
                <div className="dono-advanced-actions">
                    <label className="dono-tools-field">
                        { __( 'Scope', 'dono' ) }
                        <select
                            className="dono-select"
                            value={ recalcScope }
                            onChange={ ( e ) => setRecalcScope( e.target.value ) }
                            disabled={ recalcRunning }
                        >
                            { scopes.map( ( sc ) => (
                                <option key={ sc.value } value={ sc.value }>{ sc.label }</option>
                            ) ) }
                        </select>
                    </label>
                    <Btn variant="primary" onClick={ doRecalculate } disabled={ recalcRunning } isBusy={ recalcRunning }>
                        { recalcRunning ? __( 'Recalculating…', 'dono' ) : __( 'Recalculate', 'dono' ) }
                    </Btn>
                </div>
                { recalcResult && (
                    <ul className="dono-advanced-cron" style={ { marginTop: 12 } }>
                        { Object.entries( recalcResult )
                            .filter( ( [ , n ] ) => n > 0 )
                            .map( ( [ k, n ] ) => (
                                <li key={ k }>
                                    { sprintf(
                                        /* translators: 1: scope label (Donors, Funds, ...), 2: count */
                                        __( '%1$s: %2$d synced', 'dono' ),
                                        k.charAt( 0 ).toUpperCase() + k.slice( 1 ),
                                        n
                                    ) }
                                </li>
                            ) ) }
                    </ul>
                ) }
            </Card>

            <Card
                title={ __( 'Setup wizard', 'dono' ) }
                sub={ __( 'Walks through currency, the first campaign, and a payment gateway. Re-running it changes nothing you have already set unless you complete a step.', 'dono' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn variant="secondary" href="admin.php?page=dono-onboarding">
                        { __( 'Open setup wizard', 'dono' ) }
                    </Btn>
                </div>
            </Card>
        </div>
    );
}
