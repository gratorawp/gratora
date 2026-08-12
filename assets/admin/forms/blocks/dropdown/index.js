import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { OptionsEditor, normalizeOptions, slugify, slugifyField } from '../_shared/OptionsEditor';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/dropdown';

const DEFAULT_OPTIONS = [
    { label: 'Option one', value: 'option-one', isDefault: false },
];

function Edit( { attributes, setAttributes } ) {
    const {
        label       = '',
        placeholder = '',
        required    = false,
        field       = '',
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const options = normalizeOptions( attributes.options, DEFAULT_OPTIONS );

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--dropdown' } );

    const selected = options.find( ( o ) => o.isDefault ) || options[ 0 ];
    const previewText = placeholder || selected?.label || __( 'Select one…', 'dono-fundraising-platform' );

    const updateOptionLabel = ( i, v ) => {
        const row     = options[ i ];
        const derived = slugify( String( row?.label ?? '' ) );
        const isAuto  = row?.value === derived || row?.value === '' || ! row?.value;
        const next    = options.map( ( o, idx ) => {
            if ( idx !== i ) return o;
            const patch = { label: v };
            if ( isAuto ) patch.value = slugify( v ) || `option-${ i + 1 }`;
            return { ...o, ...patch };
        } );
        setAttributes( { options: next } );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Dropdown', 'dono-fundraising-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono-fundraising-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label or an option to edit inline.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'dono-fundraising-platform' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        placeholder={ __( 'Select one…', 'dono-fundraising-platform' ) }
                        help={ __( 'First option shown before a value is picked.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'dono-fundraising-platform' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugifyField( v ) } ) }
                        help={ __( 'Key the value is stored under. Auto-derived from label if empty.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'dono-fundraising-platform' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <OptionsEditor
                        options={ options }
                        onChange={ ( next ) => setAttributes( { options: next } ) }
                        singleDefault
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
                    placeholder={ __( 'Question', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="dono-block-preview__req" aria-hidden="true">*</em> }
                <div
                    style={ {
                        marginTop:    6,
                        padding:      '8px 12px',
                        background:   '#fff',
                        border:       '1px solid #d4d4d8',
                        borderRadius: 'var(--dono-radius-sm, 6px)',
                        fontSize:     13,
                        color:        '#374151',
                        display:      'flex',
                        alignItems:   'center',
                        justifyContent: 'space-between',
                    } }
                >
                    <span>{ previewText }</span>
                    <span style={ { color: '#9ca3af', fontSize: 11 } }>▾</span>
                </div>
                <div
                    style={ {
                        marginTop: 10,
                        display:   'flex',
                        flexDirection: 'column',
                        gap:       4,
                    } }
                >
                    { options.map( ( o, i ) => (
                        <RichText
                            key={ i }
                            tagName="span"
                            value={ o.label }
                            onChange={ ( v ) => updateOptionLabel( i, v ) }
                            placeholder={ __( 'Option label', 'dono-fundraising-platform' ) }
                            allowedFormats={ [] }
                            style={ {
                                fontSize:    12,
                                color:       '#374151',
                                padding:     '4px 8px',
                                background:  o.isDefault ? 'color-mix(in srgb, var(--dono-accent, #1e8a4e) 8%, transparent)' : '#f9fafb',
                                border:      `1px solid ${ o.isDefault ? 'var(--dono-accent, #1e8a4e)' : '#e5e7eb' }`,
                                borderRadius: 'var(--dono-radius-sm, 4px)',
                            } }
                        />
                    ) ) }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Dropdown', 'dono-fundraising-platform' ),
        description: __( 'A select question where the donor picks one option from a dropdown list.', 'dono-fundraising-platform' ),
        category:    'dono-fields',
        icon:        BlockIcons[ 'dropdown' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:       { type: 'string',  default: '' },
            placeholder: { type: 'string',  default: '' },
            options:     { type: 'array',   default: [
                { label: 'Option one', value: 'option-one', isDefault: false },
            ] },
            required:    { type: 'boolean', default: false },
            field:       { type: 'string',  default: '' },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
