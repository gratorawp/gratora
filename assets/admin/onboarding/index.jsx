import { createRoot } from '@wordpress/element';

import Onboarding from './Onboarding';
import './onboarding.scss';

const mount = document.getElementById( 'dono-admin-onboarding' );
if ( mount ) {
    createRoot( mount ).render( <Onboarding /> );
}
