// Gratora admin: Donations. Two views: list + detail, routed via URLSearchParams.

import { createRoot } from '@wordpress/element';

import List from './List';
import Detail from './Detail';
import Trash from './Trash';
import Toaster from '../_shared/components/Toaster';
// Restores the template picker, the notices, the toaster and the shared
// field styles, which live in that file rather than in a shared partial.
import '../campaigns/campaigns.scss';

function App() {
    const params = new URLSearchParams( window.location.search );
    const view = params.get( 'view' );
    const reference = params.get( 'reference' );

    if ( view === 'detail' && reference ) {
        return <Detail reference={ reference } />;
    }
    if ( view === 'trash' ) {
        return <Trash />;
    }
    return <List />;
}

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'gratora-admin-donations' );
    if ( ! root ) return;
    createRoot( root ).render( <><App /><Toaster /></> );
} );
