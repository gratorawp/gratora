/**
 * Resolve hidden custom fields from URL, referrer, or defaults; show an editable badge in the
 * editor.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'gratora/hidden';

const SOURCES = [
    { value: 'fixed',        label: __( 'Fixed value',                    'gratora' ) },
    { value: 'query',        label: __( 'URL query string',               'gratora' ) },
    { value: 'utm_source',   label: __( 'UTM: Source',                    'gratora' ) },
    { value: 'utm_medium',   label: __( 'UTM: Medium',                    'gratora' ) },
    { value: 'utm_campaign', label: __( 'UTM: Campaign',                  'gratora' ) },
    { value: 'utm_term',     label: __( 'UTM: Term',                      'gratora' ) },
    { value: 'utm_content',  label: __( 'UTM: Content',                   'gratora' ) },
    { value: 'referrer',     label: __( 'Referrer URL',                   'gratora' ) },
    { value: 'landing',      label: __( 'Landing page URL',               'gratora' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { field = '', source = 'fixed', queryParam = '', defaultValue = '', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--hidden' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Hidden field', 'gratora' ) } initialOpen>
                    <TextControl
                        label={ __( 'Field key', 'gratora' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: v.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) }
                        help={ __( 'Lowercase, underscores. This is the column name in donation reports.', 'gratora' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Source', 'gratora' ) }
                        value={ source }
                        options={ SOURCES }
                        onChange={ ( v ) => setAttributes( { source: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { source === 'query' && (
                        <TextControl
                            label={ __( 'Query parameter name', 'gratora' ) }
                            value={ queryParam }
                            onChange={ ( v ) => setAttributes( { queryParam: v } ) }
                            placeholder="appeal_code"
                            __nextHasNoMarginBottom
                        />
                    ) }
                    <TextControl
                        label={ __( 'Fallback value', 'gratora' ) }
                        value={ defaultValue }
                        onChange={ ( v ) => setAttributes( { defaultValue: v } ) }
                        help={ __( 'Used when the source above resolves to empty (e.g. donor arrived directly).', 'gratora' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span className="gratora-block-preview__hidden-tag">{ __( 'Hidden', 'gratora' ) }</span>
                <span className="gratora-block-preview__hidden-meta">
                    { field ? `${ field } ← ${ source }` : __( '(no field key set)', 'gratora' ) }
                </span>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Hidden field', 'gratora' ),
        description: __( 'Invisible value captured with the donation. Use it for UTM tags, referrer URL, or any appeal code.', 'gratora' ),
        category:    'gratora-fields',
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
