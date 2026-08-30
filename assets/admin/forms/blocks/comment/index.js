import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/comment';

function Edit( { attributes, setAttributes } ) {
    const {
        label       = '',
        placeholder = '',
        required    = false,
        condition   = DEFAULT_CONDITION,
    } = attributes;

    // Attributes default to '' and the walker injects these when empty; mirror
    // that in the preview so the canvas is never a nameless field.
    const labelText       = label || __( 'Add a message', 'fundkit-fundraising-campaigns' );
    const placeholderText = placeholder || __( 'Anything you want to share?', 'fundkit-fundraising-campaigns' );

    const blockProps = useBlockProps( { className: 'fundkit-block-preview' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Comment', 'fundkit-fundraising-campaigns' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundkit-fundraising-campaigns' ) }
                        value={ label }
                        placeholder={ __( 'Add a message', 'fundkit-fundraising-campaigns' ) }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'fundkit-fundraising-campaigns' ) }
                        value={ placeholder }
                        placeholder={ __( 'Anything you want to share?', 'fundkit-fundraising-campaigns' ) }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Required', 'fundkit-fundraising-campaigns' ) }
                        checked={ required }
                        onChange={ ( v ) => setAttributes( { required: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span className="fundkit-block-preview__label">
                    { labelText }
                    { required && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                </span>
                <div className="fundkit-block-preview__textarea">{ placeholderText }</div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Comment', 'fundkit-fundraising-campaigns' ),
        description: __( 'Optional message from the donor to the organization.', 'fundkit-fundraising-campaigns' ),
        category:   'fundkit-fields',
        icon:       BlockIcons[ 'comment' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:       { type: 'string',  default: '' },
            placeholder: { type: 'string',  default: '' },
            required:    { type: 'boolean', default: false },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
