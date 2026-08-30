import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Btn from '../../_shared/components/Btn';

function formatWhen( iso ) {
    const d = new Date( iso );
    return isNaN( d ) ? iso : d.toLocaleString();
}

export default function SystemInfoTab( { info, infoError, loadInfo, setNotice } ) {
    const [ copied, setCopied ] = useState( false );

    const sections = info?.report ?? [];

    // One text block, in the order the screen shows it, so what lands in a
    // ticket is what the person was looking at.
    const asText = () => sections
        .map( ( s ) => `== ${ s.title } ==\n`
            + s.rows.map( ( r ) => `${ r.label }: ${ r.value }` ).join( '\n' ) )
        .join( '\n\n' );

    const copy = async () => {
        try {
            await navigator.clipboard.writeText( asText() );
            setCopied( true );
            setTimeout( () => setCopied( false ), 2000 );
        } catch ( err ) {
            setNotice( { type: 'error', text: __( 'Could not copy. Select the values instead.', 'fundkit-fundraising-campaigns' ) } );
        }
    };

    return (
        <div className="fundkit-panel">
            <Card
                title={ __( 'System info', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Everything a support request needs. No keys or credentials are included, so it is safe to paste.', 'fundkit-fundraising-campaigns' ) }
            >
                { infoError ? (
                    <div className="fundkit-advanced-actions">
                        <p style={ { color: '#b42318', margin: 0 } }>{ __( 'Could not load system info.', 'fundkit-fundraising-campaigns' ) }</p>
                        <Btn variant="secondary" onClick={ loadInfo }>{ __( 'Retry', 'fundkit-fundraising-campaigns' ) }</Btn>
                    </div>
                ) : ! info ? (
                    <p className="fundkit-tools-empty">{ __( 'Loading…', 'fundkit-fundraising-campaigns' ) }</p>
                ) : (
                    <>
                        { sections.map( ( section ) => (
                            <div key={ section.title } className="fundkit-sysinfo__group">
                                <h3 className="fundkit-sysinfo__title">{ section.title }</h3>
                                <div className="fundkit-advanced-info">
                                    { section.rows.map( ( r, i ) => (
                                        <div key={ `${ section.title }-${ i }` }>
                                            <dt>{ r.label }</dt>
                                            <dd><code>{ r.value }</code></dd>
                                        </div>
                                    ) ) }
                                </div>
                            </div>
                        ) ) }
                        <div className="fundkit-advanced-actions" style={ { marginTop: 12 } }>
                            <Btn variant="secondary" onClick={ copy }>
                                { copied ? __( 'Copied', 'fundkit-fundraising-campaigns' ) : __( 'Copy to clipboard', 'fundkit-fundraising-campaigns' ) }
                            </Btn>
                        </div>
                    </>
                ) }
            </Card>

            <Card
                title={ __( 'Scheduled tasks', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'FundKit jobs queued with Action Scheduler, and when each is due.', 'fundkit-fundraising-campaigns' ) }
            >
                { info?.cron?.length ? (
                    <ul className="fundkit-advanced-cron">
                        { info.cron.map( ( c, i ) => (
                            <li key={ i }>
                                <code>{ c.hook }</code>
                                <span className="fundkit-tools-log__when"> { formatWhen( c.next ) }</span>
                            </li>
                        ) ) }
                    </ul>
                ) : (
                    <p className="fundkit-tools-empty">{ __( 'Nothing queued right now.', 'fundkit-fundraising-campaigns' ) }</p>
                ) }
            </Card>
        </div>
    );
}
