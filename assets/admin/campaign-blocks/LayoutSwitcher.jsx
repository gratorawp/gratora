/**
 * Swap a campaign page's layout from inside the editor.
 *
 * The layout is only chosen once, when the campaign is created, and until now
 * that choice was permanent. Doing the swap here rather than in the admin
 * screens is what makes it safe to offer: the editor already has undo, so
 * replacing the blocks is a mistake somebody can take back, and they are
 * looking at the page while they do it.
 *
 * Only mounts on a page tied to a campaign, which is the _giveflow_campaign_id
 * meta the campaign's page carries.
 */

import { useState } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import { PluginDocumentSettingPanel, store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { parse } from '@wordpress/blocks';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import CampaignTemplatePicker from '../_shared/components/CampaignTemplatePicker';

function CampaignLayoutPanel() {
    const [ picking, setPicking ] = useState( false );
    const [ applying, setApplying ] = useState( false );

    const campaignId = useSelect(
        ( select ) => select( editorStore ).getEditedPostAttribute( 'meta' )?._giveflow_campaign_id || 0,
        []
    );

    const { resetBlocks } = useDispatch( blockEditorStore );
    const { createNotice } = useDispatch( noticesStore );

    // Every other page in the site opens this editor too. Nothing to offer there.
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
            <PluginDocumentSettingPanel
                name="giveflow-campaign-layout"
                title={ __( 'Campaign layout', 'giveflow-fundraising-campaigns' ) }
                className="giveflow-campaign-layout"
            >
                <p style={ { marginTop: 0, fontSize: 12.5 } }>
                    { __( 'Replace everything on this page with one of the starter layouts. Your own edits go with it, so undo is how you take it back.', 'giveflow-fundraising-campaigns' ) }
                </p>
                <Button
                    variant="secondary"
                    onClick={ () => setPicking( true ) }
                    disabled={ applying }
                    __next40pxDefaultSize
                >
                    { applying
                        ? <><Spinner /> { __( 'Applying', 'giveflow-fundraising-campaigns' ) }</>
                        : __( 'Choose a layout', 'giveflow-fundraising-campaigns' ) }
                </Button>
            </PluginDocumentSettingPanel>

            { picking && (
                <CampaignTemplatePicker
                    onPick={ apply }
                    onClose={ () => setPicking( false ) }
                />
            ) }
        </>
    );
}

registerPlugin( 'giveflow-campaign-layout', { render: CampaignLayoutPanel } );
