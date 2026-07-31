import { registerBlockBindingsSource } from '@wordpress/blocks';
import { store as coreStore } from '@wordpress/core-data';
import { dispatch, select as dataSelect } from '@wordpress/data';
import { __ } from '@wordpress/i18n';

/**
 * Client-side half of the dono/campaign binding source.
 *
 * PHP registers the source, but the editor resolves bindings on the client and
 * cannot call a PHP callback. Registering only server-side leaves a bound block
 * showing the source's label, "Dono campaign", locked and unreadable, so an
 * organiser would be composing a page they cannot see.
 *
 * The values come from the server, computed by the same code that renders the
 * page, so the preview cannot drift from the result.
 */

const ENTITY_KIND = 'dono/v1';
const ENTITY_NAME = 'campaign-binding-preview';

function ensureEntity() {
    const sel = dataSelect( coreStore );
    if ( sel?.getEntityConfig?.( ENTITY_KIND, ENTITY_NAME ) ) {
        return;
    }
    dispatch( coreStore ).addEntities( [
        {
            kind: ENTITY_KIND,
            name: ENTITY_NAME,
            baseURL: '/dono/v1/campaign-binding-preview',
            label: __( 'Dono campaign binding preview', 'dono' ),
        },
    ] );
}

/**
 * Reading through getEntityRecord keeps getValues synchronous: the first call
 * returns nothing and schedules the fetch, and the editor re-renders when the
 * record arrives.
 */
function preview( select, context ) {
    const postId = context?.postId;
    if ( ! postId ) {
        return null;
    }
    return select( coreStore ).getEntityRecord( ENTITY_KIND, ENTITY_NAME, postId ) || null;
}

export function registerCampaignBindingSource( fields ) {
    ensureEntity();

    registerBlockBindingsSource( {
        name: 'dono/campaign',
        label: __( 'Dono campaign', 'dono' ),
        usesContext: [ 'postId' ],
        getValues: ( { select, context, bindings } ) => {
            const record = preview( select, context );
            const values = {};

            for ( const [ attribute, binding ] of Object.entries( bindings || {} ) ) {
                const key = binding?.args?.key;
                if ( ! key ) {
                    continue;
                }
                // Undefined while the record loads, so the block shows its own
                // value rather than flashing empty.
                values[ attribute ] = record?.campaign?.[ key ] ?? undefined;
            }

            return values;
        },
        // A list, not a map: the editor finds the field for a binding by
        // deep-comparing its args against each item's.
        getFieldsList: ( { select, context } ) => {
            const record = preview( select, context );

            return Object.entries( fields || {} ).map( ( [ key, label ] ) => ( {
                key,
                args: { key },
                label,
                value: record?.campaign?.[ key ] ?? '',
            } ) );
        },
        // The campaign owns its own title and totals. The organiser owns where
        // they sit on the page, not what they say.
        canUserEditValue: () => false,
    } );
}
