import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';
import { SlugTextControl } from '../_shared/SlugTextControl';

const NAME = 'gratora/number-input';

function Edit( { attributes, setAttributes } ) {
    const {
        label       = '',
        placeholder = '',
        helpText    = '',
        required    = false,
        min         = null,
        max         = null,
        step        = 1,
        field       = '',
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--field' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Number input', 'gratora-donation-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'gratora-donation-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'gratora-donation-platform' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'gratora-donation-platform' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        help={ __( 'For non-currency numbers (quantity, age, etc.). Use a donation-amount block for money.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SlugTextControl
                        label={ __( 'Field name', 'gratora-donation-platform' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: v } ) }
                        help={ __( 'Stored under values.custom[field]. Lowercase, snake_case.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'gratora-donation-platform' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Minimum', 'gratora-donation-platform' ) }
                        value={ min === null ? '' : min }
                        onChange={ ( v ) => setAttributes( { min: v === '' || v === undefined ? null : Number( v ) } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Maximum', 'gratora-donation-platform' ) }
                        value={ max === null ? '' : max }
                        onChange={ ( v ) => setAttributes( { max: v === '' || v === undefined ? null : Number( v ) } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Step', 'gratora-donation-platform' ) }
                        value={ step }
                        min={ 0 }
                        onChange={ ( v ) => setAttributes( { step: Number( v ) || 1 } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="span"
                    className="gratora-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Label', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="gratora-block-preview__req" aria-hidden="true">*</em> }
                { helpText !== '' && (
                    <RichText
                        tagName="span"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Help text', 'gratora-donation-platform' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', display: 'block', marginTop: 2 } }
                    />
                ) }
                <div className="gratora-block-preview__field">{ placeholder || '0' }</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Number input', 'gratora-donation-platform' ),
        description: __( 'Generic numeric field for non-currency values (quantity, age, etc.).', 'gratora-donation-platform' ),
        category:   'gratora-fields',
        icon:       BlockIcons[ 'number-input' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:       { type: 'string',  default: '' },
            placeholder: { type: 'string',  default: '' },
            helpText:    { type: 'string',  default: '' },
            required:    { type: 'boolean', default: false },
            min:         { type: [ 'number', 'null' ], default: null },
            max:         { type: [ 'number', 'null' ], default: null },
            step:        { type: 'number',  default: 1 },
            field:       { type: 'string',  default: '' },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
