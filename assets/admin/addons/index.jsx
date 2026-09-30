import { createRoot } from '@wordpress/element';

import Addons from './Addons';
import './addons.scss';

const el = document.getElementById( 'gratora-admin-addons' );
if ( el ) {
    createRoot( el ).render( <Addons addons={ window.gratoraAddons?.addons || [] } /> );
}
