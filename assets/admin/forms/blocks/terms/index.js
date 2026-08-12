import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, TextareaControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/terms';

function Edit( { attributes, setAttributes } ) {
    const {
        label     = '',
        terms     = '',
        linkUrl   = '',
        linkText  = '',
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--terms' } );
    const configured = terms.trim() !== '' || linkUrl.trim() !== '';
    const labelText  = label.trim() || __( 'I agree to the terms', 'dono-fundraising-platform' );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Terms', 'dono-fundraising-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Checkbox label', 'dono-fundraising-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'I agree to the terms', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextareaControl
                        label={ __( 'Terms', 'dono-fundraising-platform' ) }
                        help={ __( 'Your own wording. Nothing is supplied: terms differ by country, cause and legal form, and sample text goes live unread.', 'dono-fundraising-platform' ) }
                        value={ terms }
                        onChange={ ( v ) => setAttributes( { terms: v } ) }
                        rows={ 10 }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Or link to a page', 'dono-fundraising-platform' ) }
                        value={ linkUrl }
                        onChange={ ( v ) => setAttributes( { linkUrl: v } ) }
                        type="url"
                        placeholder="https://"
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Link text', 'dono-fundraising-platform' ) }
                        value={ linkText }
                        onChange={ ( v ) => setAttributes( { linkText: v } ) }
                        placeholder={ __( 'Read the terms', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    value={ condition }
                    onChange={ ( v ) => setAttributes( { condition: v } ) }
                />
            </InspectorControls>

            <div { ...blockProps }>
                { ! configured && (
                    <Notice status="warning" isDismissible={ false }>
                        { __( 'Add your terms or a link to them. Until then this block asks for nothing and is not enforced.', 'dono-fundraising-platform' ) }
                    </Notice>
                ) }
                <label className="dono-block-preview__terms-agree">
                    <input type="checkbox" disabled />
                    <span>{ labelText } <span aria-hidden="true">*</span></span>
                </label>
                { terms.trim() !== '' && (
                    <div className="dono-block-preview__terms-text">{ terms }</div>
                ) }
                { linkUrl.trim() !== '' && (
                    <p className="dono-block-preview__terms-link">
                        { linkText.trim() || __( 'Read the terms', 'dono-fundraising-platform' ) }
                    </p>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Terms', 'dono-fundraising-platform' ),
        description: __( 'A required agreement to your own terms, recorded against the donation with the revision agreed to.', 'dono-fundraising-platform' ),
        category:    'dono-extras',
        icon:        BlockIcons[ 'consent' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:     { type: 'string', default: '' },
            terms:     { type: 'string', default: '' },
            linkUrl:   { type: 'string', default: '' },
            linkText:  { type: 'string', default: '' },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
