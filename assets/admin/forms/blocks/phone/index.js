import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/phone';

function Edit( { attributes, setAttributes } ) {
    const {
        label = '',
        placeholder = '',
        required = false,
        condition = DEFAULT_CONDITION,
    } = attributes;
    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--field' } );
    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Phone', 'dono-fundraising-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono-fundraising-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Phone', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'dono-fundraising-platform' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        placeholder="+1 (555) 123 4567"
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'dono-fundraising-platform' ) }
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
                <span className="dono-block-preview__label">
                    { label || __( 'Phone', 'dono-fundraising-platform' ) }
                    { required && <em className="dono-block-preview__req" aria-hidden="true">*</em> }
                </span>
                <div className="dono-block-preview__field">
                    { placeholder || '+1 (555) 123 4567' }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Phone', 'dono-fundraising-platform' ),
        category:   'dono-donor',
        icon:       BlockIcons[ 'phone' ],
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
