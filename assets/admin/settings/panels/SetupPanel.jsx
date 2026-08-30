import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import useCardOpen from '../../_shared/useCardOpen';

// Order: money first, then whether a donor can reach you, then whether they
// hear back, then the machinery underneath.
const GROUPS = [
    { id: 'money',    title: __( 'Taking money', 'fundkit-fundraising-campaigns' ),          sub: __( 'What has to be true before a card is charged', 'fundkit-fundraising-campaigns' ) },
    { id: 'page',     title: __( 'A live donation page', 'fundkit-fundraising-campaigns' ),  sub: __( 'Somewhere for a donor to land', 'fundkit-fundraising-campaigns' ) },
    { id: 'receipts', title: __( 'Receipts and email', 'fundkit-fundraising-campaigns' ),    sub: __( 'What the donor gets back', 'fundkit-fundraising-campaigns' ) },
    { id: 'jobs',     title: __( 'Background jobs', 'fundkit-fundraising-campaigns' ),       sub: __( 'Receipts and emails are queued, not sent inline', 'fundkit-fundraising-campaigns' ) },
    { id: 'portal',   title: __( 'Donor portal', 'fundkit-fundraising-campaigns' ),          sub: __( 'Where sign-in and receipt links point', 'fundkit-fundraising-campaigns' ) },
    { id: 'licenses', title: __( 'Add-ons and licenses', 'fundkit-fundraising-campaigns' ),  sub: __( 'Updates and security fixes for what you installed', 'fundkit-fundraising-campaigns' ) },
];

export default function SetupPanel( { onJumpTo, active } ) {
    const [ report, setReport ] = useState( null );
    const [ error, setError ]   = useState( false );

    const load = useCallback( () => {
        setError( false );
        apiFetch( { path: '/fundkit/v1/admin/readiness' } )
            .then( setReport )
            .catch( () => setError( true ) );
    }, [] );

    // Tabs are hidden, not unmounted, so a mount-once fetch showed whatever was
    // true when the screen first opened. The whole point of this panel is that
    // its Fix links jump to another tab: an operator connected Stripe, came
    // back, and was still told Stripe was not connected.
    useEffect( () => { if ( active ) load(); }, [ active, load ] );

    if ( error ) {
        return (
            <div className="fundkit-panel">
                <Card title={ __( 'Could not check your setup', 'fundkit-fundraising-campaigns' ) }>
                    <p className="fundkit-connect-p">
                        { __( 'Something went wrong reading the readiness report. Nothing is broken by this on its own.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                    <Btn variant="primary" onClick={ load }>{ __( 'Try again', 'fundkit-fundraising-campaigns' ) }</Btn>
                </Card>
            </div>
        );
    }

    if ( ! report ) {
        return (
            <div className="fundkit-panel">
                <div className="fundkit-readiness__head">
                    <div className="fundkit-readiness__title">{ __( 'Checking your setup…', 'fundkit-fundraising-campaigns' ) }</div>
                </div>
            </div>
        );
    }

    const checks = report.checks || [];

    return (
        <div className="fundkit-panel">
            <Summary report={ report } />

            { GROUPS.map( ( group ) => {
                const rows = checks.filter( ( c ) => c.group === group.id );
                if ( rows.length === 0 ) {
                    return null;
                }

                return <Group key={ group.id } group={ group } rows={ rows } onJumpTo={ onJumpTo } />;
            } ) }
        </div>
    );
}

function Summary( { report } ) {
    const blockers = report.blockers || 0;
    const warnings = report.warnings || 0;

    let title = __( 'Ready to accept donations', 'fundkit-fundraising-campaigns' );
    let sub   = __( 'Nothing on this page is standing in a donor’s way.', 'fundkit-fundraising-campaigns' );
    let tone  = 'green';

    if ( blockers > 0 ) {
        tone  = 'red';
        title = sprintf(
            /* translators: %d: number of things preventing donations. */
            _n( '%d thing is stopping donations', '%d things are stopping donations', blockers, 'fundkit-fundraising-campaigns' ),
            blockers
        );
        sub = __( 'Until these are fixed, a donor cannot complete a donation.', 'fundkit-fundraising-campaigns' );
    } else if ( warnings > 0 ) {
        tone = 'amber';
        sub  = sprintf(
            /* translators: %d: number of non-blocking issues. */
            _n( '%d thing is worth a look, but donations work.', '%d things are worth a look, but donations work.', warnings, 'fundkit-fundraising-campaigns' ),
            warnings
        );
    }

    return (
        <div className={ `fundkit-readiness__head is-${ tone }` }>
            <span className={ `fundkit-readiness__dot is-${ tone }` } />
            <div>
                <div className="fundkit-readiness__title">{ title }</div>
                <div className="fundkit-readiness__sub">{ sub }</div>
            </div>
        </div>
    );
}

// A group with nothing wrong is closed: the pill says so, and the rows behind
// it are things the operator already did.
function Group( { group, rows, onJumpTo } ) {
    const trouble = rows.filter( ( r ) => r.status !== 'pass' ).length;
    const [ open, setOpen ] = useCardOpen( trouble > 0 );

    const pill = trouble === 0
        ? <span className="fundkit-pill fundkit-pill--green"><span className="fundkit-pill__dot" />{ __( 'All good', 'fundkit-fundraising-campaigns' ) }</span>
        : (
            <span className="fundkit-pill fundkit-pill--amber">
                <span className="fundkit-pill__dot" />
                { sprintf(
                    /* translators: %d: number of checks in this group needing attention. */
                    _n( '%d needs attention', '%d need attention', trouble, 'fundkit-fundraising-campaigns' ),
                    trouble
                ) }
            </span>
        );

    return (
        <Card
            title={ group.title }
            sub={ group.sub }
            meta={ pill }
            collapsible
            open={ open }
            onToggle={ setOpen }
        >
            <ul className="fundkit-readiness__rows">
                { rows.map( ( row ) => <Row key={ row.id } row={ row } onJumpTo={ onJumpTo } /> ) }
            </ul>
        </Card>
    );
}

function Row( { row, onJumpTo } ) {
    // An action pointing at another settings tab is a tab switch, not a page
    // load, but it stays a real link so it can still be opened in a new tab.
    const jump = ( e ) => {
        const url = row.action_url || '';
        if ( ! url.includes( 'page=fundkit-settings' ) || ! url.includes( '#' ) ) {
            return;
        }
        e.preventDefault();
        onJumpTo( url.split( '#' )[ 1 ] );
    };

    return (
        <li className="fundkit-readiness-row" data-status={ row.status }>
            <span className="fundkit-readiness-row__dot" />
            <div className="fundkit-readiness-row__body">
                <div className="fundkit-readiness-row__label">{ row.label }</div>
                { row.detail && <div className="fundkit-readiness-row__detail">{ row.detail }</div> }
            </div>
            { row.action_url && (
                <a className="fundkit-readiness-row__action" href={ row.action_url } onClick={ jump }>
                    { row.action_label || __( 'Fix', 'fundkit-fundraising-campaigns' ) } →
                </a>
            ) }
        </li>
    );
}
