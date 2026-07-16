/**
 * dono/html: carries sanitised HTML through to the donor form (embeds, sponsor
 * strips, legal copy). The editor previews it rendered in a thin frame.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextareaControl, Disabled, SandBox } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'dono/html';

function Edit( { attributes, setAttributes } ) {
    const { content = '', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--html' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'HTML', 'dono' ) } initialOpen>
                    <TextareaControl
                        label={ __( 'HTML markup', 'dono' ) }
                        value={ content }
                        onChange={ ( v ) => setAttributes( { content: v } ) }
                        rows={ 8 }
                        help={ __( 'Sanitised on save: scripts, iframes and embeds, event handlers, and JavaScript URLs are stripped.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                { content ? (
                    <Disabled>
                        <SandBox html={ content } />
                    </Disabled>
                ) : (
                    <div className="dono-block-preview__html-empty">
                        { __( 'Add HTML in the block settings panel.', 'dono' ) }
                    </div>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'HTML', 'dono' ),
        description: __( 'Add a sponsor strip, formatted text, or other safe HTML. Scripts and embeds are stripped.', 'dono' ),
        category:    'dono-content',
        icon:        BlockIcons[ 'html' ],
        supports:    { html: false, anchor: false, inserter: true },
        attributes: {
            content: { type: 'string', default: '' },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
