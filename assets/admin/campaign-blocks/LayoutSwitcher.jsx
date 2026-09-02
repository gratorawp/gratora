/**
 * Swap a campaign page's layout from inside the editor.
 *
 * The list offered is the campaign type's own. A type that lays out its own
 * page has templates built for it, and one of the general ones would replace
 * every block that type exists for.
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
 * Only mounts on a page tied to a campaign, which is the _fundkit_campaign_id
 * meta a campaign's page carries, and only when that campaign's type is one
 * templates can reshape. Every other page opens the same editor.
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
            host.className = 'fundkit-layout-slot';
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
        <span className="fundkit-layout-btn__mark" aria-hidden="true">
            <span /><span /><span />
        </span>
    );
}

function CampaignLayoutButton() {
    const [ picking, setPicking ]   = useState( false );
    const [ applying, setApplying ] = useState( false );

    const campaignId = useSelect(
        ( select ) => select( editorStore )?.getEditedPostAttribute( 'meta' )?._fundkit_campaign_id || 0,
        []
    );

    // A campaign type that lays its own page out owns every block on it, so
    // swapping in a template would delete the thing that type exists for. The
    // server decides, because it is the side that knows the campaign's type.
    const offered = campaignId > 0 && window.fundkitCampaignBlocks?.pageTemplates !== false;

    const { resetBlocks } = useDispatch( blockEditorStore );
    const { editPost } = useDispatch( editorStore );
    const { createNotice } = useDispatch( noticesStore );
    const slot = useHeaderSlot( offered );

    if ( ! offered ) {
        return null;
    }

    const apply = async ( template ) => {
        setApplying( true );
        try {
            const res = await apiFetch( {
                path: `/fundkit/v1/admin/campaigns/${ campaignId }/layout?template=${ encodeURIComponent( template.id ) }`,
            } );

            resetBlocks( parse( res.blocks || '' ) );
            // Recorded on the post rather than sent to the server now, so it is
            // saved with the blocks it belongs to and an organiser who changes
            // their mind before saving leaves nothing behind.
            editPost( { meta: { _fundkit_campaign_page_template: res.template } } );
            setPicking( false );

            createNotice(
                'info',
                __( 'Campaign template applied. Undo puts the old page back.', 'fundraising-toolkit' ),
                { type: 'snackbar' }
            );
        } catch ( err ) {
            createNotice(
                'error',
                err?.message || __( 'The campaign template could not be applied.', 'fundraising-toolkit' ),
                { type: 'snackbar' }
            );
        } finally {
            setApplying( false );
        }
    };

    const button = (
        <Button
            className="fundkit-layout-btn"
            onClick={ () => setPicking( true ) }
            disabled={ applying }
        >
            { applying ? <Spinner /> : <BrandMark /> }
            <span className="fundkit-layout-btn__label">
                { __( 'Campaign templates', 'fundraising-toolkit' ) }
            </span>
        </Button>
    );

    return (
        <>
            { slot ? createPortal( button, slot ) : null }
            { picking && (
                <CampaignTemplatePicker
                    campaignType={ window.fundkitCampaignBlocks?.campaignType || '' }
                    onPick={ apply }
                    onClose={ () => setPicking( false ) }
                />
            ) }
        </>
    );
}

registerPlugin( 'fundkit-campaign-layout', { render: CampaignLayoutButton } );
