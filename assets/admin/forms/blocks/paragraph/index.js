import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'dono/paragraph';

const ALIGN_OPTIONS = [
    { value: 'left',   label: __( 'Left',   'dono' ) },
    { value: 'center', label: __( 'Center', 'dono' ) },
    { value: 'right',  label: __( 'Right',  'dono' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { text = '', align = 'left', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( {
        style: {
            padding:   '4px 0',
            textAlign: align,
        },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Paragraph', 'dono' ) } initialOpen>
                    <Segmented
                        label={ __( 'Alignment', 'dono' ) }
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
                    tagName="p"
                    value={ text }
                    onChange={ ( v ) => setAttributes( { text: v } ) }
                    placeholder={ __( 'Add a short description for donors.', 'dono' ) }
                    allowedFormats={ [ 'core/bold', 'core/italic', 'core/link' ] }
                    style={ { margin: 0, lineHeight: 1.5 } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Paragraph', 'dono' ),
        description: __( 'Short copy shown above the next field step.', 'dono' ),
        category:   'dono-content',
        icon:       BlockIcons[ 'paragraph' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            text:  { type: 'string', default: '' },
            align: { type: 'string', default: 'left' },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
