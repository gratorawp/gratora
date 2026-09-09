import { useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, _n, sprintf } from '@wordpress/i18n';

import { formatAmount } from '../../_shared/format';
import Card from '../../_shared/components/Card';
import Notice from '../../_shared/components/Notice';
import Btn from '../../_shared/components/Btn';
import { userCan } from '../../_shared/caps';

const COUNT_LABELS = {
    donors:              __( 'Donors', 'gratora' ),
    funds:               __( 'Funds', 'gratora' ),
    campaigns:           __( 'Campaigns', 'gratora' ),
    forms:               __( 'Forms', 'gratora' ),
    converted_donations: __( 'Donations given a value in your base currency', 'gratora' ),
    converted_plans:     __( 'Recurring plans given a value in your base currency', 'gratora' ),
};

// Typed verbatim, so it is a placeholder rather than a word a translator can
// change out from under the check that reads it back.
const CONFIRM_WORD = 'DELETE';

// An add-on can contribute a count through gratora.recalculate.addons, and a
// raw key is not a sentence.
const countLabel = ( key ) => COUNT_LABELS[ key ]
    || ( key.charAt( 0 ).toUpperCase() + key.slice( 1 ) ).replace( /_/g, ' ' );

export default function MaintenanceTab( { info, infoError, active, loadInfo, setNotice } ) {
    const [ recalcScope, setRecalcScope ]     = useState( 'all' );
    const [ recalcRunning, setRecalcRunning ] = useState( false );
    const [ recalcResult, setRecalcResult ]   = useState( null );
    const [ upgrading, setUpgrading ]         = useState( false );
    const [ purgeText, setPurgeText ]         = useState( '' );
    const [ purging, setPurging ]             = useState( false );

    const scopes = info?.recalc_scopes?.length
        ? info.recalc_scopes
        : [ { value: 'all', label: __( 'Everything', 'gratora' ) } ];

    // Tabs are hidden rather than unmounted. Saving a currency on another
    // screen changes which donations are stranded, so refetch on each visit.
    useEffect( () => { if ( active ) loadInfo(); }, [ active, loadInfo ] );

    const doRunUpgrades = async () => {
        setUpgrading( true );
        setNotice( null );
        try {
            const res  = await apiFetch( { path: '/gratora/v1/admin/tools/run-upgrades', method: 'POST' } );
            const left = res?.remaining?.length || 0;
            setNotice( {
                type: 'success',
                text: left > 0
                    ? __( 'Progress made. There is more to do, run it again.', 'gratora' )
                    : __( 'Data updates finished.', 'gratora' ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not finish the data updates.', 'gratora' ) } );
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
                    path:   '/gratora/v1/admin/tools/recalculate',
                    method: 'POST',
                    data:   { scope: recalcScope },
                } );

                counts = res?.counts || counts;
                done   = res?.done !== false;
                setRecalcResult( {
                    counts,
                    stillUnconvertible: Number( res?.still_unconvertible ) || 0,
                    currencies:         Array.isArray( res?.unconvertible_currencies ) ? res.unconvertible_currencies : [],
                } );

                if ( ! done ) {
                    setNotice( { type: 'info', text: __( 'Still recomputing. Leave this open.', 'gratora' ) } );
                }
            }

            setNotice( done
                ? { type: 'success', text: __( 'Aggregates recomputed.', 'gratora' ) }
                : { type: 'warning', text: __( 'Progress made. There is more to do, run it again.', 'gratora' ) } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Recalculation failed.', 'gratora' ) } );
        } finally {
            setRecalcRunning( false );
        }
    };

    const doPurgeTestData = async () => {
        setPurging( true );
        setNotice( null );
        try {
            const res = await apiFetch( {
                path:   '/gratora/v1/admin/tools/purge-test-data',
                method: 'POST',
                data:   { confirmation: purgeText },
            } );
            setPurgeText( '' );
            setNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: 1: donations removed, 2: recurring plans removed, 3: donors removed */
                    __( 'Removed %1$d test donations, %2$d test recurring plans and %3$d donors left with nothing.', 'gratora' ),
                    res?.donations || 0,
                    res?.recurring_plans || 0,
                    res?.donors || 0,
                ),
            } );
            loadInfo();
        } catch ( err ) {
            setNotice( { type: 'error', text: err?.message || __( 'Could not remove the test data.', 'gratora' ) } );
        } finally {
            setPurging( false );
        }
    };

    const testData  = info?.test_data;
    const testTotal = ( testData?.donations || 0 ) + ( testData?.recurring_plans || 0 );

    return (
        <div className="gratora-panel">
            { infoError && (
                <Card
                    title={ __( 'Could not check this site', 'gratora' ) }
                    sub={ __( 'The checks behind this screen did not run, so it cannot say whether data updates are outstanding, whether completed donations are missing from your totals, or whether test data is still here. Nothing below is a clean bill of health until it does.', 'gratora' ) }
                >
                    <Btn variant="secondary" onClick={ loadInfo }>
                        { __( 'Check again', 'gratora' ) }
                    </Btn>
                </Card>
            ) }

            { userCan( 'manage_options' ) && info?.pending_upgrades?.length > 0 && (
                <Card
                    title={ __( 'Data updates are outstanding', 'gratora' ) }
                    sub={ __( 'These run by themselves in the background. If they are still here after a few minutes, this site\'s scheduled tasks are not running and you can finish them here.', 'gratora' ) }
                >
                    <ul className="gratora-advanced-cron">
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
                                                'gratora'
                                            ),
                                            u.failure.message,
                                            u.failure.attempts
                                        ) }
                                    </Notice>
                                ) }
                            </li>
                        ) ) }
                    </ul>
                    <div className="gratora-advanced-actions" style={ { marginTop: 12 } }>
                        <Btn variant="primary" onClick={ doRunUpgrades } disabled={ upgrading } isBusy={ upgrading }>
                            { upgrading ? __( 'Working…', 'gratora' ) : __( 'Run them now', 'gratora' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            { info?.unconverted_donations?.length > 0 && (
                <Card
                    title={ __( 'Donations missing from your totals', 'gratora' ) }
                    sub={
                        info.unconverted_donations.some( ( row ) => row.needs_rate )
                            ? __( 'A donation is never refused for want of an exchange rate, so these completed donations were recorded in their own currency and left out of every total. Add a rate for the currency on Settings > Currency, then recalculate to bring them in.', 'gratora' )
                            : __( 'These completed donations were recorded without a value in your base currency, so every total leaves them out. Recalculate to bring them in; no exchange rate is needed.', 'gratora' )
                    }
                >
                    <ul className="gratora-advanced-cron">
                        { info.unconverted_donations.map( ( row ) => (
                            <li key={ row.currency }>
                                <strong>{ row.currency }</strong>
                                { ' ' }
                                { sprintf(
                                    /* translators: 1: how many donations, 2: their total in that currency. */
                                    _n( '%1$s donation, %2$s', '%1$s donations, %2$s', row.count, 'gratora' ),
                                    row.count,
                                    formatAmount( row.amount_cents, row.currency )
                                ) }
                                { ! row.needs_rate && (
                                    <>
                                        { ' ' }
                                        <em>{ __( '(your base currency: recalculate is all this needs)', 'gratora' ) }</em>
                                    </>
                                ) }
                            </li>
                        ) ) }
                    </ul>
                </Card>
            ) }

            <Card
                title={ __( 'Recalculate aggregates', 'gratora' ) }
                sub={ __( 'Re-derive donor, fund, campaign and form counters from the donation rows. Safe to run any time; donations are only read.', 'gratora' ) }
            >
                <div className="gratora-advanced-actions">
                    <label className="gratora-tools-field">
                        { __( 'Scope', 'gratora' ) }
                        <select
                            className="gratora-select"
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
                        { recalcRunning ? __( 'Recalculating…', 'gratora' ) : __( 'Recalculate', 'gratora' ) }
                    </Btn>
                </div>
                { recalcResult && (
                    <ul className="gratora-advanced-cron" style={ { marginTop: 12 } }>
                        { Object.entries( recalcResult.counts || {} )
                            .filter( ( [ , n ] ) => n > 0 )
                            .map( ( [ k, n ] ) => (
                                <li key={ k }>
                                    { sprintf(
                                        /* translators: 1: what was recomputed (Donors, Funds, ...), 2: how many */
                                        __( '%1$s: %2$d synced', 'gratora' ),
                                        countLabel( k ),
                                        n
                                    ) }
                                </li>
                            ) ) }
                    </ul>
                ) }

                { recalcResult?.stillUnconvertible > 0 && (
                    <Notice status="warning" isDismissible={ false }>
                        { sprintf(
                            /* translators: 1: how many donations, 2: comma-separated currency codes */
                            _n(
                                '%1$d donation is still missing from your totals: there is no exchange rate for %2$s.',
                                '%1$d donations are still missing from your totals: there is no exchange rate for %2$s.',
                                recalcResult.stillUnconvertible,
                                'gratora'
                            ),
                            recalcResult.stillUnconvertible,
                            ( recalcResult.currencies || [] ).join( ', ' )
                        ) }
                    </Notice>
                ) }
            </Card>

            { userCan( 'manage_options' ) && testTotal > 0 && (
                <Card
                    title={ __( 'Test data', 'gratora' ) }
                    sub={ __( 'Everything a gateway in test mode left behind: donations, the recurring plans set up against them, and donors who would have nothing left on record. Test rows are left out of your reported totals unless you ask to see them, so this changes nothing you have quoted: it clears the ledger you read by eye before going live. There is no undo.', 'gratora' ) }
                >
                    <ul className="gratora-advanced-cron">
                        { testData.donations > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of test donations */
                                    _n( '%d test donation', '%d test donations', testData.donations, 'gratora' ),
                                    testData.donations
                                ) }
                            </li>
                        ) }
                        { testData.recurring_plans > 0 && (
                            <li>
                                { sprintf(
                                    /* translators: %d: number of test recurring plans */
                                    _n( '%d test recurring plan', '%d test recurring plans', testData.recurring_plans, 'gratora' ),
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
                                        'gratora'
                                    ),
                                    testData.donors
                                ) }
                            </li>
                        ) }
                    </ul>
                    <div className="gratora-advanced-actions" style={ { marginTop: 12 } }>
                        <label className="gratora-tools-field">
                            { sprintf(
                                /* translators: %s: the literal confirmation keyword to type (DELETE) */
                                __( 'Type %s to confirm.', 'gratora' ),
                                CONFIRM_WORD
                            ) }
                            <input
                                type="text"
                                className="gratora-input"
                                value={ purgeText }
                                onChange={ ( e ) => setPurgeText( e.target.value ) }
                                disabled={ purging }
                            />
                        </label>
                        <Btn
                            variant="danger"
                            onClick={ doPurgeTestData }
                            disabled={ purging || purgeText.trim().toUpperCase() !== CONFIRM_WORD }
                            isBusy={ purging }
                        >
                            { purging ? __( 'Removing…', 'gratora' ) : __( 'Delete test data', 'gratora' ) }
                        </Btn>
                    </div>
                </Card>
            ) }

            <Card
                title={ __( 'Setup wizard', 'gratora' ) }
                sub={ __( 'Walks through currency, the first campaign, and a payment gateway. Re-running it changes nothing you have already set unless you complete a step.', 'gratora' ) }
            >
                <div className="gratora-advanced-actions">
                    <Btn variant="secondary" href="admin.php?page=gratora-onboarding">
                        { __( 'Open setup wizard', 'gratora' ) }
                    </Btn>
                </div>
            </Card>
        </div>
    );
}
