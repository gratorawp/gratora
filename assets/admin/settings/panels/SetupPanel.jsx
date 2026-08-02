import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import useCardOpen from '../../_shared/useCardOpen';

// Order: money first, then whether a donor can reach you, then whether they
// hear back, then the machinery underneath.
const GROUPS = [
    { id: 'money',    title: __( 'Taking money', 'dono' ),          sub: __( 'What has to be true before a card is charged', 'dono' ) },
    { id: 'page',     title: __( 'A live donation page', 'dono' ),  sub: __( 'Somewhere for a donor to land', 'dono' ) },
    { id: 'receipts', title: __( 'Receipts and email', 'dono' ),    sub: __( 'What the donor gets back', 'dono' ) },
    { id: 'jobs',     title: __( 'Background jobs', 'dono' ),       sub: __( 'Receipts and emails are queued, not sent inline', 'dono' ) },
    { id: 'portal',   title: __( 'Donor portal', 'dono' ),          sub: __( 'Where sign-in and receipt links point', 'dono' ) },
    { id: 'licenses', title: __( 'Add-ons and licenses', 'dono' ),  sub: __( 'Updates and security fixes for what you installed', 'dono' ) },
];

export default function SetupPanel( { onJumpTo, active } ) {
    const [ report, setReport ] = useState( null );
    const [ error, setError ]   = useState( false );

    const load = useCallback( () => {
        setError( false );
        apiFetch( { path: '/dono/v1/admin/readiness' } )
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
            <div className="dono-panel">
                <Card title={ __( 'Could not check your setup', 'dono' ) }>
                    <p className="dono-connect-p">
                        { __( 'Something went wrong reading the readiness report. Nothing is broken by this on its own.', 'dono' ) }
                    </p>
                    <Btn variant="primary" onClick={ load }>{ __( 'Try again', 'dono' ) }</Btn>
                </Card>
            </div>
        );
    }

    if ( ! report ) {
        return (
            <div className="dono-panel">
                <div className="dono-readiness__head">
                    <div className="dono-readiness__title">{ __( 'Checking your setup…', 'dono' ) }</div>
                </div>
            </div>
        );
    }

    const checks = report.checks || [];

    return (
        <div className="dono-panel">
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

    let title = __( 'Ready to accept donations', 'dono' );
    let sub   = __( 'Nothing on this page is standing in a donor’s way.', 'dono' );
    let tone  = 'green';

    if ( blockers > 0 ) {
        tone  = 'red';
        title = sprintf(
            /* translators: %d: number of things preventing donations. */
            _n( '%d thing is stopping donations', '%d things are stopping donations', blockers, 'dono' ),
            blockers
        );
        sub = __( 'Until these are fixed, a donor cannot complete a donation.', 'dono' );
    } else if ( warnings > 0 ) {
        tone = 'amber';
        sub  = sprintf(
            /* translators: %d: number of non-blocking issues. */
            _n( '%d thing is worth a look, but donations work.', '%d things are worth a look, but donations work.', warnings, 'dono' ),
            warnings
        );
    }

    return (
        <div className={ `dono-readiness__head is-${ tone }` }>
            <span className={ `dono-readiness__dot is-${ tone }` } />
            <div>
                <div className="dono-readiness__title">{ title }</div>
                <div className="dono-readiness__sub">{ sub }</div>
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
        ? <span className="dono-pill dono-pill--green"><span className="dono-pill__dot" />{ __( 'All good', 'dono' ) }</span>
        : (
            <span className="dono-pill dono-pill--amber">
                <span className="dono-pill__dot" />
                { sprintf(
                    /* translators: %d: number of checks in this group needing attention. */
                    _n( '%d needs attention', '%d need attention', trouble, 'dono' ),
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
            <ul className="dono-readiness__rows">
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
        if ( ! url.includes( 'page=dono-settings' ) || ! url.includes( '#' ) ) {
            return;
        }
        e.preventDefault();
        onJumpTo( url.split( '#' )[ 1 ] );
    };

    return (
        <li className="dono-readiness-row" data-status={ row.status }>
            <span className="dono-readiness-row__dot" />
            <div className="dono-readiness-row__body">
                <div className="dono-readiness-row__label">{ row.label }</div>
                { row.detail && <div className="dono-readiness-row__detail">{ row.detail }</div> }
            </div>
            { row.action_url && (
                <a className="dono-readiness-row__action" href={ row.action_url } onClick={ jump }>
                    { row.action_label || __( 'Fix', 'dono' ) } →
                </a>
            ) }
        </li>
    );
}
