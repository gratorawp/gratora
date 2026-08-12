import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/date';

function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' );
}

function Edit( { attributes, setAttributes } ) {
    const {
        label     = '',
        helpText  = '',
        required  = false,
        minDate   = '',
        maxDate   = '',
        field     = '',
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--field' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Date', 'dono-fundraising-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono-fundraising-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'dono-fundraising-platform' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'dono-fundraising-platform' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugify( v ) } ) }
                        help={ __( 'Stored under values.custom[field]. Lowercase, snake_case.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'dono-fundraising-platform' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Minimum date', 'dono-fundraising-platform' ) }
                        value={ minDate }
                        onChange={ ( v ) => setAttributes( { minDate: v } ) }
                        placeholder="YYYY-MM-DD"
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Maximum date', 'dono-fundraising-platform' ) }
                        value={ maxDate }
                        onChange={ ( v ) => setAttributes( { maxDate: v } ) }
                        placeholder="YYYY-MM-DD"
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
                    className="dono-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Date', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="dono-block-preview__req" aria-hidden="true">*</em> }
                { helpText !== '' && (
                    <RichText
                        tagName="span"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Help text', 'dono-fundraising-platform' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', display: 'block', marginTop: 2 } }
                    />
                ) }
                <div className="dono-block-preview__field">YYYY-MM-DD</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Date', 'dono-fundraising-platform' ),
        description: __( 'Date picker for birthdays, dedication dates, event dates, etc.', 'dono-fundraising-platform' ),
        category:   'dono-fields',
        icon:       BlockIcons[ 'date' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:     { type: 'string',  default: '' },
            helpText:  { type: 'string',  default: '' },
            required:  { type: 'boolean', default: false },
            minDate:   { type: 'string',  default: '' },
            maxDate:   { type: 'string',  default: '' },
            field:     { type: 'string',  default: '' },
            condition: { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
