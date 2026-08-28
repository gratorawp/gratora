/**
 * Swap a campaign page's layout from inside the editor.
 *
 * The layout is only chosen once, when the campaign is created, and until now
 * that choice was permanent. Doing the swap here rather than in the admin
 * screens is what makes it safe to offer: the editor already has undo, so
 * replacing the blocks is a mistake somebody can take back, and they are
 * looking at the page while they do it.
 *
 * Sits in the editor header beside the view and Save buttons, filling the
 * pinned-items slot the editor puts there. Taken from @wordpress/editor and
 * not @wordpress/interface: WordPress ships no wp-interface script, so that
 * import bundles a second copy of the slot registry and the fill never finds
 * the editor's slot. Not a sidebar: this is one action,
 * and a panel to hold one button is a door where a button would do.
 *
 * Only mounts on a page tied to a campaign, which is the _giveflow_campaign_id
 * meta a campaign's page carries. Every other page opens the same editor.
 */

import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import { PinnedItems, store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { parse } from '@wordpress/blocks';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import CampaignTemplatePicker from '../_shared/components/CampaignTemplatePicker';

/**
 * The brand mark, three stacked rules.
 *
 * Drawn rather than taken from the icon set so the button reads as ours among
 * a row of WordPress's own monochrome icons, which is the whole point of it
 * looking different.
 */
function BrandMark() {
    return (
        <span className="giveflow-layout-btn__mark" aria-hidden="true">
            <span /><span /><span />
        </span>
    );
}

function CampaignLayoutButton() {
    const [ picking, setPicking ]   = useState( false );
    const [ applying, setApplying ] = useState( false );

    const campaignId = useSelect(
        ( select ) => select( editorStore ).getEditedPostAttribute( 'meta' )?._giveflow_campaign_id || 0,
        []
    );

    const { resetBlocks } = useDispatch( blockEditorStore );
    const { createNotice } = useDispatch( noticesStore );

    if ( ! campaignId ) {
        return null;
    }

    const apply = async ( template ) => {
        setApplying( true );
        try {
            const res = await apiFetch( {
                path: `/giveflow/v1/admin/campaigns/${ campaignId }/layout?template=${ encodeURIComponent( template.id ) }`,
            } );

            resetBlocks( parse( res.blocks || '' ) );
            setPicking( false );

            createNotice(
                'info',
                __( 'Layout replaced. Undo puts the old one back.', 'giveflow-fundraising-campaigns' ),
                { type: 'snackbar' }
            );
        } catch ( err ) {
            createNotice(
                'error',
                err?.message || __( 'The layout could not be applied.', 'giveflow-fundraising-campaigns' ),
                { type: 'snackbar' }
            );
        } finally {
            setApplying( false );
        }
    };

    return (
        <>
            <PinnedItems scope="core">
                <Button
                    className="giveflow-layout-btn"
                    onClick={ () => setPicking( true ) }
                    disabled={ applying }
                    label={ __( 'Change the campaign page layout', 'giveflow-fundraising-campaigns' ) }
                    showTooltip
                >
                    { applying ? <Spinner /> : <BrandMark /> }
                    <span className="giveflow-layout-btn__label">
                        { __( 'Layout', 'giveflow-fundraising-campaigns' ) }
                    </span>
                </Button>
            </PinnedItems>

            { picking && (
                <CampaignTemplatePicker
                    onPick={ apply }
                    onClose={ () => setPicking( false ) }
                />
            ) }
        </>
    );
}

registerPlugin( 'giveflow-campaign-layout', { render: CampaignLayoutButton } );
