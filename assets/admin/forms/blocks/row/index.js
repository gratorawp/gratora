import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Slider from '../../../_shared/components/Slider';

const NAME = 'dono/row';

// Donor fields only: content blocks (heading/paragraph/currency-switcher) are
// rendered outside the grid by the runtime, so they must not sit in a row.
const ALLOWED = [
    'dono/name',
    'dono/email',
    'dono/country',
    'dono/phone',
    'dono/comment',
    'dono/anonymous-toggle',
    'dono/cover-fees',
    'dono/tribute',
];

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
            borderRadius:        'var(--dono-radius-sm, 6px)',
        },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Row', 'dono' ) } initialOpen>
                    <Slider
                        label={ __( 'Columns', 'dono' ) }
                        value={ columns }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        min={ 1 }
                        max={ 4 }
                    />
                    <Slider
                        label={ __( 'Gap', 'dono' ) }
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
                    allowedBlocks={ ALLOWED }
                    renderAppender={ InnerBlocks.ButtonBlockAppender }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Row', 'dono' ),
        description: __( 'Lay out fields side by side in columns.', 'dono' ),
        category:   'dono-content',
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
