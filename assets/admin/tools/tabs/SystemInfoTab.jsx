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
        [ __( 'GiveFlow version', 'giveflow-fundraising-campaigns' ), info.version ],
        [ __( 'PHP version', 'giveflow-fundraising-campaigns' ), info.php ],
        [ __( 'WordPress', 'giveflow-fundraising-campaigns' ), info.wp ],
        [ __( 'Site URL', 'giveflow-fundraising-campaigns' ), info.site_url ],
        [ __( 'REST namespace', 'giveflow-fundraising-campaigns' ), info.rest_root ],
    ] : [];

    const copy = async () => {
        try {
            await navigator.clipboard.writeText(
                rows.map( ( [ k, v ] ) => `${ k }: ${ v }` ).join( '\n' )
            );
            setCopied( true );
            setTimeout( () => setCopied( false ), 2000 );
        } catch ( err ) {
            setNotice( { type: 'error', text: __( 'Could not copy. Select the values instead.', 'giveflow-fundraising-campaigns' ) } );
        }
    };

    return (
        <div className="giveflow-panel">
            <Card
                title={ __( 'System info', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'Worth pasting into a support request.', 'giveflow-fundraising-campaigns' ) }
            >
                { infoError ? (
                    <div className="giveflow-advanced-actions">
                        <p style={ { color: '#b42318', margin: 0 } }>{ __( 'Could not load system info.', 'giveflow-fundraising-campaigns' ) }</p>
                        <Btn variant="secondary" onClick={ loadInfo }>{ __( 'Retry', 'giveflow-fundraising-campaigns' ) }</Btn>
                    </div>
                ) : ! info ? (
                    <p className="giveflow-tools-empty">{ __( 'Loading…', 'giveflow-fundraising-campaigns' ) }</p>
                ) : (
                    <>
                        <div className="giveflow-advanced-info">
                            { rows.map( ( [ label, value ] ) => (
                                <div key={ label }>
                                    <dt>{ label }</dt>
                                    <dd><code>{ value }</code></dd>
                                </div>
                            ) ) }
                        </div>
                        <div className="giveflow-advanced-actions" style={ { marginTop: 12 } }>
                            <Btn variant="secondary" onClick={ copy }>
                                { copied ? __( 'Copied', 'giveflow-fundraising-campaigns' ) : __( 'Copy to clipboard', 'giveflow-fundraising-campaigns' ) }
                            </Btn>
                        </div>
                    </>
                ) }
            </Card>

            <Card
                title={ __( 'Scheduled tasks', 'giveflow-fundraising-campaigns' ) }
                sub={ __( 'GiveFlow jobs queued with Action Scheduler, and when each is due.', 'giveflow-fundraising-campaigns' ) }
            >
                { info?.cron?.length ? (
                    <ul className="giveflow-advanced-cron">
                        { info.cron.map( ( c, i ) => (
                            <li key={ i }>
                                <code>{ c.hook }</code>
                                <span className="giveflow-tools-log__when"> { formatWhen( c.next ) }</span>
                            </li>
                        ) ) }
                    </ul>
                ) : (
                    <p className="giveflow-tools-empty">{ __( 'Nothing queued right now.', 'giveflow-fundraising-campaigns' ) }</p>
                ) }
            </Card>
        </div>
    );
}
