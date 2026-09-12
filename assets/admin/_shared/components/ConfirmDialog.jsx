import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import Dialog from './Dialog';
import Btn from './Btn';

/**
 * Controlled confirmation modal: null closes it; onConfirm runs after closing. requireText
 * gates destructive actions.
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
                        { __( 'Cancel', 'gratora-donation-platform' ) }
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
                        { confirm.confirmLabel || __( 'Confirm', 'gratora-donation-platform' ) }
                    </Btn>
                </>
            }
        >
            <p style={ { margin: 0 } }>{ confirm.message }</p>
            { /* Anything the message cannot be: the notice about what will be
                 attempted at the gateway, and the checkbox that decides whether
                 the donor goes too. Rendered above the confirmation word, so
                 the last thing before typing it is what is about to happen. */ }
            { confirm.body }
            { required !== '' && (
                <label className="gratora-fld" style={ { marginTop: 16, display: 'block' } }>
                    { sprintf( /* translators: %s: confirmation word */ __( 'Type %s to confirm', 'gratora-donation-platform' ), required ) }
                    <input
                        className="gratora-input"
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
