import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/anonymous-toggle';

function Edit( { attributes, setAttributes } ) {
    const {
        label     = __( 'Make this donation anonymous', 'fundraising-toolkit' ),
        defaultOn = false,
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--check' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Anonymous toggle', 'fundraising-toolkit' ) } initialOpen>
                    <ToggleControl
                        label={ __( 'Default on', 'fundraising-toolkit' ) }
                        checked={ defaultOn }
                        onChange={ ( v ) => setAttributes( { defaultOn: v } ) }
                        help={ __( 'Click the label to edit it inline.', 'fundraising-toolkit' ) }
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
                        background:   defaultOn ? 'var(--fundkit-accent, #211d3f)' : '#fff',
                        flexShrink:   0,
                    } }
                />
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Make this donation anonymous', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                    style={ { fontSize: 13, flex: 1 } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Anonymous toggle', 'fundraising-toolkit' ),
        description: __( 'Lets the donor hide their identity on public displays.', 'fundraising-toolkit' ),
        category:   'fundkit-extras',
        icon:       BlockIcons[ 'anonymous-toggle' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:     { type: 'string',  default: '' },
            defaultOn: { type: 'boolean', default: false },
            condition: { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
