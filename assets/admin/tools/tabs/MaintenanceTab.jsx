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
    const [ purgeText, setPurgeText ]         = useState( '' );
    const [ purging, setPurging ]             = useState( false );

    const scopes = info?.recalc_scopes?.length
        ? info.recalc_scopes
        : [ { value: 'all', label: __( 'Everything', 'dono-fundraising-platform' ) } ];

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
                    ? __( 'Progress made. There is more to do, run it again.', 'dono-fundraising-platform' )
                    : __( 'Data updates finished.', 'dono-fundraising-platform' ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not finish the data updates.', 'dono-fundraising-platform' ) } );
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
            setNotice( { type: 'success', text: __( 'Aggregates recomputed.', 'dono-fundraising-platform' ) } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Recalculation failed.', 'dono-fundraising-platform' ) } );
        } finally {
            setRecalcRunning( false );
        }
    };

    const doPurgeTestData = async () => {
        setPurging( true );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path:   '/dono/v1/admin/tools/purge-test-data',
                method: 'POST',
                data:   { confirmation: purgeText },
            } );
            setPurgeText( '' );
            setNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: 1: donations removed, 2: recurring plans removed, 3: donors removed */
                    __( 'Removed %1$d test donations, %2$d test recurring plans and %3$d donors left with nothing.', 'dono-fundraising-platform' ),
                    res?.donations || 0,
                    res?.recurring_plans || 0,
                    res?.donors || 0,
                ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not remove the test data.', 'dono-fundraising-platform' ) } );
        } finally {
            setPurging( false );
        }
    };

    const testData  = info?.test_data;
    const testTotal = ( testData?.donations || 0 ) + ( testData?.recurring_plans || 0 );

    return (
        <div className="dono-panel">
            { info?.pending_upgrades?.length > 0 && (
                <Card
                    title={ __( 'Data updates are outstanding', 'dono-fundraising-platform' ) }
                    sub={ __( 'These run by themselves in the background. If they are still here after a few minutes, this site\'s scheduled tasks are not running and you can finish them here.', 'dono-fundraising-platform' ) }
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
                                                'dono-fundraising-platform'
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
                            { upgrading ? __( 'Working…', 'dono-fundraising-platform' ) : __( 'Run them now', 'dono-fundraising-platform' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            { info?.unconverted_donations?.length > 0 && (
                <Card
                    title={ __( 'Donations missing from your totals', 'dono-fundraising-platform' ) }
                    sub={ __( 'A donation is never refused for want of an exchange rate, so these were recorded in their own currency and left out of every total. Add a rate for the currency, then recalculate to bring them in.', 'dono-fundraising-platform' ) }
                >
                    <ul className="dono-advanced-cron">
                        { info.unconverted_donations.map( ( row ) => (
                            <li key={ row.currency }>
                                <strong>{ row.currency }</strong>
                                { ' ' }
                                { sprintf(
                                    /* translators: 1: how many donations, 2: their total in that currency. */
                                    _n( '%1$s donation, %2$s', '%1$s donations, %2$s', row.count, 'dono-fundraising-platform' ),
                                    row.count,
                                    formatAmount( row.amount_cents, row.currency )
                                ) }
                            </li>
                        ) ) }
                    </ul>
                </Card>
            ) }

            <Card
                title={ __( 'Recalculate aggregates', 'dono-fundraising-platform' ) }
                sub={ __( 'Re-derive donor, fund, campaign and form counters from the donation rows. Safe to run any time; donations are only read.', 'dono-fundraising-platform' ) }
            >
                <div className="dono-advanced-actions">
                    <label className="dono-tools-field">
                        { __( 'Scope', 'dono-fundraising-platform' ) }
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
                        { recalcRunning ? __( 'Recalculating…', 'dono-fundraising-platform' ) : __( 'Recalculate', 'dono-fundraising-platform' ) }
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
                                        __( '%1$s: %2$d synced', 'dono-fundraising-platform' ),
                                        k.charAt( 0 ).toUpperCase() + k.slice( 1 ),
                                        n
                                    ) }
                                </li>
                            ) ) }
                    </ul>
                ) }
            </Card>

            { testTotal > 0 && (
                <Card
                    title={ __( 'Test data', 'dono-fundraising-platform' ) }
                    sub={ __( 'Everything a gateway in test mode left behind: donations, the recurring plans set up against them, and donors who would have nothing left on record. Test rows are already left out of every total, so this changes nothing you have reported: it clears the ledger you read by eye before going live. There is no undo.', 'dono-fundraising-platform' ) }
                >
                    <ul className="dono-advanced-cron">
                        { testData.donations > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of test donations */
                                    _n( '%d test donation', '%d test donations', testData.donations, 'dono-fundraising-platform' ),
                                    testData.donations
                                ) }
                            </li>
                        ) }
                        { testData.recurring_plans > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of test recurring plans */
                                    _n( '%d test recurring plan', '%d test recurring plans', testData.recurring_plans, 'dono-fundraising-platform' ),
                                    testData.recurring_plans
                                ) }
                            </li>
                        ) }
                        { testData.donors > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of donors that would be left with no records */
                                    _n(
                                        '%d donor, who would have nothing left on record',
                                        '%d donors, who would have nothing left on record',
                                        testData.donors,
                                        'dono-fundraising-platform'
                                    ),
                                    testData.donors
                                ) }
                            </li>
                        ) }
                    </ul>
                    <div className="dono-advanced-actions" style={ { marginTop: 12 } }>
                        <label className="dono-tools-field">
                            { __( 'Type DELETE to confirm', 'dono-fundraising-platform' ) }
                            <input
                                type="text"
                                className="dono-input"
                                value={ purgeText }
                                onChange={ ( e ) => setPurgeText( e.target.value ) }
                                disabled={ purging }
                            />
                        </label>
                        <Btn
                            variant="danger"
                            onClick={ doPurgeTestData }
                            disabled={ purging || purgeText.trim().toUpperCase() !== 'DELETE' }
                            isBusy={ purging }
                        >
                            { purging ? __( 'Removing…', 'dono-fundraising-platform' ) : __( 'Delete test data', 'dono-fundraising-platform' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            <Card
                title={ __( 'Setup wizard', 'dono-fundraising-platform' ) }
                sub={ __( 'Walks through currency, the first campaign, and a payment gateway. Re-running it changes nothing you have already set unless you complete a step.', 'dono-fundraising-platform' ) }
            >
                <div className="dono-advanced-actions">
                    <Btn variant="secondary" href="admin.php?page=dono-onboarding">
                        { __( 'Open setup wizard', 'dono-fundraising-platform' ) }
                    </Btn>
                </div>
            </Card>
        </div>
    );
}
