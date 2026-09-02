import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/country';

function Edit( { attributes, setAttributes } ) {
    const {
        label = '',
        placeholder = '',
        required = false,
        condition = DEFAULT_CONDITION,
    } = attributes;
    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--field' } );
    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Country', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Country', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'fundraising-toolkit' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        placeholder={ __( 'Search country…', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'fundraising-toolkit' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span className="fundkit-block-preview__label">
                    { label || __( 'Country', 'fundraising-toolkit' ) }
                    { required && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                </span>
                <div className="fundkit-block-preview__field">
                    { placeholder || __( 'Search country…', 'fundraising-toolkit' ) }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Country', 'fundraising-toolkit' ),
        category:   'fundkit-donor',
        icon:       BlockIcons[ 'country' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:       { type: 'string',  default: '' },
            placeholder: { type: 'string',  default: '' },
            required:    { type: 'boolean', default: false },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
