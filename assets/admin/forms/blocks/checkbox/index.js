import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { slugifyField } from '../_shared/OptionsEditor';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/checkbox';

function Edit( { attributes, setAttributes } ) {
    const {
        label     = '',
        helpText  = '',
        required  = false,
        defaultOn = false,
        field     = '',
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--check' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Checkbox', 'dono-fundraising-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono-fundraising-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label or help text to edit inline.', 'dono-fundraising-platform' ) }
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
                        onChange={ ( v ) => setAttributes( { field: slugifyField( v ) } ) }
                        help={ __( 'Key the value is stored under. Auto-derived from label if empty.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Default on', 'dono-fundraising-platform' ) }
                        checked={ defaultOn }
                        onChange={ ( v ) => setAttributes( { defaultOn: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'dono-fundraising-platform' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        help={ __( 'Donor must tick this to submit.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span
                    style={ {
                        width:        16,
                        height:       16,
                        borderRadius: 3,
                        border:       '1px solid #888',
                        background:   defaultOn ? 'var(--dono-accent, #1e8a4e)' : '#fff',
                        flexShrink:   0,
                        marginTop:    2,
                    } }
                />
                <span style={ { flex: 1, minWidth: 0, display: 'flex', flexDirection: 'column', gap: 2 } }>
                    <RichText
                        tagName="span"
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'I agree to…', 'dono-fundraising-platform' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 13, color: '#111827' } }
                    />
                    { required && <em className="dono-block-preview__req" aria-hidden="true">*</em> }
                    <RichText
                        tagName="span"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Optional help text', 'dono-fundraising-platform' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', lineHeight: 1.3 } }
                    />
                </span>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Checkbox', 'dono-fundraising-platform' ),
        description: __( 'Single yes/no checkbox for agreements, opt-ins, or any boolean.', 'dono-fundraising-platform' ),
        category:    'dono-fields',
        icon:        BlockIcons[ 'checkbox' ],
        supports: { html: false, anchor: false, inserter: true },
        attributes: {
            label:     { type: 'string',  default: '' },
            helpText:  { type: 'string',  default: '' },
            required:  { type: 'boolean', default: false },
            defaultOn: { type: 'boolean', default: false },
            field:     { type: 'string',  default: '' },
            condition: { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
