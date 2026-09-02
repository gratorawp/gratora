import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/email';

function Edit( { attributes, setAttributes } ) {
    const {
        label = '',
        placeholder = '',
    } = attributes;
    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--field' } );
    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Email', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Email', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Placeholder', 'fundraising-toolkit' ) }
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
                <span className="fundkit-block-preview__label">
                    { label || __( 'Email', 'fundraising-toolkit' ) }
                    <em className="fundkit-block-preview__req" aria-hidden="true">*</em>
                </span>
                <div className="fundkit-block-preview__field">
                    { placeholder || 'you@example.com' }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Email', 'fundraising-toolkit' ),
        category:   'fundkit-donor',
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
