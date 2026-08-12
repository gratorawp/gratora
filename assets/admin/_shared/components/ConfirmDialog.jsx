import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import Dialog from './Dialog';
import Btn from './Btn';

/**
 * Shared confirm dialog for list-table bulk/row actions; replaces window.confirm
 * with the Dono-styled modal. Driven by a `confirm` state object, null when closed:
 * { title, message, confirmLabel, destructive, onConfirm } (onConfirm runs after close).
 *
 * `requireText` holds the confirm button until the word is typed back, for the
 * handful of actions that erase something no undo can restore.
 */
export default function ConfirmDialog( { confirm, onClose } ) {
    const [ typed, setTyped ] = useState( '' );

    // Clear between openings, or the word typed for the last action leaves the
    // next one a single click away.
    useEffect( () => setTyped( '' ), [ confirm ] );

    if ( ! confirm ) return null;

    const required = confirm.requireText || '';
    const matches  = required === '' || typed.trim().toLowerCase() === required.toLowerCase();

    return (
        <Dialog
            title={ confirm.title }
            onClose={ onClose }
            foot={
                <>
                    <Btn variant="secondary" onClick={ onClose }>
                        { __( 'Cancel', 'dono-fundraising-platform' ) }
                    </Btn>
                    <Btn
                        variant={ confirm.destructive ? 'danger' : 'primary' }
                        disabled={ ! matches }
                        onClick={ async () => {
                            const action = confirm.onConfirm;
                            onClose();
                            if ( action ) await action();
                        } }
                    >
                        { confirm.confirmLabel || __( 'Confirm', 'dono-fundraising-platform' ) }
                    </Btn>
                </>
            }
        >
            <p style={ { margin: 0 } }>{ confirm.message }</p>
            { required !== '' && (
                <label className="dono-fld" style={ { marginTop: 16, display: 'block' } }>
                    { sprintf( /* translators: %s: confirmation word */ __( 'Type %s to confirm', 'dono-fundraising-platform' ), required ) }
                    <input
                        className="dono-input"
                        type="text"
                        value={ typed }
                        onChange={ ( e ) => setTyped( e.target.value ) }
                        autoComplete="off"
                        spellCheck="false"
                    />
                </label>
            ) }
        </Dialog>
    );
}
