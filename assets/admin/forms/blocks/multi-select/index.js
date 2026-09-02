import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import Slider from '../../../_shared/components/Slider';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { OptionsEditor, normalizeOptions, slugify, slugifyField } from '../_shared/OptionsEditor';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/multi-select';

const DEFAULT_OPTIONS = [
    { label: 'Option one', value: 'option-one', isDefault: false },
];

function Edit( { attributes, setAttributes } ) {
    const {
        label         = '',
        required      = false,
        field         = '',
        minSelections = 0,
        maxSelections = 0,
        condition     = DEFAULT_CONDITION,
    } = attributes;

    const options = normalizeOptions( attributes.options, DEFAULT_OPTIONS );

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--multi-select' } );

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
                <PanelBody title={ __( 'Multi-select', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label or any option to edit inline.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'fundraising-toolkit' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugifyField( v ) } ) }
                        help={ __( 'Key the array is stored under. Auto-derived from label if empty.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'fundraising-toolkit' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        help={ __( 'At least one option must be selected.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <Slider
                        label={ __( 'Minimum selections', 'fundraising-toolkit' ) }
                        value={ minSelections }
                        onChange={ ( v ) => setAttributes( { minSelections: Math.max( 0, v ) } ) }
                        min={ 0 }
                        max={ 20 }
                    />
                    <Slider
                        label={ __( 'Maximum selections', 'fundraising-toolkit' ) }
                        value={ maxSelections }
                        onChange={ ( v ) => setAttributes( { maxSelections: Math.max( 0, v ) } ) }
                        min={ 0 }
                        max={ 20 }
                        help={ __( 'Set to 0 for no upper limit.', 'fundraising-toolkit' ) }
                    />
                    <OptionsEditor
                        options={ options }
                        onChange={ ( next ) => setAttributes( { options: next } ) }
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
                    placeholder={ __( 'Pick any that apply', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                <div
                    style={ {
                        marginTop: 8,
                        display:   'flex',
                        flexDirection: 'column',
                        gap:       6,
                    } }
                >
                    { options.map( ( o, i ) => (
                        <div
                            key={ i }
                            style={ {
                                display:    'flex',
                                alignItems: 'center',
                                gap:        8,
                            } }
                        >
                            <span
                                style={ {
                                    width:        14,
                                    height:       14,
                                    borderRadius: 3,
                                    border:       '1px solid #888',
                                    background:   o.isDefault ? 'var(--fundkit-accent, #211d3f)' : '#fff',
                                    flexShrink:   0,
                                } }
                            />
                            <RichText
                                tagName="span"
                                value={ o.label }
                                onChange={ ( v ) => updateOptionLabel( i, v ) }
                                placeholder={ __( 'Option label', 'fundraising-toolkit' ) }
                                allowedFormats={ [] }
                                style={ { fontSize: 13, color: '#111827' } }
                            />
                        </div>
                    ) ) }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Multi-select', 'fundraising-toolkit' ),
        description: __( 'Donor picks any number of options from a checkbox list.', 'fundraising-toolkit' ),
        category:    'fundkit-fields',
        icon:        BlockIcons[ 'multi-select' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:         { type: 'string',  default: '' },
            options:       { type: 'array',   default: [
                { label: 'Option one', value: 'option-one', isDefault: false },
            ] },
            required:      { type: 'boolean', default: false },
            field:         { type: 'string',  default: '' },
            minSelections: { type: 'number',  default: 0 },
            maxSelections: { type: 'number',  default: 0 },
            condition:     { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
