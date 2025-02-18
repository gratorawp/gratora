/**
 * Consumer side of the extension-tab seam. Core React apps call
 * useExtensionTabs(surface) to read add-on-registered tabs from the
 * window.dono.tabs registry (defined by ExtensionAssets), and render each via
 * ExtensionTabPanel, which hands the add-on a DOM node + context to mount into.
 */
import { useState, useEffect, useRef } from '@wordpress/element';

const EVENT = 'dono:tabs:changed';

function readTabs( surface ) {
    const reg = ( typeof window !== 'undefined' && window.dono && window.dono.tabs ) || null;
    return reg && typeof reg.get === 'function' ? reg.get( surface ) : [];
}

export function useExtensionTabs( surface ) {
    const [ tabs, setTabs ] = useState( () => readTabs( surface ) );

    useEffect( () => {
        const onChange = ( e ) => {
            if ( ! e.detail || e.detail.surface === surface ) {
                setTabs( readTabs( surface ) );
            }
        };
        window.addEventListener( EVENT, onChange );
        // Catch tabs registered between initial render and this effect.
        setTabs( readTabs( surface ) );
        return () => window.removeEventListener( EVENT, onChange );
    }, [ surface ] );

    return tabs;
}

export function ExtensionTabPanel( { tab, context } ) {
    const ref = useRef( null );

    useEffect( () => {
        if ( ! ref.current || ! tab || typeof tab.mount !== 'function' ) {
            return undefined;
        }
        const cleanup = tab.mount( ref.current, context );
        return () => {
            if ( typeof cleanup === 'function' ) cleanup();
        };
        // Mount once per tab; the add-on owns re-fetching for its own context.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ tab && tab.id ] );

    return <div ref={ ref } className="dono-ext-tab-panel" />;
}
