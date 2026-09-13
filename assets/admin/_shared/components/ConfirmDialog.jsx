import { __, sprintf } from '@wordpress/i18n';
import { useState, useEffect } from '@wordpress/element';
import { Spinner } from '@wordpress/components';
import Dialog from './Dialog';
import Btn from './Btn';

/**
 * Controlled confirmation modal: null closes it. requireText gates destructive
 * actions.
 *
 * onConfirm is awaited with the dialog still up, and is handed a reporter it
 * can call with (done, total) as it goes. A batch delete sends a page of rows
 * fifty at a time and takes seconds over a real connection, and the screen
 * behind this has nothing on it that moves: closing first left an admin
 * looking at exactly what they would see if the click had missed.
 */
export default function ConfirmDialog( { confirm, onClose } ) {
    const [ typed, setTyped ]       = useState( '' );
    const [ running, setRunning ]   = useState( false );
    const [ progress, setProgress ] = useState( null );

    // Clear between openings, or the word typed for the last action leaves the
    // next one a single click away.
    useEffect( () => {
        setTyped( '' );
        setRunning( false );
        setProgress( null );
    }, [ confirm ] );

    if ( ! confirm ) return null;

    const required = confirm.requireText || '';
    const matches  = required === '' || typed.trim().toLowerCase() === required.toLowerCase();

    // Dismissal is off while rows are going: the work does not stop with the
    // dialog, so a closed one would be claiming the action was called off.
    const dismiss = () => {
        if ( ! running ) onClose();
    };

    const run = async () => {
        const action = confirm.onConfirm;
        if ( ! action ) {
            onClose();
            return;
        }

        setRunning( true );
        try {
            await action( ( done, total ) => setProgress( { done, total } ) );
        } catch ( e ) {
            // Swallowed on purpose: every caller reports its own failure, and
            // letting it out of an onClick leaves an unhandled rejection and a
            // dialog that never comes down.
        } finally {
            // Given back on a refusal as much as on a success: a dialog left
            // spinning over a failed call is the one an admin most needs out
            // of the way to read what went wrong.
            setRunning( false );
            setProgress( null );
            onClose();
        }
    };

    const busyText = progress && progress.total > 1
        ? sprintf(
            /* translators: 1: rows finished so far, 2: rows in total */
            __( '%1$d of %2$d', 'gratora-donation-platform' ),
            progress.done,
            progress.total
        )
        : ( confirm.busyLabel || __( 'Working…', 'gratora-donation-platform' ) );

    return (
        <Dialog
            title={ confirm.title }
            onClose={ dismiss }
            foot={
                <>
                    <Btn variant="secondary" onClick={ dismiss } disabled={ running }>
                        { __( 'Cancel', 'gratora-donation-platform' ) }
                    </Btn>
                    <Btn
                        variant={ confirm.destructive ? 'danger' : 'primary' }
                        disabled={ ! matches }
                        isBusy={ running }
                        onClick={ run }
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
            { running ? (
                <div
                    className="gratora-confirm-busy"
                    aria-live="polite"
                    style={ { marginTop: 16, display: 'flex', alignItems: 'center', gap: 8 } }
                >
                    <Spinner />
                    <span>{ busyText }</span>
                </div>
            ) : ( required !== '' && (
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
            ) ) }
        </Dialog>
    );
}
