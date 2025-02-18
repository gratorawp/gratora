import { createRoot } from '@wordpress/element';

import { registerDonoEntities } from '../_shared/entities';
import Dashboard from './Dashboard';
import './dashboard.scss';

registerDonoEntities();

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-dashboard' );
    if ( ! root ) return;
    createRoot( root ).render( <Dashboard /> );
} );
