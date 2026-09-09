import { useRef } from '@wordpress/element';
import BaseDialog from '@gratora/ui/components/Dialog';

import { useFocusTrap } from '../useFocusTrap';

/**
 * The design system's dialog, with focus management added here rather than
 * forked into the package. display:contents keeps the wrapper out of layout.
 */
export default function Dialog( props ) {
    const ref = useRef( null );
    useFocusTrap( ref );

    return (
        <div ref={ ref } style={ { display: 'contents' } }>
            <BaseDialog { ...props } />
        </div>
    );
}
