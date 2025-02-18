import { createRoot } from '@wordpress/element';

import List from './List';
import Toaster from '../_shared/components/Toaster';
import './funds.scss';

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-funds' );
    if ( ! root ) return;
    createRoot( root ).render( <><List /><Toaster /></> );
} );
