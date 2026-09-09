import { __, sprintf } from '@wordpress/i18n';
import { slidable } from '@gratora/ui/styling/TokenEditor';

/**
 * The row shows a value no slider can hold as the literal it is, which is
 * honest but only once the group holding it is expanded. Every group but the
 * first is collapsed, so this names them where the admin is already looking.
 */
export default function UnshownNotice( { tokens = {}, catalogue = {} } ) {
    const unshown = Object.entries( catalogue )
        .filter( ( [ , def ] ) => def.control === 'range' )
        .map( ( [ key, def ] ) => ( { key, def, label: def.label || key, value: String( tokens[ key ] ?? '' ) } ) )
        .filter( ( t ) => t.value !== '' && ! slidable( t.value, t.def.min ?? 0, t.def.max ?? 32 ) );

    if ( ! unshown.length ) return null;

    return (
        <ul className="gratora-preset-editor__unshown">
            { unshown.map( ( t ) => (
                <li key={ t.key }>
                    { sprintf(
                        /* translators: 1: token name, e.g. Small corner radius, 2: the value it holds, e.g. 1rem */
                        __( '%1$s is %2$s, which is outside what its slider offers. Its row takes the value as text.', 'gratora' ),
                        t.label,
                        t.value
                    ) }
                </li>
            ) ) }
        </ul>
    );
}
