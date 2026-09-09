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
            setNotice( { type: 'error', text: __( 'Could not copy. Select the values instead.', 'gratora' ) } );
        }
    };

    return (
        <div className="gratora-panel">
            <Card
                title={ __( 'System info', 'gratora' ) }
                sub={ __( 'Everything a support request needs. No keys or credentials are included, so it is safe to paste.', 'gratora' ) }
            >
                { infoError ? (
                    <div className="gratora-advanced-actions">
                        <p style={ { color: '#b42318', margin: 0 } }>{ __( 'Could not load system info.', 'gratora' ) }</p>
                        <Btn variant="secondary" onClick={ loadInfo }>{ __( 'Retry', 'gratora' ) }</Btn>
                    </div>
                ) : ! info ? (
                    <p className="gratora-tools-empty">{ __( 'Loading…', 'gratora' ) }</p>
                ) : (
                    <>
                        { sections.map( ( section ) => (
                            <div key={ section.title } className="gratora-sysinfo__group">
                                <h3 className="gratora-sysinfo__title">{ section.title }</h3>
                                <div className="gratora-advanced-info">
                                    { section.rows.map( ( r, i ) => (
                                        <div key={ `${ section.title }-${ i }` }>
                                            <dt>{ r.label }</dt>
                                            <dd><code>{ r.value }</code></dd>
                                        </div>
                                    ) ) }
                                </div>
                            </div>
                        ) ) }
                        <div className="gratora-advanced-actions" style={ { marginTop: 12 } }>
                            <Btn variant="secondary" onClick={ copy }>
                                { copied ? __( 'Copied', 'gratora' ) : __( 'Copy to clipboard', 'gratora' ) }
                            </Btn>
                        </div>
                    </>
                ) }
            </Card>

            <Card
                title={ __( 'Scheduled tasks', 'gratora' ) }
                sub={ __( 'Gratora jobs queued with Action Scheduler, and when each is due.', 'gratora' ) }
            >
                { info?.cron?.length ? (
                    <ul className="gratora-advanced-cron">
                        { info.cron.map( ( c, i ) => (
                            <li key={ i }>
                                <code>{ c.hook }</code>
                                <span className="gratora-tools-log__when"> { formatWhen( c.next ) }</span>
                            </li>
                        ) ) }
                    </ul>
                ) : (
                    <p className="gratora-tools-empty">{ __( 'Nothing queued right now.', 'gratora' ) }</p>
                ) }
            </Card>
        </div>
    );
}
