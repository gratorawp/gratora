import { __ } from '@wordpress/i18n';
import Dialog from './Dialog';
import Btn from './Btn';

/**
 * Shared confirm dialog for list-table bulk/row actions. Replaces native
 * window.confirm() so every admin surface uses the same Dono-styled modal.
 *
 * Driven by a `confirm` state object (or null when closed):
 *   {
 *     title:        string,            // dialog heading
 *     message:      string|node,       // body copy
 *     confirmLabel: string,            // primary button label (default "Confirm")
 *     destructive:  boolean,           // render the primary button as danger
 *     onConfirm:    () => void|Promise // run after the dialog closes
 *   }
 *
 * Usage:
 *   const [ confirm, setConfirm ] = useState( null );
 *   ...
 *   setConfirm( { title, message, confirmLabel, destructive, onConfirm } );
 *   ...
 *   <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
 */
export default function ConfirmDialog( { confirm, onClose } ) {
    if ( ! confirm ) return null;

    return (
        <Dialog
            title={ confirm.title }
            onClose={ onClose }
            foot={
                <>
                    <Btn variant="secondary" onClick={ onClose }>
                        { __( 'Cancel', 'dono' ) }
                    </Btn>
                    <Btn
                        variant={ confirm.destructive ? 'danger' : 'primary' }
                        onClick={ async () => {
                            const action = confirm.onConfirm;
                            onClose();
                            if ( action ) await action();
                        } }
                    >
                        { confirm.confirmLabel || __( 'Confirm', 'dono' ) }
                    </Btn>
                </>
            }
        >
            <p style={ { margin: 0 } }>{ confirm.message }</p>
        </Dialog>
    );
}
