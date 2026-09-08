import { __, sprintf } from '@wordpress/i18n';

// px is the unit the slider writes back, and it clamps to the same min/max.
function slidable( value, def ) {
    const m = /^(-?\d+(?:\.\d+)?)(px)?$/.exec( String( value ).trim() );
    if ( ! m ) return false;
    const n = parseFloat( m[ 1 ] );
    if ( ! m[ 2 ] && n !== 0 ) return false;

    return n >= ( def.min ?? 0 ) && n <= ( def.max ?? 32 );
}

/**
 * A slider reads whole pixels and clamps to its own range. theme.json does not:
 * a button radius of 1rem or 9999px reaches the control as 1 or as its maximum,
 * so the panel states a size the site does not use.
 */
export default function UnshownNotice( { tokens = {}, catalogue = {} } ) {
    const unshown = Object.entries( catalogue )
        .filter( ( [ , def ] ) => def.control === 'range' )
        .map( ( [ key, def ] ) => ( { key, def, label: def.label || key, value: String( tokens[ key ] ?? '' ) } ) )
        .filter( ( t ) => t.value !== '' && ! slidable( t.value, t.def ) );

    if ( ! unshown.length ) return null;

    return (
        <ul className="fundkit-preset-editor__unshown">
            { unshown.map( ( t ) => (
                <li key={ t.key }>
                    { sprintf(
                        /* translators: 1: token name, e.g. Small corner radius, 2: the value it holds, e.g. 1rem */
                        __( '%1$s is %2$s, which the slider below cannot show. Moving that slider replaces the value with a pixel size.', 'fundraising-toolkit' ),
                        t.label,
                        t.value
                    ) }
                </li>
            ) ) }
        </ul>
    );
}
