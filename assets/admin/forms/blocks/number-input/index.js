import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, __experimentalNumberControl as NumberControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'giveflow/number-input';

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

    const blockProps = useBlockProps( { className: 'giveflow-block-preview giveflow-block-preview--field' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Number input', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'giveflow-fundraising-campaigns' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'giveflow-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'giveflow-fundraising-campaigns' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'giveflow-fundraising-campaigns' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        help={ __( 'For non-currency numbers (quantity, age, etc.). Use a donation-amount block for money.', 'giveflow-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'giveflow-fundraising-campaigns' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugify( v ) } ) }
                        help={ __( 'Stored under values.custom[field]. Lowercase, snake_case.', 'giveflow-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'giveflow-fundraising-campaigns' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Minimum', 'giveflow-fundraising-campaigns' ) }
                        value={ min === null ? '' : min }
                        onChange={ ( v ) => setAttributes( { min: v === '' || v === undefined ? null : Number( v ) } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Maximum', 'giveflow-fundraising-campaigns' ) }
                        value={ max === null ? '' : max }
                        onChange={ ( v ) => setAttributes( { max: v === '' || v === undefined ? null : Number( v ) } ) }
                        __nextHasNoMarginBottom
                    />
                    <NumberControl
                        label={ __( 'Step', 'giveflow-fundraising-campaigns' ) }
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
                    className="giveflow-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Label', 'giveflow-fundraising-campaigns' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="giveflow-block-preview__req" aria-hidden="true">*</em> }
                { helpText !== '' && (
                    <RichText
                        tagName="span"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Help text', 'giveflow-fundraising-campaigns' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', display: 'block', marginTop: 2 } }
                    />
                ) }
                <div className="giveflow-block-preview__field">{ placeholder || '0' }</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Number input', 'giveflow-fundraising-campaigns' ),
        description: __( 'Generic numeric field for non-currency values (quantity, age, etc.).', 'giveflow-fundraising-campaigns' ),
        category:   'giveflow-fields',
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
