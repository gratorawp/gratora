import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/name';

function Edit( { attributes, setAttributes } ) {
    const {
        firstLabel = '',
        lastLabel = '',
        firstPlaceholder = '',
        lastPlaceholder = '',
        requireFirst = true,
        requireLast = true,
    } = attributes;
    const blockProps = useBlockProps( { className: 'fundkit-block-preview' } );
    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Name', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'First name label', 'fundraising-toolkit' ) }
                        value={ firstLabel }
                        onChange={ ( v ) => setAttributes( { firstLabel: v } ) }
                        placeholder={ __( 'First name', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'First name placeholder', 'fundraising-toolkit' ) }
                        value={ firstPlaceholder }
                        onChange={ ( v ) => setAttributes( { firstPlaceholder: v } ) }
                        placeholder="Jane"
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'First name required', 'fundraising-toolkit' ) }
                        checked={ requireFirst }
                        onChange={ ( v ) => setAttributes( { requireFirst: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Last name label', 'fundraising-toolkit' ) }
                        value={ lastLabel }
                        onChange={ ( v ) => setAttributes( { lastLabel: v } ) }
                        placeholder={ __( 'Last name', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Last name placeholder', 'fundraising-toolkit' ) }
                        value={ lastPlaceholder }
                        onChange={ ( v ) => setAttributes( { lastPlaceholder: v } ) }
                        placeholder="Doe"
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Last name required', 'fundraising-toolkit' ) }
                        checked={ requireLast }
                        onChange={ ( v ) => setAttributes( { requireLast: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <div className="fundkit-block-preview__grid-2">
                    <div>
                        <span className="fundkit-block-preview__label">
                            { firstLabel || __( 'First name', 'fundraising-toolkit' ) }
                            { requireFirst && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                        </span>
                        <div className="fundkit-block-preview__field">
                            { firstPlaceholder || 'Jane' }
                        </div>
                    </div>
                    <div>
                        <span className="fundkit-block-preview__label">
                            { lastLabel || __( 'Last name', 'fundraising-toolkit' ) }
                            { requireLast && <em className="fundkit-block-preview__req" aria-hidden="true">*</em> }
                        </span>
                        <div className="fundkit-block-preview__field">
                            { lastPlaceholder || 'Doe' }
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Name', 'fundraising-toolkit' ),
        category:   'fundkit-donor',
        icon:       BlockIcons[ 'name' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            firstLabel:       { type: 'string',  default: '' },
            lastLabel:        { type: 'string',  default: '' },
            firstPlaceholder: { type: 'string',  default: '' },
            lastPlaceholder:  { type: 'string',  default: '' },
            requireFirst:     { type: 'boolean', default: true },
            requireLast:      { type: 'boolean', default: true },
        },
        edit: Edit,
        save: () => null,
    } );
}
