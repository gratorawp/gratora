import { __ } from '@wordpress/i18n';
import Dialog from './Dialog';
import Btn from './Btn';

/**
 * Shared confirm dialog for list-table bulk/row actions; replaces window.confirm
 * with the Dono-styled modal. Driven by a `confirm` state object, null when closed:
 * { title, message, confirmLabel, destructive, onConfirm } (onConfirm runs after close).
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
