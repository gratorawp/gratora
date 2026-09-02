/**
 * fundkit/steps: multi-page wizard container. Children are fundkit/step blocks, one
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

const NAME = 'fundkit/steps';
const ALLOWED = [ 'fundkit/step' ];

const TEMPLATE = [
    [ 'fundkit/step', { title: __( 'Your donation', 'fundraising-toolkit' ) } ],
    [ 'fundkit/step', { title: __( 'Your info', 'fundraising-toolkit' ) } ],
];

const PROGRESS_STYLES = [
    { value: 'dots', label: __( 'Dots',   'fundraising-toolkit' ) },
    { value: 'bar',  label: __( 'Bar',    'fundraising-toolkit' ) },
    { value: 'none', label: __( 'None',   'fundraising-toolkit' ) },
];

const PROGRESS_HELP = {
    dots: __( 'Centered dots beneath the form.', 'fundraising-toolkit' ),
    bar:  __( 'Header bar with back arrow + title + progress fill.', 'fundraising-toolkit' ),
    none: __( 'No progress indicator.', 'fundraising-toolkit' ),
};

function Edit( { attributes, setAttributes, clientId } ) {
    const { prevLabel = '', nextLabel = '', progressStyle = 'dots' } = attributes;
    const blockProps = useBlockProps( {
        className: `fundkit-block-preview fundkit-block-preview--steps fundkit-block-preview--steps-${ progressStyle }`,
    } );

    const { insertBlock } = useDispatch( 'core/block-editor' );
    const childCount = useSelect(
        ( select ) => select( 'core/block-editor' ).getBlockOrder( clientId ).length,
        [ clientId ]
    );

    const addStep = () => {
        const next = createBlock( 'fundkit/step', {
            title: sprintf(
                /* translators: %d: new step number. */
                __( 'Step %d', 'fundraising-toolkit' ),
                childCount + 1
            ),
        } );
        insertBlock( next, childCount, clientId, false );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Wizard navigation', 'fundraising-toolkit' ) } initialOpen>
                    <Segmented
                        label={ __( 'Progress style', 'fundraising-toolkit' ) }
                        value={ progressStyle }
                        onChange={ ( v ) => setAttributes( { progressStyle: v } ) }
                        options={ PROGRESS_STYLES }
                        help={ PROGRESS_HELP[ progressStyle ] }
                    />
                    <TextControl
                        label={ __( 'Back-button label', 'fundraising-toolkit' ) }
                        value={ prevLabel }
                        onChange={ ( v ) => setAttributes( { prevLabel: v } ) }
                        placeholder={ __( 'Back', 'fundraising-toolkit' ) }
                        help={ progressStyle === 'bar'
                            ? __( 'Used as the aria-label on the back arrow.', 'fundraising-toolkit' )
                            : undefined
                        }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Next-button label', 'fundraising-toolkit' ) }
                        value={ nextLabel }
                        onChange={ ( v ) => setAttributes( { nextLabel: v } ) }
                        placeholder={ __( 'Continue', 'fundraising-toolkit' ) }
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
                <div className="fundkit-block-preview__steps-add">
                    <Button variant="secondary" onClick={ addStep }>
                        { __( '+ Add step', 'fundraising-toolkit' ) }
                    </Button>
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Steps', 'fundraising-toolkit' ),
        description: __( 'Split the form into pages a donor clicks through. Add a Step inside to make a new page.', 'fundraising-toolkit' ),
        category:    'fundkit-content',
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
