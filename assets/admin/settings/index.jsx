import { createRoot } from '@wordpress/element';

import Settings from './Settings';
import './settings.scss';
// Licenses is a tab here now, so its styles ride the settings bundle.
import '../licenses/licenses.scss';

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-settings' );
    if ( ! root ) return;
    createRoot( root ).render( <Settings /> );
} );
