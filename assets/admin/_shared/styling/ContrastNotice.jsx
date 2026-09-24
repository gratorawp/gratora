import { __, sprintf } from '@wordpress/i18n';

import { bestOn } from '../../../_shared/ink';

/**
 * A ground between light and dark carries no body text whichever ink is drawn
 * on it, and a colour picker cannot show that. The text colours follow the
 * ground on their own, so this is the one choice the org has to make itself.
 */
const GROUNDS = [
    [ 'gratora-bg',      () => __( 'Background', 'gratora-donation-platform' ) ],
    [ 'gratora-bg-soft', () => __( 'Soft background', 'gratora-donation-platform' ) ],
    [ 'gratora-field-bg', () => __( 'Field background', 'gratora-donation-platform' ) ],
    [ 'gratora-accent',  () => __( 'Accent', 'gratora-donation-platform' ) ],
];

export default function ContrastNotice( { tokens } ) {
    const failing = GROUNDS
        .map( ( [ key, label ] ) => ( { label: label(), value: tokens[ key ], best: bestOn( tokens[ key ] ) } ) )
        .filter( ( g ) => g.best !== null && g.best < 4.5 );

    if ( ! failing.length ) return null;

    return (
        <ul className="gratora-preset-editor__contrast">
            { failing.map( ( g ) => (
                <li key={ g.label }>
                    { sprintf(
                        /* translators: 1: colour name, e.g. Background, 2: the hex the admin picked, 3: the contrast it reaches, e.g. 4.0 */
                        __( '%1$s (%2$s) reaches %3$s:1, under the 4.5:1 that text needs. Take it lighter or darker.', 'gratora-donation-platform' ),
                        g.label,
                        String( g.value ).toUpperCase(),
                        // Floored: 4.49 rounds to the very 4.5 it misses.
                        ( Math.floor( g.best * 10 ) / 10 ).toFixed( 1 )
                    ) }
                </li>
            ) ) }
        </ul>
    );
}
