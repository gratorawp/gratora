/**
 * gratora/step: one page inside a gratora/steps wizard. Hidden from the inserter;
 * authors add steps via the parent's toolbar.
 */

import { useBlockProps, InspectorControls, InnerBlocks, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, Notice } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'gratora/step';

function Edit( { attributes, setAttributes, clientId } ) {
    const { title = '', showTitle = true } = attributes;
    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--step' } );

    const { index, total, childCount } = useSelect( ( select ) => {
        const { getBlockRootClientId, getBlockOrder } = select( 'core/block-editor' );
        const parentId = getBlockRootClientId( clientId );
        const siblings = parentId ? getBlockOrder( parentId ) : [];
        return {
            index: Math.max( 0, siblings.indexOf( clientId ) ),
            total: siblings.length,
            childCount: getBlockOrder( clientId ).length,
        };
    }, [ clientId ] );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Step', 'gratora' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'gratora' ) }
                        value={ title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        help={ __( 'Shown as the page title and on the progress indicator.', 'gratora' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show label', 'gratora' ) }
                        checked={ showTitle }
                        onChange={ ( v ) => setAttributes( { showTitle: v } ) }
                        help={ __( 'Off hides the label on the donor form. The progress indicator still uses it for screen-reader names.', 'gratora' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <div className="gratora-block-preview__step-meta">
                    { sprintf(
                        /* translators: %1$d: current step number. %2$d: total number of steps. */
                        __( 'Step %1$d of %2$d', 'gratora' ),
                        index + 1,
                        total
                    ) }
                </div>
                { showTitle && (
                    <RichText
                        tagName="h3"
                        className="gratora-block-preview__step-title"
                        value={ title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Untitled step', 'gratora' ) }
                        allowedFormats={ [] }
                    />
                ) }
                { childCount === 0 && (
                    <Notice status="warning" isDismissible={ false }>
                        { __( 'This step is empty. Add fields or content, or remove the step, so donors do not land on a blank page.', 'gratora' ) }
                    </Notice>
                ) }
                <InnerBlocks
                    renderAppender={ InnerBlocks.ButtonBlockAppender }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Step', 'gratora' ),
        description: __( 'One page inside a Steps wizard.', 'gratora' ),
        category:    'gratora-content',
        icon:        BlockIcons[ 'step' ],
        parent:      [ 'gratora/steps' ],
        supports:    { html: false, anchor: false, inserter: false },
        attributes: {
            title:     { type: 'string',  default: '' },
            showTitle: { type: 'boolean', default: true },
        },
        edit: Edit,
        save: () => <InnerBlocks.Content />,
    } );
}
