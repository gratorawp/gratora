/**
 * dono/html: carries sanitised HTML through to the donor form (sponsor strips,
 * legal copy). The editor previews the sanitised result so authors see what
 * actually survives save, not embeds that will be stripped.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextareaControl, Disabled, SandBox } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'dono/html';

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
    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--html' } );
    const preview = survivesSave( content );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'HTML', 'dono-fundraising-platform' ) } initialOpen>
                    <TextareaControl
                        label={ __( 'HTML markup', 'dono-fundraising-platform' ) }
                        value={ content }
                        onChange={ ( v ) => setAttributes( { content: v } ) }
                        rows={ 8 }
                        help={ __( 'Sanitised on save: scripts, iframes and embeds, event handlers, and JavaScript URLs are stripped.', 'dono-fundraising-platform' ) }
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
                    <div className="dono-block-preview__html-empty">
                        { __( 'Add HTML in the block settings panel.', 'dono-fundraising-platform' ) }
                    </div>
                ) : preview.trim() ? (
                    <Disabled>
                        <SandBox html={ preview } />
                    </Disabled>
                ) : (
                    <div className="dono-block-preview__html-empty">
                        { __( 'Nothing to preview: scripts and embeds are removed when the form is saved.', 'dono-fundraising-platform' ) }
                    </div>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'HTML', 'dono-fundraising-platform' ),
        description: __( 'Add a sponsor strip, formatted text, or other safe HTML. Scripts and embeds are stripped.', 'dono-fundraising-platform' ),
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
