import { createRoot } from '@wordpress/element';

import { registerDonoEntities } from '../_shared/entities';
import Toaster from '../_shared/components/Toaster';
import Dashboard from './Dashboard';
import './dashboard.scss';

registerDonoEntities();

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-dashboard' );
    if ( ! root ) return;
    // Toaster renders notify.* output (e.g. the New-campaign error); without it
    // those notifications fire into a store with no renderer and vanish.
    createRoot( root ).render( <><Dashboard /><Toaster /></> );
} );
