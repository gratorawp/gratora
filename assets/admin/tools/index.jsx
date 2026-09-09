import { createRoot } from '@wordpress/element';

import Tools from './Tools';
import './tools.scss';

const el = document.getElementById( 'gratora-admin-tools' );
if ( el ) {
    createRoot( el ).render( <Tools /> );
}
