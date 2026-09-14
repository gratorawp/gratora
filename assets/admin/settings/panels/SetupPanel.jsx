import { __, _n, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';
import { forwardGlyph } from '../../_shared/arrow';
import useCardOpen from '../../_shared/useCardOpen';

// Order: money first, then whether a donor can reach you, then whether they
// hear back, then the machinery underneath.
const GROUPS = [
    { id: 'money',    title: __( 'Taking money', 'gratora-donation-platform' ),          sub: __( 'What has to be true before a card is charged', 'gratora-donation-platform' ) },
    { id: 'page',     title: __( 'A live donation page', 'gratora-donation-platform' ),  sub: __( 'Somewhere for a donor to land', 'gratora-donation-platform' ) },
    { id: 'receipts', title: __( 'Receipts and email', 'gratora-donation-platform' ),    sub: __( 'What the donor gets back', 'gratora-donation-platform' ) },
    { id: 'jobs',     title: __( 'Background jobs', 'gratora-donation-platform' ),       sub: __( 'Receipts and emails are queued, not sent inline', 'gratora-donation-platform' ) },
    { id: 'portal',   title: __( 'Donor portal', 'gratora-donation-platform' ),          sub: __( 'Where sign-in and receipt links point', 'gratora-donation-platform' ) },
    { id: 'licenses', title: __( 'Add-ons and licenses', 'gratora-donation-platform' ),  sub: __( 'Updates and security fixes for what you installed', 'gratora-donation-platform' ) },
];

export default function SetupPanel( { onJumpTo, active } ) {
    const [ report, setReport ] = useState( null );
    const [ error, setError ]   = useState( false );

    const load = useCallback( () => {
        setError( false );
        apiFetch( { path: '/gratora/v1/admin/readiness' } )
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
            <div className="gratora-panel">
                <Card title={ __( 'Could not check your setup', 'gratora-donation-platform' ) }>
                    <p className="gratora-connect-p">
                        { __( 'Something went wrong reading the readiness report. Nothing is broken by this on its own.', 'gratora-donation-platform' ) }
                    </p>
                    <Btn variant="primary" onClick={ load }>{ __( 'Try again', 'gratora-donation-platform' ) }</Btn>
                </Card>
            </div>
        );
    }

    if ( ! report ) {
        return (
            <div className="gratora-panel">
                <div className="gratora-readiness__head">
                    <div className="gratora-readiness__title">{ __( 'Checking your setup…', 'gratora-donation-platform' ) }</div>
                </div>
            </div>
        );
    }

    const checks = report.checks || [];

    return (
        <div className="gratora-panel">
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

    let title = __( 'Ready to accept donations', 'gratora-donation-platform' );
    let sub   = __( 'Nothing on this page is standing in a donor’s way.', 'gratora-donation-platform' );
    let tone  = 'green';

    if ( blockers > 0 ) {
        tone  = 'red';
        title = sprintf(
            /* translators: %d: number of things preventing donations. */
            _n( '%d thing is stopping donations', '%d things are stopping donations', blockers, 'gratora-donation-platform' ),
            blockers
        );
        // No count here: the headline just gave it. What this has to do is
        // agree with it, and a ternary on 1 would only agree in languages with
        // two plural forms.
        sub = _n(
            'Until it is fixed, a donor cannot complete a donation.',
            'Until they are fixed, a donor cannot complete a donation.',
            blockers,
            'gratora-donation-platform'
        );
    } else if ( warnings > 0 ) {
        tone = 'amber';
        sub  = sprintf(
            /* translators: %d: number of non-blocking issues. */
            _n( '%d thing is worth a look, but donations work.', '%d things are worth a look, but donations work.', warnings, 'gratora-donation-platform' ),
            warnings
        );
    }

    return (
        <div className={ `gratora-readiness__head is-${ tone }` }>
            <span className={ `gratora-readiness__dot is-${ tone }` } />
            <div>
                <div className="gratora-readiness__title">{ title }</div>
                <div className="gratora-readiness__sub">{ sub }</div>
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
        ? <span className="gratora-pill gratora-pill--green"><span className="gratora-pill__dot" />{ __( 'All good', 'gratora-donation-platform' ) }</span>
        : (
            <span className="gratora-pill gratora-pill--amber">
                <span className="gratora-pill__dot" />
                { sprintf(
                    /* translators: %d: number of checks in this group needing attention. */
                    _n( '%d needs attention', '%d need attention', trouble, 'gratora-donation-platform' ),
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
            <ul className="gratora-readiness__rows">
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
        if ( ! url.includes( 'page=gratora-settings' ) || ! url.includes( '#' ) ) {
            return;
        }
        e.preventDefault();
        onJumpTo( url.split( '#' )[ 1 ] );
    };

    return (
        <li className="gratora-readiness-row" data-status={ row.status }>
            <span className="gratora-readiness-row__dot" />
            <div className="gratora-readiness-row__body">
                <div className="gratora-readiness-row__label">{ row.label }</div>
                { row.detail && <div className="gratora-readiness-row__detail">{ row.detail }</div> }
            </div>
            { row.action_url && (
                <a className="gratora-readiness-row__action" href={ row.action_url } onClick={ jump }>
                    { row.action_label || __( 'Fix', 'gratora-donation-platform' ) } { forwardGlyph() }
                </a>
            ) }
        </li>
    );
}
