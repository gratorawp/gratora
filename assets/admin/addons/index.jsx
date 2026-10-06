import { createRoot } from '@wordpress/element';

import Addons from './Addons';
import './addons.scss';

const el = document.getElementById( 'gratora-admin-addons' );
if ( el ) {
    const { addons = [], plans, offer, source } = window.gratoraAddons || {};

    createRoot( el ).render( <Addons addons={ addons } plans={ plans } offer={ offer } source={ source } /> );
}
