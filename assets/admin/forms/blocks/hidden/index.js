/**
 * dono/hidden: invisible value capture (UTM, referrer, appeal code).
 *
 * Renders nothing to the donor. The editor preview shows a thin badge so
 * authors can spot and edit it. Values are resolved from the URL, referrer,
 * or a fixed default and submitted with the donation payload as a custom field.
 */

import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'dono/hidden';

const SOURCES = [
    { value: 'fixed',        label: __( 'Fixed value',                    'dono' ) },
    { value: 'query',        label: __( 'URL query string',               'dono' ) },
    { value: 'utm_source',   label: __( 'UTM: Source',                    'dono' ) },
    { value: 'utm_medium',   label: __( 'UTM: Medium',                    'dono' ) },
    { value: 'utm_campaign', label: __( 'UTM: Campaign',                  'dono' ) },
    { value: 'utm_term',     label: __( 'UTM: Term',                      'dono' ) },
    { value: 'utm_content',  label: __( 'UTM: Content',                   'dono' ) },
    { value: 'referrer',     label: __( 'Referrer URL',                   'dono' ) },
    { value: 'landing',      label: __( 'Landing page URL',               'dono' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { field = '', source = 'fixed', queryParam = '', defaultValue = '', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--hidden' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Hidden field', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Field key', 'dono' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: v.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) }
                        help={ __( 'Lowercase, underscores. This is the column name in donation reports.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Source', 'dono' ) }
                        value={ source }
                        options={ SOURCES }
                        onChange={ ( v ) => setAttributes( { source: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { source === 'query' && (
                        <TextControl
                            label={ __( 'Query parameter name', 'dono' ) }
                            value={ queryParam }
                            onChange={ ( v ) => setAttributes( { queryParam: v } ) }
                            placeholder="appeal_code"
                            __nextHasNoMarginBottom
                        />
                    ) }
                    <TextControl
                        label={ __( 'Fallback value', 'dono' ) }
                        value={ defaultValue }
                        onChange={ ( v ) => setAttributes( { defaultValue: v } ) }
                        help={ __( 'Used when the source above resolves to empty (e.g. donor arrived directly).', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span className="dono-block-preview__hidden-tag">{ __( 'Hidden', 'dono' ) }</span>
                <span className="dono-block-preview__hidden-meta">
                    { field ? `${ field } ← ${ source }` : __( '(no field key set)', 'dono' ) }
                </span>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Hidden field', 'dono' ),
        description: __( 'Invisible value captured with the donation. Use it for UTM tags, referrer URL, or any appeal code.', 'dono' ),
        category:    'dono-fields',
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
