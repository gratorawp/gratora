/**
 * Resolve hidden custom fields from URL, referrer, or defaults; show an editable badge in the
 * editor.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'fundkit/hidden';

const SOURCES = [
    { value: 'fixed',        label: __( 'Fixed value',                    'fundraising-toolkit' ) },
    { value: 'query',        label: __( 'URL query string',               'fundraising-toolkit' ) },
    { value: 'utm_source',   label: __( 'UTM: Source',                    'fundraising-toolkit' ) },
    { value: 'utm_medium',   label: __( 'UTM: Medium',                    'fundraising-toolkit' ) },
    { value: 'utm_campaign', label: __( 'UTM: Campaign',                  'fundraising-toolkit' ) },
    { value: 'utm_term',     label: __( 'UTM: Term',                      'fundraising-toolkit' ) },
    { value: 'utm_content',  label: __( 'UTM: Content',                   'fundraising-toolkit' ) },
    { value: 'referrer',     label: __( 'Referrer URL',                   'fundraising-toolkit' ) },
    { value: 'landing',      label: __( 'Landing page URL',               'fundraising-toolkit' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { field = '', source = 'fixed', queryParam = '', defaultValue = '', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--hidden' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Hidden field', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'Field key', 'fundraising-toolkit' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: v.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) }
                        help={ __( 'Lowercase, underscores. This is the column name in donation reports.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Source', 'fundraising-toolkit' ) }
                        value={ source }
                        options={ SOURCES }
                        onChange={ ( v ) => setAttributes( { source: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { source === 'query' && (
                        <TextControl
                            label={ __( 'Query parameter name', 'fundraising-toolkit' ) }
                            value={ queryParam }
                            onChange={ ( v ) => setAttributes( { queryParam: v } ) }
                            placeholder="appeal_code"
                            __nextHasNoMarginBottom
                        />
                    ) }
                    <TextControl
                        label={ __( 'Fallback value', 'fundraising-toolkit' ) }
                        value={ defaultValue }
                        onChange={ ( v ) => setAttributes( { defaultValue: v } ) }
                        help={ __( 'Used when the source above resolves to empty (e.g. donor arrived directly).', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span className="fundkit-block-preview__hidden-tag">{ __( 'Hidden', 'fundraising-toolkit' ) }</span>
                <span className="fundkit-block-preview__hidden-meta">
                    { field ? `${ field } ← ${ source }` : __( '(no field key set)', 'fundraising-toolkit' ) }
                </span>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Hidden field', 'fundraising-toolkit' ),
        description: __( 'Invisible value captured with the donation. Use it for UTM tags, referrer URL, or any appeal code.', 'fundraising-toolkit' ),
        category:    'fundkit-fields',
        icon:        BlockIcons[ 'hidden' ],
        supports:    { html: false, anchor: false, inserter: true },
        attributes: {
            field:        { type: 'string', default: '' },
            source:       { type: 'string', default: 'fixed' },
            queryParam:   { type: 'string', default: '' },
            defaultValue: { type: 'string', default: '' },
            condition:    { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
