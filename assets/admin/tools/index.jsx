import { createRoot } from '@wordpress/element';

import Tools from './Tools';
import './tools.scss';

const el = document.getElementById( 'dono-admin-tools' );
if ( el ) {
    createRoot( el ).render( <Tools /> );
}
