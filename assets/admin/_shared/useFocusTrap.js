import { useEffect } from '@wordpress/element';

export const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select:not([disabled]),textarea:not([disabled]),[tabindex]:not([tabindex="-1"])';

/**
 * Keeps Tab inside a modal and puts focus back where it came from.
 *
 * No Escape handling: the shared Dialog already binds it, and a second handler
 * would close twice.
 */
export function useFocusTrap( ref ) {
    useEffect( () => {
        const doc  = ( ref.current && ref.current.ownerDocument ) || document;
        const prev = doc.activeElement;

        const panel = () => ( ref.current
            ? ref.current.querySelector( '.gratora-dialog' ) || ref.current
            : null );

        const first = panel() && panel().querySelector( FOCUSABLE );
        if ( first ) first.focus();

        const onKey = ( e ) => {
            if ( e.key !== 'Tab' ) return;
            const node = panel();
            if ( ! node ) return;

            const nodes = [ ...node.querySelectorAll( FOCUSABLE ) ];
            if ( ! nodes.length ) return;

            if ( e.shiftKey && doc.activeElement === nodes[ 0 ] ) {
                e.preventDefault();
                nodes[ nodes.length - 1 ].focus();
            } else if ( ! e.shiftKey && doc.activeElement === nodes[ nodes.length - 1 ] ) {
                e.preventDefault();
                nodes[ 0 ].focus();
            }
        };

        // On the document: focus that has already escaped the panel still has
        // to be brought back.
        doc.addEventListener( 'keydown', onKey );

        return () => {
            doc.removeEventListener( 'keydown', onKey );
            if ( prev && typeof prev.focus === 'function' && doc.contains( prev ) ) prev.focus();
        };
    }, [] ); // eslint-disable-line react-hooks/exhaustive-deps
}
