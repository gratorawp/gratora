/**
 * giveflow/html: carries sanitised HTML through to the donor form (sponsor strips,
 * legal copy). The editor previews the sanitised result so authors see what
 * actually survives save, not embeds that will be stripped.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextareaControl, Disabled, SandBox } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'giveflow/html';

// Mirror the server sanitiser (HtmlBlock::sanitize -> wp_kses_post) closely
// enough for preview: drop scripts, iframes/embeds, inline event handlers, and
// javascript: URLs. Everything else renders the same as it will on the form.
function survivesSave( html ) {
    const raw = String( html || '' );
    if ( ! raw || typeof document === 'undefined' ) return raw;
    const doc = document.implementation.createHTMLDocument( '' );
    doc.body.innerHTML = raw;
    doc.body.querySelectorAll( 'script, iframe, object, embed' ).forEach( ( el ) => el.remove() );
    doc.body.querySelectorAll( '*' ).forEach( ( el ) => {
        Array.from( el.attributes ).forEach( ( attr ) => {
            const name  = attr.name.toLowerCase();
            const value = ( attr.value || '' ).replace( /\s+/g, '' ).toLowerCase();
            if ( name.startsWith( 'on' ) ||
                ( /^(href|src|xlink:href)$/.test( name ) && value.startsWith( 'javascript:' ) ) ) {
                el.removeAttribute( attr.name );
            }
        } );
    } );
    return doc.body.innerHTML;
}

function Edit( { attributes, setAttributes } ) {
    const { content = '', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( { className: 'giveflow-block-preview giveflow-block-preview--html' } );
    const preview = survivesSave( content );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'HTML', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <TextareaControl
                        label={ __( 'HTML markup', 'giveflow-fundraising-campaigns' ) }
                        value={ content }
                        onChange={ ( v ) => setAttributes( { content: v } ) }
                        rows={ 8 }
                        help={ __( 'Sanitised on save: scripts, iframes and embeds, event handlers, and JavaScript URLs are stripped.', 'giveflow-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                { ! content ? (
                    <div className="giveflow-block-preview__html-empty">
                        { __( 'Add HTML in the block settings panel.', 'giveflow-fundraising-campaigns' ) }
                    </div>
                ) : preview.trim() ? (
                    <Disabled>
                        <SandBox html={ preview } />
                    </Disabled>
                ) : (
                    <div className="giveflow-block-preview__html-empty">
                        { __( 'Nothing to preview: scripts and embeds are removed when the form is saved.', 'giveflow-fundraising-campaigns' ) }
                    </div>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'HTML', 'giveflow-fundraising-campaigns' ),
        description: __( 'Add a sponsor strip, formatted text, or other safe HTML. Scripts and embeds are stripped.', 'giveflow-fundraising-campaigns' ),
        category:    'giveflow-content',
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
