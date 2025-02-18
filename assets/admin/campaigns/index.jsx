import { createRoot } from '@wordpress/element';

import { registerDonoEntities } from '../_shared/entities';
import List from './List';
import Detail from './Detail';
import Toaster from '../_shared/components/Toaster';
import './campaigns.scss';

registerDonoEntities();

function App() {
    const params = new URLSearchParams( window.location.search );
    const view = params.get( 'view' );
    const id   = params.get( 'id' );
    const tab  = params.get( 'tab' ) || 'overview';

    if ( view === 'detail' && id ) {
        return <Detail id={ Number( id ) } tab={ tab } />;
    }
    return <List />;
}

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-campaigns' );
    if ( ! root ) return;
    createRoot( root ).render( <><App /><Toaster /></> );
} );
