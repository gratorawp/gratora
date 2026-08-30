/**
 * fundkit/divider: a horizontal rule with author-set spacing and line colour.
 * Mirrors the server render in src/Forms/Blocks/DividerBlock.php.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import Slider from '../../../_shared/components/Slider';
import ColorInput from '../../../_shared/components/ColorInput';

const NAME = 'fundkit/divider';

function Edit( { attributes, setAttributes } ) {
    const {
        marginTop    = 16,
        marginBottom = 16,
        thickness    = 1,
        color        = '',
        condition    = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { style: { padding: '0' } } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Divider', 'fundkit-fundraising-campaigns' ) } initialOpen>
                    <Slider
                        label={ __( 'Space above', 'fundkit-fundraising-campaigns' ) }
                        value={ marginTop }
                        onChange={ ( v ) => setAttributes( { marginTop: Number( v ) } ) }
                        min={ 0 }
                        max={ 120 }
                        unit="px"
                    />
                    <Slider
                        label={ __( 'Space below', 'fundkit-fundraising-campaigns' ) }
                        value={ marginBottom }
                        onChange={ ( v ) => setAttributes( { marginBottom: Number( v ) } ) }
                        min={ 0 }
                        max={ 120 }
                        unit="px"
                    />
                    <Slider
                        label={ __( 'Line thickness', 'fundkit-fundraising-campaigns' ) }
                        value={ thickness }
                        onChange={ ( v ) => setAttributes( { thickness: Number( v ) } ) }
                        min={ 1 }
                        max={ 8 }
                        unit="px"
                    />
                    <ColorInput
                        label={ __( 'Line colour', 'fundkit-fundraising-campaigns' ) }
                        value={ color }
                        onChange={ ( v ) => setAttributes( { color: v || '' } ) }
                    />
                    <p style={ { fontSize: 12, color: '#6b7280', margin: '8px 0 0' } }>
                        { __( 'Leave the colour empty to follow the form border colour.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <hr
                    style={ {
                        margin:         `${ marginTop }px 0 ${ marginBottom }px`,
                        border:         0,
                        borderTop:      `${ thickness }px solid ${ color || 'var(--fundkit-border, #e5e7eb)' }`,
                        width:          '100%',
                    } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Divider', 'fundkit-fundraising-campaigns' ),
        description: __( 'A horizontal line with adjustable spacing and colour.', 'fundkit-fundraising-campaigns' ),
        category:   'fundkit-content',
        icon:       BlockIcons.divider,
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            marginTop:    { type: 'number', default: 16 },
            marginBottom: { type: 'number', default: 16 },
            thickness:    { type: 'number', default: 1 },
            color:        { type: 'string', default: '' },
            condition:    { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
