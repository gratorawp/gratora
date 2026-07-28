import { useState } from '@wordpress/element';

/**
 * Open state for a collapsible Card that knows when it wants attention.
 *
 * Follows `needsAttention` until the operator clicks the head, after which
 * their choice sticks. Status arrives async, so a plain defaultOpen would be
 * read before the card knows whether anything is wrong.
 */
export default function useCardOpen( needsAttention ) {
    const [ pinned, setPinned ] = useState( null );

    return [ pinned === null ? !! needsAttention : pinned, setPinned ];
}
