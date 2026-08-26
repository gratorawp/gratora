/**
 * Consumer side of the extension-tab seam. Core React apps call
 * useExtensionTabs(surface) to read add-on-registered tabs from the
 * window.giveflow.tabs registry (defined by ExtensionAssets), and render each via
 * ExtensionTabPanel, which hands the add-on a DOM node + context to mount into.
 */
import { useState, useEffect, useRef } from '@wordpress/element';

const EVENT       = 'giveflow:tabs:changed';
const PANEL_EVENT = 'giveflow:panels:changed';

function readTabs( surface ) {
    const reg = ( typeof window !== 'undefined' && window.giveflow && window.giveflow.tabs ) || null;
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

function readPanels( surface ) {
    const reg = ( typeof window !== 'undefined' && window.giveflow && window.giveflow.panels ) || null;
    return reg && typeof reg.get === 'function' ? reg.get( surface ) : [];
}

/**
 * Sections an add-on adds inside an existing screen, as opposed to a whole tab.
 * The portal has had this since the tributes extraction; this is the same
 * contract for the React admin.
 */
export function useExtensionPanels( surface ) {
    const [ panels, setPanels ] = useState( () => readPanels( surface ) );

    useEffect( () => {
        const onChange = ( e ) => {
            if ( ! e.detail || e.detail.surface === surface ) {
                setPanels( readPanels( surface ) );
            }
        };
        window.addEventListener( PANEL_EVENT, onChange );
        setPanels( readPanels( surface ) );
        return () => window.removeEventListener( PANEL_EVENT, onChange );
    }, [ surface ] );

    return panels;
}

/**
 * Remounted when the record it describes changes, so a panel showing one
 * donor's state cannot be left on screen showing it for another.
 */
export function ExtensionSection( { panel, context, token } ) {
    const ref = useRef( null );

    useEffect( () => {
        if ( ! ref.current || ! panel || typeof panel.mount !== 'function' ) {
            return undefined;
        }
        const cleanup = panel.mount( ref.current, context );
        return () => {
            if ( typeof cleanup === 'function' ) cleanup();
        };
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [ panel && panel.id, token ] );

    return <div ref={ ref } className="giveflow-ext-panel" />;
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

    return <div ref={ ref } className="giveflow-ext-tab-panel" />;
}
