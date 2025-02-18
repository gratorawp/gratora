import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/email';

function Edit( { attributes, setAttributes } ) {
    const {
        label = '',
        placeholder = '',
    } = attributes;
    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--field' } );
    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Email', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Email', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'dono' ) }
                        value={ placeholder }
                        onChange={ ( v ) => setAttributes( { placeholder: v } ) }
                        placeholder="you@example.com"
                        __nextHasNoMarginBottom
                    />
                    { /* No "Required" toggle: a donation always needs an email
                         (the server hard-requires it), so it is always required. */ }
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <span className="dono-block-preview__label">
                    { label || __( 'Email', 'dono' ) }
                    <em className="dono-block-preview__req" aria-hidden="true">*</em>
                </span>
                <div className="dono-block-preview__field">
                    { placeholder || 'you@example.com' }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Email', 'dono' ),
        category:   'dono-donor',
        icon:       BlockIcons[ 'email' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:       { type: 'string',  default: '' },
            placeholder: { type: 'string',  default: '' },
            required:    { type: 'boolean', default: true },
        },
        edit: Edit,
        save: () => null,
    } );
}
