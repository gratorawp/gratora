import { useSelect } from '@wordpress/data';
import { useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import { ComboboxControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import Notice from '../_shared/components/Notice';

// Read per render, not once at module scope: the payload is inlined before the
// bundle, but a stale global must not decide this for a whole session.
export const canManageCampaigns = () => !! ( window.gratoraCampaignBlocks || {} ).canManageCampaigns;

export function useBoundCampaign( campaignId ) {
    const postMetaId = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        if ( ! editor || ! editor.getEditedPostAttribute ) return 0;
        const meta = editor.getEditedPostAttribute( 'meta' ) || {};
        return Number( meta._gratora_campaign_id || 0 );
    }, [] );

    const resolvedId = campaignId || postMetaId || 0;
    const { record, hasResolved } = useEntityRecord( 'gratora/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );

    // Hide the picker only when the bound campaign exists. Distinguish missing campaigns from
    // permission failures.
    const orphaned = canManageCampaigns() && postMetaId > 0 && hasResolved && ! record;

    // Blocks on a campaign landing page inherit its campaign, so there is
    // nothing to pick. Undecided while the lookup is in flight, or the picker
    // flashes in and out on every load.
    const onCampaignPage = postMetaId > 0 && ! orphaned;

    return { campaign: record, onCampaignPage, resolvedId };
}

export function CampaignPicker( { value, onChange, noneLabel } ) {
    // Narrowed on the server: a page of a hundred cannot hold every campaign,
    // and one outside it could not be picked at all.
    const [ search, setSearch ] = useState( '' );
    const { records } = useEntityRecords( 'gratora/v1', 'campaign', {
        per_page: 100,
        orderby:  'title',
        order:    'asc',
        search:   search || undefined,
    } );
    // useEntityRecords yields `records: null` until the fetch resolves, and a
    // destructuring default only replaces `undefined`, so this guard is what
    // keeps the inspector from throwing on selection.
    const campaigns = Array.isArray( records ) ? records : [];
    // A refused list resolves to null, never [], so the count alone cannot tell
    // "nothing to show" from "not allowed to look", and the sentence written
    // for this reader was unreachable.
    const empty = ! canManageCampaigns()
        || ( Array.isArray( records ) && records.length === 0 && ! search );

    // Resolved on its own so a block bound to a campaign outside the current
    // page still reads as bound rather than as unset.
    const { record: bound } = useEntityRecord( 'gratora/v1', 'campaign', value || 0, {
        enabled: Number( value ) > 0,
    } );

    const options = [ { value: 0, label: noneLabel || __( 'Select a campaign', 'gratora-donation-platform' ) } ];
    const seen    = new Set( [ 0 ] );
    for ( const c of [ ...( bound ? [ bound ] : [] ), ...campaigns ] ) {
        if ( seen.has( c.id ) ) continue;
        seen.add( c.id );
        options.push( { value: c.id, label: c.title } );
    }

    return (
        <>
            <ComboboxControl
                label={ __( 'Campaign', 'gratora-donation-platform' ) }
                value={ Number( value ) || 0 }
                options={ options }
                onFilterValueChange={ setSearch }
                onChange={ ( v ) => onChange( Number( v ) || 0 ) }
                __nextHasNoMarginBottom
            />
            { empty && (
                <Notice status="warning" isDismissible={ false }>
                    { canManageCampaigns()
                        ? __( 'No campaigns have been created yet.', 'gratora-donation-platform' )
                        : __( 'You do not have permission to list campaigns, so this block can only use the one the page is already for.', 'gratora-donation-platform' ) }
                </Notice>
            ) }
        </>
    );
}
