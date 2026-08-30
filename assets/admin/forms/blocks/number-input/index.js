import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/number-input';

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
        min         = null,
        max         = null,
        step        = 1,
        field       = '',
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--field' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Number input', 'fundkit-fundraising-campaigns' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundkit-fundraising-campaigns' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'fundkit-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'fundkit-fundraising-campaigns' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'fundkit-fundraising-campaigns' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        help={ __( 'For non-currency numbers (quantity, age, etc.). Use a donation-amount block for money.', 'fundkit-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'fundkit-fundraising-campaigns' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugify( v ) } ) }
                        help={ __( 'Stored under values.custom[field]. Lowercase, snake_case.', 'fundkit-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'fundkit-fundraising-campaigns' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Minimum', 'fundkit-fundraising-campaigns' ) }
                        value={ min === null ? '' : min }
                        onChange={ ( v ) => setAttributes( { min: v === '' || v === undefined ? null : Number( v ) } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Maximum', 'fundkit-fundraising-campaigns' ) }
                        value={ max === null ? '' : max }
                        onChange={ ( v ) => setAttributes( { max: v === '' || v === undefined ? null : Number( v ) } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Step', 'fundkit-fundraising-campaigns' ) }
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
                    className="fundkit-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Label', 'fundkit-fundraising-campaigns' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                { helpText !== '' && (
                    <RichText
                        tagName="span"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Help text', 'fundkit-fundraising-campaigns' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', display: 'block', marginTop: 2 } }
                    />
                ) }
                <div className="fundkit-block-preview__field">{ placeholder || '0' }</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Number input', 'fundkit-fundraising-campaigns' ),
        description: __( 'Generic numeric field for non-currency values (quantity, age, etc.).', 'fundkit-fundraising-campaigns' ),
        category:   'fundkit-fields',
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
