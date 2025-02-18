import { createRoot } from '@wordpress/element';

import { registerDonoEntities } from '../_shared/entities';
import Toaster from '../_shared/components/Toaster';
import Editor from './Editor';

registerDonoEntities();

function App() {
    const params = new URLSearchParams( window.location.search );
    const formId = params.get( 'form' );
    return formId ? <Editor formId={ Number( formId ) } /> : null;
}

document.addEventListener( 'DOMContentLoaded', () => {
    const root = document.getElementById( 'dono-admin-forms' );
    if ( ! root ) return;
    createRoot( root ).render( <><App /><Toaster /></> );
} );
