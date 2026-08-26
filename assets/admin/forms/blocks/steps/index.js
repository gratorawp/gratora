/**
 * giveflow/steps: multi-page wizard container. Children are giveflow/step blocks, one
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

const NAME = 'giveflow/steps';
const ALLOWED = [ 'giveflow/step' ];

const TEMPLATE = [
    [ 'giveflow/step', { title: __( 'Your donation', 'giveflow-fundraising-campaigns' ) } ],
    [ 'giveflow/step', { title: __( 'Your info', 'giveflow-fundraising-campaigns' ) } ],
];

const PROGRESS_STYLES = [
    { value: 'dots', label: __( 'Dots',   'giveflow-fundraising-campaigns' ) },
    { value: 'bar',  label: __( 'Bar',    'giveflow-fundraising-campaigns' ) },
    { value: 'none', label: __( 'None',   'giveflow-fundraising-campaigns' ) },
];

const PROGRESS_HELP = {
    dots: __( 'Centered dots beneath the form.', 'giveflow-fundraising-campaigns' ),
    bar:  __( 'Header bar with back arrow + title + progress fill.', 'giveflow-fundraising-campaigns' ),
    none: __( 'No progress indicator.', 'giveflow-fundraising-campaigns' ),
};

function Edit( { attributes, setAttributes, clientId } ) {
    const { prevLabel = '', nextLabel = '', progressStyle = 'dots' } = attributes;
    const blockProps = useBlockProps( {
        className: `giveflow-block-preview giveflow-block-preview--steps giveflow-block-preview--steps-${ progressStyle }`,
    } );

    const { insertBlock } = useDispatch( 'core/block-editor' );
    const childCount = useSelect(
        ( select ) => select( 'core/block-editor' ).getBlockOrder( clientId ).length,
        [ clientId ]
    );

    const addStep = () => {
        const next = createBlock( 'giveflow/step', {
            title: sprintf(
                /* translators: %d: new step number. */
                __( 'Step %d', 'giveflow-fundraising-campaigns' ),
                childCount + 1
            ),
        } );
        insertBlock( next, childCount, clientId, false );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Wizard navigation', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <Segmented
                        label={ __( 'Progress style', 'giveflow-fundraising-campaigns' ) }
                        value={ progressStyle }
                        onChange={ ( v ) => setAttributes( { progressStyle: v } ) }
                        options={ PROGRESS_STYLES }
                        help={ PROGRESS_HELP[ progressStyle ] }
                    />
                    <TextControl
                        label={ __( 'Back-button label', 'giveflow-fundraising-campaigns' ) }
                        value={ prevLabel }
                        onChange={ ( v ) => setAttributes( { prevLabel: v } ) }
                        placeholder={ __( 'Back', 'giveflow-fundraising-campaigns' ) }
                        help={ progressStyle === 'bar'
                            ? __( 'Used as the aria-label on the back arrow.', 'giveflow-fundraising-campaigns' )
                            : undefined
                        }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Next-button label', 'giveflow-fundraising-campaigns' ) }
                        value={ nextLabel }
                        onChange={ ( v ) => setAttributes( { nextLabel: v } ) }
                        placeholder={ __( 'Continue', 'giveflow-fundraising-campaigns' ) }
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
                <div className="giveflow-block-preview__steps-add">
                    <Button variant="secondary" onClick={ addStep }>
                        { __( '+ Add step', 'giveflow-fundraising-campaigns' ) }
                    </Button>
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Steps', 'giveflow-fundraising-campaigns' ),
        description: __( 'Split the form into pages a donor clicks through. Add a Step inside to make a new page.', 'giveflow-fundraising-campaigns' ),
        category:    'giveflow-content',
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
