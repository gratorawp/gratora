import { createRoot } from '@wordpress/element';

import Licenses from './Licenses';
import './licenses.scss';

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-licenses' );
    if ( ! root ) return;
    createRoot( root ).render( <Licenses /> );
} );
