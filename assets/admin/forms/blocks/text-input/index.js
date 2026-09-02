import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import Slider from '../../../_shared/components/Slider';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/text-input';

function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' );
}

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

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--field' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Text input', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'fundraising-toolkit' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'fundraising-toolkit' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'fundraising-toolkit' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugify( v ) } ) }
                        help={ __( 'Stored under values.custom[field]. Lowercase, snake_case.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'fundraising-toolkit' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <Slider
                        label={ __( 'Maximum length', 'fundraising-toolkit' ) }
                        value={ maxLength }
                        onChange={ ( v ) => setAttributes( { maxLength: Math.max( 0, v ) } ) }
                        min={ 0 }
                        max={ 500 }
                        help={ __( '0 = no limit.', 'fundraising-toolkit' ) }
                    />
                    <TextControl
                        label={ __( 'Pattern (regex)', 'fundraising-toolkit' ) }
                        value={ pattern }
                        onChange={ ( v ) => setAttributes( { pattern: v } ) }
                        help={ __( 'HTML5 pattern attribute. Leave empty to skip.', 'fundraising-toolkit' ) }
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
                    className="fundkit-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Label', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                { helpText !== '' && (
                    <RichText
                        tagName="span"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Help text', 'fundraising-toolkit' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', display: 'block', marginTop: 2 } }
                    />
                ) }
                <div className="fundkit-block-preview__field">{ placeholder || __( 'Text', 'fundraising-toolkit' ) }</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Text input', 'fundraising-toolkit' ),
        description: __( 'Single-line free text. For employer, dedication name, custom questions, etc.', 'fundraising-toolkit' ),
        category:   'fundkit-fields',
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
