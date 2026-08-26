import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { applyFilters } from '@wordpress/hooks';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Slider from '../../../_shared/components/Slider';

const NAME = 'giveflow/row';

// Donor fields only: content blocks (heading/paragraph/currency-switcher) are
// rendered outside the grid by the runtime, so they must not sit in a row.
const ALLOWED = [
    'giveflow/name',
    'giveflow/email',
    'giveflow/country',
    'giveflow/phone',
    'giveflow/comment',
    'giveflow/anonymous-toggle',
    'giveflow/cover-fees',
];

// Resolved at registration, not module scope, so a donor field an add-on
// contributes can join the list before the editor mounts.
function allowedBlocks() {
    return applyFilters( 'giveflow.editor.rowAllowedBlocks', ALLOWED );
}

const GAP_UNITS = [ 'px', 'em', 'rem', '%' ];

function Edit( { attributes, setAttributes } ) {
    const { columns = 2, gap = 12, gapUnit = 'px' } = attributes;
    const blockProps = useBlockProps( {
        style: {
            display:             'grid',
            gridTemplateColumns: `repeat(${ columns }, minmax(0, 1fr))`,
            gap:                 `${ gap }${ gapUnit }`,
            padding:             8,
            border:              '1px dashed #c3c4c7',
            borderRadius:        'var(--giveflow-radius-sm, 6px)',
        },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Row', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <Slider
                        label={ __( 'Columns', 'giveflow-fundraising-campaigns' ) }
                        value={ columns }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        min={ 1 }
                        max={ 4 }
                    />
                    <Slider
                        label={ __( 'Gap', 'giveflow-fundraising-campaigns' ) }
                        value={ gap }
                        onChange={ ( v ) => setAttributes( { gap: v } ) }
                        min={ 0 }
                        max={ 40 }
                        unit={ gapUnit }
                        units={ GAP_UNITS }
                        onUnitChange={ ( u ) => setAttributes( { gapUnit: u } ) }
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <InnerBlocks
                    allowedBlocks={ allowedBlocks() }
                    renderAppender={ InnerBlocks.ButtonBlockAppender }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Row', 'giveflow-fundraising-campaigns' ),
        description: __( 'Lay out fields side by side in columns.', 'giveflow-fundraising-campaigns' ),
        category:   'giveflow-content',
        icon:       BlockIcons[ 'row' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            columns: { type: 'number', default: 2 },
            gap:     { type: 'number', default: 12 },
            gapUnit: { type: 'string', default: 'px' },
        },
        edit: Edit,
        save: () => <InnerBlocks.Content />,
    } );
}
