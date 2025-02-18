import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import Segmented from '../../../_shared/components/Segmented';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { OptionsEditor, normalizeOptions, slugify } from '../_shared/OptionsEditor';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/radio';

const DEFAULT_OPTIONS = [
    { label: 'Option one', value: 'option-one', isDefault: false },
];

function Edit( { attributes, setAttributes } ) {
    const {
        label     = '',
        required  = false,
        field     = '',
        layout    = 'vertical',
        condition = DEFAULT_CONDITION,
    } = attributes;

    const options = normalizeOptions( attributes.options, DEFAULT_OPTIONS );

    const blockProps = useBlockProps( {
        className: `dono-block-preview dono-block-preview--radio dono-block-preview--${ layout }`,
    } );

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
                <PanelBody title={ __( 'Radio group', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label or any option to edit inline.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Field name', 'dono' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: slugify( v ) } ) }
                        help={ __( 'Key the value is stored under. Auto-derived from label if empty.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Layout', 'dono' ) }
                        value={ layout }
                        onChange={ ( v ) => setAttributes( { layout: v } ) }
                        options={ [
                            { value: 'vertical',   label: __( 'Vertical',   'dono' ) },
                            { value: 'horizontal', label: __( 'Horizontal', 'dono' ) },
                        ] }
                    />
                    <ToggleControl
                        label={ __( 'Required', 'dono' ) }
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
                    placeholder={ __( 'Question', 'dono' ) }
                    allowedFormats={ [] }
                />
                { required && <em className="dono-block-preview__req" aria-hidden="true">*</em> }
                <div
                    style={ {
                        marginTop: 8,
                        display:   'flex',
                        flexDirection: layout === 'horizontal' ? 'row' : 'column',
                        flexWrap:  'wrap',
                        gap:       layout === 'horizontal' ? 14 : 6,
                    } }
                >
                    { options.map( ( o, i ) => (
                        <div
                            key={ i }
                            style={ {
                                display:    'flex',
                                alignItems: 'center',
                                gap:        6,
                            } }
                        >
                            <span
                                style={ {
                                    width:        14,
                                    height:       14,
                                    borderRadius: '50%',
                                    border:       '1px solid #888',
                                    background:   o.isDefault ? 'radial-gradient(circle, var(--dono-accent, #1e8a4e) 40%, #fff 50%)' : '#fff',
                                    flexShrink:   0,
                                } }
                            />
                            <RichText
                                tagName="span"
                                value={ o.label }
                                onChange={ ( v ) => updateOptionLabel( i, v ) }
                                placeholder={ __( 'Option label', 'dono' ) }
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
        title:       __( 'Radio group', 'dono' ),
        description: __( 'Single-choice radio buttons. Donor picks one option from the list.', 'dono' ),
        category:    'dono-fields',
        icon:        BlockIcons[ 'radio' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:     { type: 'string',  default: '' },
            options:   { type: 'array',   default: [
                { label: 'Option one', value: 'option-one', isDefault: false },
            ] },
            required:  { type: 'boolean', default: false },
            field:     { type: 'string',  default: '' },
            layout:    { type: 'string',  default: 'vertical' },
            condition: { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
