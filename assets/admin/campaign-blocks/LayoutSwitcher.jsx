/**
 * Swap a campaign page's layout from inside the editor.
 *
 * The layout is only chosen once, when the campaign is created, and until now
 * that choice was permanent. Doing the swap here rather than in the admin
 * screens is what makes it safe to offer: the editor already has undo, so
 * replacing the blocks is a mistake somebody can take back, and they are
 * looking at the page while they do it.
 *
 * The button goes into the editor header by portal rather than through a slot.
 * Two slot routes were tried first and both fail quietly or loudly depending on
 * where the component is imported from, because the pinned-items slot is not
 * dependable to fill from outside core. A portal asks one question with a
 * visible answer: is the header there. If it is not, no button, no error.
 *
 * Only mounts on a page tied to a campaign, which is the _giveflow_campaign_id
 * meta a campaign's page carries. Every other page opens the same editor.
 */

import { useState, useEffect, createPortal } from '@wordpress/element';
import { useSelect, useDispatch } from '@wordpress/data';
import { registerPlugin } from '@wordpress/plugins';
import { store as editorStore } from '@wordpress/editor';
import { store as blockEditorStore } from '@wordpress/block-editor';
import { store as noticesStore } from '@wordpress/notices';
import { parse } from '@wordpress/blocks';
import { Button, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import CampaignTemplatePicker from '../_shared/components/CampaignTemplatePicker';

const HEADER = '.editor-header__settings';

/**
 * A host node at the front of the header's button strip.
 *
 * The header mounts after this plugin does, so it is polled for briefly rather
 * than read once. Giving up quietly is the right failure: the layout can still
 * be changed when the campaign is created.
 */
function useHeaderSlot( enabled ) {
    const [ node, setNode ] = useState( null );

    useEffect( () => {
        if ( ! enabled ) return undefined;

        let host = null;
        let tries = 0;

        const attach = () => {
            const header = document.querySelector( HEADER );
            if ( ! header ) {
                return ++tries < 40;
            }
            host = document.createElement( 'div' );
            host.className = 'giveflow-layout-slot';
            header.prepend( host );
            setNode( host );
            return false;
        };

        if ( ! attach() ) {
            return () => host?.remove();
        }

        const timer = setInterval( () => {
            if ( ! attach() ) clearInterval( timer );
        }, 150 );

        return () => {
            clearInterval( timer );
            host?.remove();
        };
    }, [ enabled ] );

    return node;
}

/** The brand mark: three stacked rules, so the button reads as ours. */
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
        ( select ) => select( editorStore )?.getEditedPostAttribute( 'meta' )?._giveflow_campaign_id || 0,
        []
    );

    const { resetBlocks } = useDispatch( blockEditorStore );
    const { createNotice } = useDispatch( noticesStore );
    const slot = useHeaderSlot( !! campaignId );

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

    const button = (
        <Button
            className="giveflow-layout-btn"
            onClick={ () => setPicking( true ) }
            disabled={ applying }
        >
            { applying ? <Spinner /> : <BrandMark /> }
            <span className="giveflow-layout-btn__label">
                { __( 'Layout', 'giveflow-fundraising-campaigns' ) }
            </span>
        </Button>
    );

    return (
        <>
            { slot ? createPortal( button, slot ) : null }
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
