import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import Slider from '../../../_shared/components/Slider';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';
import { SlugTextControl } from '../_shared/SlugTextControl';

const NAME = 'gratora/text-input';

function Edit( { attributes, setAttributes } ) {
    const {
        label       = '',
        placeholder = '',
        helpText    = '',
        required    = false,
        maxLength   = 0,
        pattern     = '',
        field       = '',
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--field' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Text input', 'gratora-donation-platform' ) } initialOpen>
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
                    <Slider
                        label={ __( 'Maximum length', 'gratora-donation-platform' ) }
                        value={ maxLength }
                        onChange={ ( v ) => setAttributes( { maxLength: Math.max( 0, v ) } ) }
                        min={ 0 }
                        max={ 500 }
                        help={ __( '0 = no limit.', 'gratora-donation-platform' ) }
                    />
                    <TextControl
                        label={ __( 'Pattern (regex)', 'gratora-donation-platform' ) }
                        value={ pattern }
                        onChange={ ( v ) => setAttributes( { pattern: v } ) }
                        help={ __( 'HTML5 pattern attribute. Leave empty to skip.', 'gratora-donation-platform' ) }
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
                <div className="gratora-block-preview__field">{ placeholder || __( 'Text', 'gratora-donation-platform' ) }</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Text input', 'gratora-donation-platform' ),
        description: __( 'Single-line free text. For employer, dedication name, custom questions, etc.', 'gratora-donation-platform' ),
        category:   'gratora-fields',
        icon:       BlockIcons[ 'text-input' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:       { type: 'string',  default: '' },
            placeholder: { type: 'string',  default: '' },
            helpText:    { type: 'string',  default: '' },
            required:    { type: 'boolean', default: false },
            maxLength:   { type: 'integer', default: 0 },
            pattern:     { type: 'string',  default: '' },
            field:       { type: 'string',  default: '' },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
