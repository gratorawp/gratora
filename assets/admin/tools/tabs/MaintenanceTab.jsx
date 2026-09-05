import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatAmount } from '../../_shared/format';
import Card from '../../_shared/components/Card';
import Notice from '../../_shared/components/Notice';
import Btn from '../../_shared/components/Btn';

export default function MaintenanceTab( { info, infoError, active, loadInfo, setNotice } ) {
    const [ recalcScope, setRecalcScope ]     = useState( 'all' );
    const [ recalcRunning, setRecalcRunning ] = useState( false );
    const [ recalcResult, setRecalcResult ]   = useState( null );
    const [ upgrading, setUpgrading ]         = useState( false );
    const [ purgeText, setPurgeText ]         = useState( '' );
    const [ purging, setPurging ]             = useState( false );

    const scopes = info?.recalc_scopes?.length
        ? info.recalc_scopes
        : [ { value: 'all', label: __( 'Everything', 'fundraising-toolkit' ) } ];

    // Tabs are hidden rather than unmounted. Saving a currency on another
    // screen changes which donations are stranded, so refetch on each visit.
    useEffect( () => { if ( active ) loadInfo(); }, [ active, loadInfo ] );

    const doRunUpgrades = async () => {
        setUpgrading( true );
        setNotice( null );
        try {
            const res  = await apiFetch( { path: '/fundkit/v1/admin/tools/run-upgrades', method: 'POST' } );
            const left = res?.remaining?.length || 0;
            setNotice( {
                type: 'success',
                text: left > 0
                    ? __( 'Progress made. There is more to do, run it again.', 'fundraising-toolkit' )
                    : __( 'Data updates finished.', 'fundraising-toolkit' ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not finish the data updates.', 'fundraising-toolkit' ) } );
        } finally {
            setUpgrading( false );
        }
    };

    // The server does as much as fits in one request and says whether it
    // finished, so a big org's rebuild arrives as a series of requests instead
    // of one that times out half way through the donors.
    const doRecalculate = async () => {
        setRecalcRunning( true );
        setRecalcResult( null );
        setNotice( null );
        try {
            let counts = {};
            let done   = false;

            for ( let round = 0; ! done && round < 500; round++ ) {
                const res = await apiFetch( {
                    path:   '/fundkit/v1/admin/tools/recalculate',
                    method: 'POST',
                    data:   { scope: recalcScope },
                } );

                counts = res?.counts || counts;
                done   = res?.done !== false;
                setRecalcResult( counts );

                if ( ! done ) {
                    setNotice( { type: 'info', text: __( 'Still recomputing. Leave this open.', 'fundraising-toolkit' ) } );
                }
            }

            setNotice( done
                ? { type: 'success', text: __( 'Aggregates recomputed.', 'fundraising-toolkit' ) }
                : { type: 'warning', text: __( 'Progress made. There is more to do, run it again.', 'fundraising-toolkit' ) } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Recalculation failed.', 'fundraising-toolkit' ) } );
        } finally {
            setRecalcRunning( false );
        }
    };

    const doPurgeTestData = async () => {
        setPurging( true );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path:   '/fundkit/v1/admin/tools/purge-test-data',
                method: 'POST',
                data:   { confirmation: purgeText },
            } );
            setPurgeText( '' );
            setNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: 1: donations removed, 2: recurring plans removed, 3: donors removed */
                    __( 'Removed %1$d test donations, %2$d test recurring plans and %3$d donors left with nothing.', 'fundraising-toolkit' ),
                    res?.donations || 0,
                    res?.recurring_plans || 0,
                    res?.donors || 0,
                ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not remove the test data.', 'fundraising-toolkit' ) } );
        } finally {
            setPurging( false );
        }
    };

    const testData  = info?.test_data;
    const testTotal = ( testData?.donations || 0 ) + ( testData?.recurring_plans || 0 );

    return (
        <div className="fundkit-panel">
            { infoError && (
                <Card
                    title={ __( 'Could not check this site', 'fundraising-toolkit' ) }
                    sub={ __( 'The checks behind this screen did not run, so it cannot say whether data updates are outstanding, whether completed donations are missing from your totals, or whether test data is still here. Nothing below is a clean bill of health until it does.', 'fundraising-toolkit' ) }
                >
                    <Btn variant="secondary" onClick={ loadInfo }>
                        { __( 'Check again', 'fundraising-toolkit' ) }
                    </Btn>
                </Card>
            ) }

            { info?.pending_upgrades?.length > 0 && (
                <Card
                    title={ __( 'Data updates are outstanding', 'fundraising-toolkit' ) }
                    sub={ __( 'These run by themselves in the background. If they are still here after a few minutes, this site\'s scheduled tasks are not running and you can finish them here.', 'fundraising-toolkit' ) }
                >
                    <ul className="fundkit-advanced-cron">
                        { info.pending_upgrades.map( ( u ) => (
                            <li key={ u.id }>
                                { u.description }
                                { u.failure && (
                                    <Notice status="error" compact>
                                        { sprintf(
                                            /* translators: 1: error message, 2: number of attempts */
                                            _n(
                                                'Stopped with: %1$s (failed %2$d time)',
                                                'Stopped with: %1$s (failed %2$d times)',
                                                u.failure.attempts,
                                                'fundraising-toolkit'
                                            ),
                                            u.failure.message,
                                            u.failure.attempts
                                        ) }
                                    </Notice>
                                ) }
                            </li>
                        ) ) }
                    </ul>
                    <div className="fundkit-advanced-actions" style={ { marginTop: 12 } }>
                        <Btn variant="primary" onClick={ doRunUpgrades } disabled={ upgrading } isBusy={ upgrading }>
                            { upgrading ? __( 'Working…', 'fundraising-toolkit' ) : __( 'Run them now', 'fundraising-toolkit' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            { info?.unconverted_donations?.length > 0 && (
                <Card
                    title={ __( 'Donations missing from your totals', 'fundraising-toolkit' ) }
                    sub={
                        info.unconverted_donations.some( ( row ) => row.needs_rate )
                            ? __( 'A donation is never refused for want of an exchange rate, so these completed donations were recorded in their own currency and left out of every total. Add a rate for the currency on Settings > Currency, then recalculate to bring them in.', 'fundraising-toolkit' )
                            : __( 'These completed donations were recorded without a value in your base currency, so every total leaves them out. Recalculate to bring them in; no exchange rate is needed.', 'fundraising-toolkit' )
                    }
                >
                    <ul className="fundkit-advanced-cron">
                        { info.unconverted_donations.map( ( row ) => (
                            <li key={ row.currency }>
                                <strong>{ row.currency }</strong>
                                { ' ' }
                                { sprintf(
                                    /* translators: 1: how many donations, 2: their total in that currency. */
                                    _n( '%1$s donation, %2$s', '%1$s donations, %2$s', row.count, 'fundraising-toolkit' ),
                                    row.count,
                                    formatAmount( row.amount_cents, row.currency )
                                ) }
                                { ! row.needs_rate && (
                                    <>
                                        { ' ' }
                                        <em>{ __( '(your base currency: recalculate is all this needs)', 'fundraising-toolkit' ) }</em>
                                    </>
                                ) }
                            </li>
                        ) ) }
                    </ul>
                </Card>
            ) }

            <Card
                title={ __( 'Recalculate aggregates', 'fundraising-toolkit' ) }
                sub={ __( 'Re-derive donor, fund, campaign and form counters from the donation rows. Safe to run any time; donations are only read.', 'fundraising-toolkit' ) }
            >
                <div className="fundkit-advanced-actions">
                    <label className="fundkit-tools-field">
                        { __( 'Scope', 'fundraising-toolkit' ) }
                        <select
                            className="fundkit-select"
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
                        { recalcRunning ? __( 'Recalculating…', 'fundraising-toolkit' ) : __( 'Recalculate', 'fundraising-toolkit' ) }
                    </Btn>
                </div>
                { recalcResult && (
                    <ul className="fundkit-advanced-cron" style={ { marginTop: 12 } }>
                        { Object.entries( recalcResult )
                            .filter( ( [ , n ] ) => n > 0 )
                            .map( ( [ k, n ] ) => (
                                <li key={ k }>
                                    { sprintf(
                                        /* translators: 1: scope label (Donors, Funds, ...), 2: count */
                                        __( '%1$s: %2$d synced', 'fundraising-toolkit' ),
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
                    title={ __( 'Test data', 'fundraising-toolkit' ) }
                    sub={ __( 'Everything a gateway in test mode left behind: donations, the recurring plans set up against them, and donors who would have nothing left on record. Test rows are left out of your reported totals unless you ask to see them, so this changes nothing you have quoted: it clears the ledger you read by eye before going live. There is no undo.', 'fundraising-toolkit' ) }
                >
                    <ul className="fundkit-advanced-cron">
                        { testData.donations > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of test donations */
                                    _n( '%d test donation', '%d test donations', testData.donations, 'fundraising-toolkit' ),
                                    testData.donations
                                ) }
                            </li>
                        ) }
                        { testData.recurring_plans > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of test recurring plans */
                                    _n( '%d test recurring plan', '%d test recurring plans', testData.recurring_plans, 'fundraising-toolkit' ),
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
                                        'fundraising-toolkit'
                                    ),
                                    testData.donors
                                ) }
                            </li>
                        ) }
                    </ul>
                    <div className="fundkit-advanced-actions" style={ { marginTop: 12 } }>
                        <label className="fundkit-tools-field">
                            { __( 'Type DELETE to confirm', 'fundraising-toolkit' ) }
                            <input
                                type="text"
                                className="fundkit-input"
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
                            { purging ? __( 'Removing…', 'fundraising-toolkit' ) : __( 'Delete test data', 'fundraising-toolkit' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            <Card
                title={ __( 'Setup wizard', 'fundraising-toolkit' ) }
                sub={ __( 'Walks through currency, the first campaign, and a payment gateway. Re-running it changes nothing you have already set unless you complete a step.', 'fundraising-toolkit' ) }
            >
                <div className="fundkit-advanced-actions">
                    <Btn variant="secondary" href="admin.php?page=fundkit-onboarding">
                        { __( 'Open setup wizard', 'fundraising-toolkit' ) }
                    </Btn>
                </div>
            </Card>
        </div>
    );
}
