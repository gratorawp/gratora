import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/anonymous-toggle';

function Edit( { attributes, setAttributes } ) {
    const {
        label     = __( 'Make this donation anonymous', 'dono-fundraising-platform' ),
        defaultOn = false,
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--check' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Anonymous toggle', 'dono-fundraising-platform' ) } initialOpen>
                    <ToggleControl
                        label={ __( 'Default on', 'dono-fundraising-platform' ) }
                        checked={ defaultOn }
                        onChange={ ( v ) => setAttributes( { defaultOn: v } ) }
                        help={ __( 'Click the label to edit it inline.', 'dono-fundraising-platform' ) }
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
                    } }
                />
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Make this donation anonymous', 'dono-fundraising-platform' ) }
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
        title:      __( 'Anonymous toggle', 'dono-fundraising-platform' ),
        description: __( 'Lets the donor hide their identity on public displays.', 'dono-fundraising-platform' ),
        category:   'dono-extras',
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
