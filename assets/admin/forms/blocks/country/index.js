import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'gratora/country';

function Edit( { attributes, setAttributes } ) {
    const {
        label = '',
        placeholder = '',
        required = false,
        condition = DEFAULT_CONDITION,
    } = attributes;
    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--field' } );
    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Country', 'gratora-donation-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'gratora-donation-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Country', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'gratora-donation-platform' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        placeholder={ __( 'Search country…', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'gratora-donation-platform' ) }
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
                <span className="gratora-block-preview__label">
                    { label || __( 'Country', 'gratora-donation-platform' ) }
                    { required && <em className="gratora-block-preview__req" aria-hidden="true">*</em> }
                </span>
                <div className="gratora-block-preview__field">
                    { placeholder || __( 'Search country…', 'gratora-donation-platform' ) }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Country', 'gratora-donation-platform' ),
        category:   'gratora-donor',
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
