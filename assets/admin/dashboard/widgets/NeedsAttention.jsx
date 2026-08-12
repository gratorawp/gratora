import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { X as DismissIcon } from 'lucide-react';
import notify from '../../_shared/notify';

export default function NeedsAttention( { items = [] } ) {
    // Dismissed locally as well as on the server: the widget is fed by the
    // dashboard's own fetch, which does not re-run on a dismiss.
    const [ hidden, setHidden ] = useState( [] );

    const visible = items.filter( ( i ) => ! hidden.includes( i.key ) );

    const dismiss = async ( item ) => {
        setHidden( ( h ) => [ ...h, item.key ] );
        try {
            await apiFetch( {
                path:   '/dono/v1/admin/me/attention/dismiss',
                method: 'POST',
                data:   { key: item.key, signature: item.signature || 'x' },
            } );
            notify.success( __( 'Dismissed. It comes back if the situation changes.', 'dono-fundraising-platform' ), {
                // The default 4s is not long enough to read the sentence and
                // decide, and the row is already gone by then.
                duration: 10000,
                action: {
                    label:   __( 'Undo', 'dono-fundraising-platform' ),
                    onClick: () => restore( item ),
                },
            } );
        } catch ( err ) {
            setHidden( ( h ) => h.filter( ( k ) => k !== item.key ) );
            notify.error( err?.message || __( 'Could not dismiss that.', 'dono-fundraising-platform' ) );
        }
    };

    const restore = async ( item ) => {
        try {
            await apiFetch( {
                path:   '/dono/v1/admin/me/attention/restore',
                method: 'POST',
                data:   { key: item.key },
            } );
            setHidden( ( h ) => h.filter( ( k ) => k !== item.key ) );
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not bring that back.', 'dono-fundraising-platform' ) );
        }
    };

    if ( visible.length === 0 ) {
        return (
            <p className="dono-attention__empty">
                { __( 'Nothing needs attention right now.', 'dono-fundraising-platform' ) }
            </p>
        );
    }

    return (
        <ul className="dono-attention">
            { visible.map( ( item ) => (
                <li key={ item.key } className={ `dono-attention__item is-${ item.tone }` }>
                    <span className="dono-attention__dot" aria-hidden="true" />
                    <span className="dono-attention__title">{ item.title }</span>
                    { item.action_href && (
                        <a className="dono-attention__action" href={ item.action_href }>
                            { item.action_label || __( 'Open', 'dono-fundraising-platform' ) } →
                        </a>
                    ) }
                    <button
                        type="button"
                        className="dono-attention__dismiss"
                        onClick={ () => dismiss( item ) }
                        aria-label={ __( 'Dismiss', 'dono-fundraising-platform' ) }
                        title={ __( 'Dismiss', 'dono-fundraising-platform' ) }
                    >
                        <DismissIcon size={ 14 } strokeWidth={ 2 } />
                    </button>
                </li>
            ) ) }
        </ul>
    );
}
