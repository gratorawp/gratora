// Dono admin: Donations. Two views: list + detail, routed via URLSearchParams.

import { createRoot } from '@wordpress/element';

import List from './List';
import Detail from './Detail';
import Toaster from '../_shared/components/Toaster';
import '../campaigns/campaigns.scss';

function App() {
    const params = new URLSearchParams( window.location.search );
    const view = params.get( 'view' );
    const reference = params.get( 'reference' );

    if ( view === 'detail' && reference ) {
        return <Detail reference={ reference } />;
    }
    return <List />;
}

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-donations' );
    if ( ! root ) return;
    createRoot( root ).render( <><App /><Toaster /></> );
} );
