import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'dono/heading';

const LEVEL_OPTIONS = [
    { value: 1, label: 'H1' },
    { value: 2, label: 'H2' },
    { value: 3, label: 'H3' },
    { value: 4, label: 'H4' },
];

const ALIGN_OPTIONS = [
    { value: 'left',   label: __( 'Left',   'dono-fundraising-platform' ) },
    { value: 'center', label: __( 'Center', 'dono-fundraising-platform' ) },
    { value: 'right',  label: __( 'Right',  'dono-fundraising-platform' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { text = '', level = 2, align = 'left', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( {
        style: {
            padding:      '8px 0',
            textAlign:    align,
        },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Heading', 'dono-fundraising-platform' ) } initialOpen>
                    <Segmented
                        label={ __( 'Level', 'dono-fundraising-platform' ) }
                        value={ level }
                        onChange={ ( v ) => setAttributes( { level: Number( v ) } ) }
                        options={ LEVEL_OPTIONS }
                    />
                    <Segmented
                        label={ __( 'Alignment', 'dono-fundraising-platform' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ ALIGN_OPTIONS }
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName={ `h${ level }` }
                    value={ text }
                    onChange={ ( v ) => setAttributes( { text: v } ) }
                    placeholder={ __( 'Section heading', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                    style={ { margin: 0, fontWeight: 600 } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Heading', 'dono-fundraising-platform' ),
        description: __( 'Section heading shown above the next field step.', 'dono-fundraising-platform' ),
        category:   'dono-content',
        icon:       BlockIcons[ 'heading' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            text:  { type: 'string', default: '' },
            level: { type: 'number', default: 2 },
            align: { type: 'string', default: 'left' },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
