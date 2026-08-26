import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'giveflow/anonymous-toggle';

function Edit( { attributes, setAttributes } ) {
    const {
        label     = __( 'Make this donation anonymous', 'giveflow-fundraising-campaigns' ),
        defaultOn = false,
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'giveflow-block-preview giveflow-block-preview--check' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Anonymous toggle', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <ToggleControl
                        label={ __( 'Default on', 'giveflow-fundraising-campaigns' ) }
                        checked={ defaultOn }
                        onChange={ ( v ) => setAttributes( { defaultOn: v } ) }
                        help={ __( 'Click the label to edit it inline.', 'giveflow-fundraising-campaigns' ) }
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
                        background:   defaultOn ? 'var(--giveflow-accent, #211d3f)' : '#fff',
                        flexShrink:   0,
                    } }
                />
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Make this donation anonymous', 'giveflow-fundraising-campaigns' ) }
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
        title:      __( 'Anonymous toggle', 'giveflow-fundraising-campaigns' ),
        description: __( 'Lets the donor hide their identity on public displays.', 'giveflow-fundraising-campaigns' ),
        category:   'giveflow-extras',
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
