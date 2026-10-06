/** @jsxImportSource preact */

import { emptyMessage } from '../util/gateways';

/**
 * Why the form cannot take a donation. A donor reads the neutral sentence. The
 * person who manages the plugin, on a form with no method at all, reads the
 * reason and where to put it right: the server sends them that and nobody else.
 */
export default function NoGateway( { config, state } ) {
    const owner   = config && config.ownerNotice;
    const methods = config && config.gateways && Array.isArray( config.gateways.options ) ? config.gateways.options : [];

    if ( owner && ! methods.length ) {
        return (
            <div class="gratora-form__gateways-empty" role="alert">
                { owner.text }
                { ' ' }
                <a href={ owner.linkUrl }>{ owner.linkLabel }</a>
            </div>
        );
    }

    return <div class="gratora-form__gateways-empty" role="alert">{ emptyMessage( config, state ) }</div>;
}
