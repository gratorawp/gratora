import { createRoot } from '@wordpress/element';

import Settings from './Settings';
import './settings.scss';

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-settings' );
    if ( ! root ) return;
    createRoot( root ).render( <Settings /> );
} );
