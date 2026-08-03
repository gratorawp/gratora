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

    const rows = info ? [
        [ __( 'Dono version', 'dono' ), info.version ],
        [ __( 'PHP version', 'dono' ), info.php ],
        [ __( 'WordPress', 'dono' ), info.wp ],
        [ __( 'Site URL', 'dono' ), info.site_url ],
        [ __( 'REST namespace', 'dono' ), info.rest_root ],
    ] : [];

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(
                rows.map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( '\n' )
            );
            setCopied( true );
            setTimeout( () => setCopied( false ), 2000 );
        } catch ( err ) {
            setNotice( { type: 'error', text: __( 'Could not copy. Select the values instead.', 'dono' ) } );
        }
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'System info', 'dono' ) }
                sub={ __( 'Worth pasting into a support request.', 'dono' ) }
            >
                { infoError ? (
                    <div className="dono-advanced-actions">
                        <p style={ { color: '#b42318', margin: 0 } }>{ __( 'Could not load system info.', 'dono' ) }</p>
                        <Btn variant="secondary" onClick={ loadInfo }>{ __( 'Retry', 'dono' ) }</Btn>
                    </div>
                ) : ! info ? (
                    <p className="dono-tools-empty">{ __( 'Loading…', 'dono' ) }</p>
                ) : (
                    <>
                        <div className="dono-advanced-info">
                            { rows.map( ( [ label, value ] ) => (
                                <div key={ label }>
                                    <dt>{ label }</dt>
                                    <dd><code>{ value }</code></dd>
                                </div>
                            ) ) }
                        </div>
                        <div className="dono-advanced-actions" style={ { marginTop: 12 } }>
                            <Btn variant="secondary" onClick={ copy }>
                                { copied ? __( 'Copied', 'dono' ) : __( 'Copy to clipboard', 'dono' ) }
                            </Btn>
                        </div>
                    </>
                ) }
            </Card>

            <Card
                title={ __( 'Scheduled tasks', 'dono' ) }
                sub={ __( 'Dono jobs queued with WP-Cron, and when each is due.', 'dono' ) }
            >
                { info?.cron?.length ? (
                    <ul className="dono-advanced-cron">
                        { info.cron.map( ( c, i ) => (
                            <li key={ i }>
                                <code>{ c.hook }</code>
                                <span className="dono-tools-log__when"> { formatWhen( c.next ) }</span>
                            </li>
                        ) ) }
                    </ul>
                ) : (
                    <p className="dono-tools-empty">{ __( 'Nothing queued right now.', 'dono' ) }</p>
                ) }
            </Card>
        </div>
    );
}
