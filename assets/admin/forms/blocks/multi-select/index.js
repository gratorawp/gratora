import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import Slider from '../../../_shared/components/Slider';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { OptionsEditor, normalizeOptions, slugify } from '../_shared/OptionsEditor';
import { BlockIcons } from '../_shared/block-icons';
import { SlugTextControl } from '../_shared/SlugTextControl';

const NAME = 'gratora/multi-select';

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
    const count   = options.length;

    // A minimum above the option count, or above the maximum, is a field no
    // donor can satisfy.
    const setMin = ( v ) => {
        const min = Math.min( Math.max( 0, v ), count );
        setAttributes( maxSelections > 0 && min > maxSelections
            ? { minSelections: min, maxSelections: min }
            : { minSelections: min } );
    };

    const setMax = ( v ) => {
        const max = Math.min( Math.max( 0, v ), count );
        setAttributes( { maxSelections: max === 0 ? 0 : Math.max( max, minSelections ) } );
    };

    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--multi-select' } );

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
                <PanelBody title={ __( 'Multi-select', 'gratora-donation-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'gratora-donation-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label or any option to edit inline.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SlugTextControl
                        label={ __( 'Field name', 'gratora-donation-platform' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: v } ) }
                        help={ __( 'Key the array is stored under. Auto-derived from label if empty.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'gratora-donation-platform' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        help={ __( 'At least one option must be selected.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <Slider
                        label={ __( 'Minimum selections', 'gratora-donation-platform' ) }
                        value={ minSelections }
                        onChange={ setMin }
                        min={ 0 }
                        max={ count }
                    />
                    <Slider
                        label={ __( 'Maximum selections', 'gratora-donation-platform' ) }
                        value={ maxSelections }
                        onChange={ setMax }
                        min={ 0 }
                        max={ count }
                        help={ __( 'Set to 0 for no upper limit.', 'gratora-donation-platform' ) }
                    />
                    <OptionsEditor
                        options={ options }
                        onChange={ ( next ) => setAttributes( {
                            options: next,
                            minSelections: Math.min( minSelections, next.length ),
                            maxSelections: maxSelections > 0 ? Math.min( maxSelections, next.length ) : 0,
                        } ) }
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
                    placeholder={ __( 'Pick any that apply', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="gratora-block-preview__req" aria-hidden="true">*</em> }
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
                                    background:   o.isDefault ? 'var(--gratora-accent, #211d3f)' : '#fff',
                                    flexShrink:   0,
                                } }
                            />
                            <RichText
                                tagName="span"
                                value={ o.label }
                                onChange={ ( v ) => updateOptionLabel( i, v ) }
                                placeholder={ __( 'Option label', 'gratora-donation-platform' ) }
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
        title:       __( 'Multi-select', 'gratora-donation-platform' ),
        description: __( 'Donor picks any number of options from a checkbox list.', 'gratora-donation-platform' ),
        category:    'gratora-fields',
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
