/**
 * dono/steps: multi-page wizard container. Children are dono/step blocks, one
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

const NAME = 'dono/steps';
const ALLOWED = [ 'dono/step' ];

const TEMPLATE = [
    [ 'dono/step', { title: __( 'Your donation', 'dono-fundraising-platform' ) } ],
    [ 'dono/step', { title: __( 'Your info', 'dono-fundraising-platform' ) } ],
];

const PROGRESS_STYLES = [
    { value: 'dots', label: __( 'Dots',   'dono-fundraising-platform' ) },
    { value: 'bar',  label: __( 'Bar',    'dono-fundraising-platform' ) },
    { value: 'none', label: __( 'None',   'dono-fundraising-platform' ) },
];

const PROGRESS_HELP = {
    dots: __( 'Centered dots beneath the form.', 'dono-fundraising-platform' ),
    bar:  __( 'Header bar with back arrow + title + progress fill.', 'dono-fundraising-platform' ),
    none: __( 'No progress indicator.', 'dono-fundraising-platform' ),
};

function Edit( { attributes, setAttributes, clientId } ) {
    const { prevLabel = '', nextLabel = '', progressStyle = 'dots' } = attributes;
    const blockProps = useBlockProps( {
        className: `dono-block-preview dono-block-preview--steps dono-block-preview--steps-${ progressStyle }`,
    } );

    const { insertBlock } = useDispatch( 'core/block-editor' );
    const childCount = useSelect(
        ( select ) => select( 'core/block-editor' ).getBlockOrder( clientId ).length,
        [ clientId ]
    );

    const addStep = () => {
        const next = createBlock( 'dono/step', {
            title: sprintf(
                /* translators: %d: new step number. */
                __( 'Step %d', 'dono-fundraising-platform' ),
                childCount + 1
            ),
        } );
        insertBlock( next, childCount, clientId, false );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Wizard navigation', 'dono-fundraising-platform' ) } initialOpen>
                    <Segmented
                        label={ __( 'Progress style', 'dono-fundraising-platform' ) }
                        value={ progressStyle }
                        onChange={ ( v ) => setAttributes( { progressStyle: v } ) }
                        options={ PROGRESS_STYLES }
                        help={ PROGRESS_HELP[ progressStyle ] }
                    />
                    <TextControl
                        label={ __( 'Back-button label', 'dono-fundraising-platform' ) }
                        value={ prevLabel }
                        onChange={ ( v ) => setAttributes( { prevLabel: v } ) }
                        placeholder={ __( 'Back', 'dono-fundraising-platform' ) }
                        help={ progressStyle === 'bar'
                            ? __( 'Used as the aria-label on the back arrow.', 'dono-fundraising-platform' )
                            : undefined
                        }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Next-button label', 'dono-fundraising-platform' ) }
                        value={ nextLabel }
                        onChange={ ( v ) => setAttributes( { nextLabel: v } ) }
                        placeholder={ __( 'Continue', 'dono-fundraising-platform' ) }
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
                <div className="dono-block-preview__steps-add">
                    <Button variant="secondary" onClick={ addStep }>
                        { __( '+ Add step', 'dono-fundraising-platform' ) }
                    </Button>
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Steps', 'dono-fundraising-platform' ),
        description: __( 'Split the form into pages a donor clicks through. Add a Step inside to make a new page.', 'dono-fundraising-platform' ),
        category:    'dono-content',
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
