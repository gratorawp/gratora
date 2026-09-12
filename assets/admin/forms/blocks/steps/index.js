/**
 * gratora/steps: multi-page wizard container. Children are gratora/step blocks, one
 * wizard page each; without this block the form is single-page. progressStyle
 * picks the navigation treatment: dots, bar, or none.
 */

import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { Button, PanelBody, TextControl } from '@wordpress/components';
import Segmented from '../../../_shared/components/Segmented';
import { useDispatch, useSelect } from '@wordpress/data';
import { __, sprintf } from '@wordpress/i18n';
import { createBlock } from '@wordpress/blocks';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'gratora/steps';
const ALLOWED = [ 'gratora/step' ];

const TEMPLATE = [
    [ 'gratora/step', { title: __( 'Your donation', 'gratora-donation-platform' ) } ],
    [ 'gratora/step', { title: __( 'Your info', 'gratora-donation-platform' ) } ],
];

const PROGRESS_STYLES = [
    { value: 'dots', label: __( 'Dots',   'gratora-donation-platform' ) },
    { value: 'bar',  label: __( 'Bar',    'gratora-donation-platform' ) },
    { value: 'none', label: __( 'None',   'gratora-donation-platform' ) },
];

const PROGRESS_HELP = {
    dots: __( 'Centered dots beneath the form.', 'gratora-donation-platform' ),
    bar:  __( 'Header bar with back arrow + title + progress fill.', 'gratora-donation-platform' ),
    none: __( 'No progress indicator.', 'gratora-donation-platform' ),
};

function Edit( { attributes, setAttributes, clientId } ) {
    const { prevLabel = '', nextLabel = '', progressStyle = 'dots' } = attributes;
    const blockProps = useBlockProps( {
        className: `gratora-block-preview gratora-block-preview--steps gratora-block-preview--steps-${ progressStyle }`,
    } );

    const { insertBlock } = useDispatch( 'core/block-editor' );
    const childCount = useSelect(
        ( select ) => select( 'core/block-editor' ).getBlockOrder( clientId ).length,
        [ clientId ]
    );

    const addStep = () => {
        const next = createBlock( 'gratora/step', {
            title: sprintf(
                /* translators: %d: new step number. */
                __( 'Step %d', 'gratora-donation-platform' ),
                childCount + 1
            ),
        } );
        insertBlock( next, childCount, clientId, false );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Wizard navigation', 'gratora-donation-platform' ) } initialOpen>
                    <Segmented
                        label={ __( 'Progress style', 'gratora-donation-platform' ) }
                        value={ progressStyle }
                        onChange={ ( v ) => setAttributes( { progressStyle: v } ) }
                        options={ PROGRESS_STYLES }
                        help={ PROGRESS_HELP[ progressStyle ] }
                    />
                    <TextControl
                        label={ __( 'Back-button label', 'gratora-donation-platform' ) }
                        value={ prevLabel }
                        onChange={ ( v ) => setAttributes( { prevLabel: v } ) }
                        placeholder={ __( 'Back', 'gratora-donation-platform' ) }
                        help={ progressStyle === 'bar'
                            ? __( 'Used as the aria-label on the back arrow.', 'gratora-donation-platform' )
                            : undefined
                        }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Next-button label', 'gratora-donation-platform' ) }
                        value={ nextLabel }
                        onChange={ ( v ) => setAttributes( { nextLabel: v } ) }
                        placeholder={ __( 'Continue', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <InnerBlocks
                    allowedBlocks={ ALLOWED }
                    template={ TEMPLATE }
                    templateInsertUpdatesSelection={ false }
                    renderAppender={ false }
                />
                <div className="gratora-block-preview__steps-add">
                    <Button variant="secondary" onClick={ addStep }>
                        { __( '+ Add step', 'gratora-donation-platform' ) }
                    </Button>
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Steps', 'gratora-donation-platform' ),
        description: __( 'Split the form into pages a donor clicks through. Add a Step inside to make a new page.', 'gratora-donation-platform' ),
        category:    'gratora-content',
        icon:        BlockIcons[ 'steps' ],
        supports:    { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            prevLabel:     { type: 'string', default: '' },
            nextLabel:     { type: 'string', default: '' },
            progressStyle: { type: 'string', default: 'dots' },
        },
        edit: Edit,
        save: () => <InnerBlocks.Content />,
    } );
}
