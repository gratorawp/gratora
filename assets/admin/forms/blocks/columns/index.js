/**
 * fundkit/columns: multi-column container for content blocks.
 *
 * Renders as a CSS grid in both the editor preview and the donor-facing
 * runtime. Decoration-only (no donor input fields); use fundkit/row for the
 * form-field side-by-side layout.
 */

import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import Slider from '../../../_shared/components/Slider';

const NAME = 'fundkit/columns';

const GAP_UNITS = [ 'px', 'em', 'rem', '%' ];

// Decoration-only blocks. Field/step blocks (donation-amount, submit-button,
// donor-detail blocks, ...) intentionally excluded: the walker treats
// fundkit/columns purely as a decoration container, so any step inside escapes
// the wrapper and renders as a top-level step. Mirrors fundkit/section.
const ALLOWED = [
    'fundkit/heading',
    'fundkit/paragraph',
    'fundkit/section',
    'fundkit/goal',
    'fundkit/html',
];

function Edit( { attributes, setAttributes } ) {
    const { columns = 2, gap = 16, gapUnit = 'px', condition = DEFAULT_CONDITION } = attributes;

    const blockProps = useBlockProps( {
        className: 'fundkit-block-preview fundkit-block-preview--columns',
        style: {
            display:             'grid',
            gridTemplateColumns: `repeat(${ columns }, minmax(0, 1fr))`,
            gap:                 `${ gap }${ gapUnit }`,
            padding:             8,
            border:              '1px dashed #c3c4c7',
            borderRadius:        6,
        },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Columns', 'fundraising-toolkit' ) } initialOpen>
                    <Slider
                        label={ __( 'Columns', 'fundraising-toolkit' ) }
                        value={ columns }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        min={ 1 }
                        max={ 6 }
                    />
                    <Slider
                        label={ __( 'Gap', 'fundraising-toolkit' ) }
                        value={ gap }
                        onChange={ ( v ) => setAttributes( { gap: v } ) }
                        min={ 0 }
                        max={ 80 }
                        unit={ gapUnit }
                        units={ GAP_UNITS }
                        onUnitChange={ ( u ) => setAttributes( { gapUnit: u } ) }
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <InnerBlocks
                    allowedBlocks={ ALLOWED }
                    renderAppender={ InnerBlocks.ButtonBlockAppender }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Columns', 'fundraising-toolkit' ),
        description: __( 'Lay out content blocks side by side in a grid.', 'fundraising-toolkit' ),
        category:    'fundkit-content',
        icon:        BlockIcons[ 'columns' ],
        supports:    { html: false, anchor: false, inserter: true },
        attributes: {
            columns: { type: 'number', default: 2 },
            gap:     { type: 'number', default: 16 },
            gapUnit: { type: 'string', default: 'px' },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => <InnerBlocks.Content />,
    } );
}
