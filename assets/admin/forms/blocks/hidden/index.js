/**
 * giveflow/hidden: invisible value capture (UTM, referrer, appeal code).
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

const NAME = 'giveflow/hidden';

const SOURCES = [
    { value: 'fixed',        label: __( 'Fixed value',                    'giveflow-fundraising-campaigns' ) },
    { value: 'query',        label: __( 'URL query string',               'giveflow-fundraising-campaigns' ) },
    { value: 'utm_source',   label: __( 'UTM: Source',                    'giveflow-fundraising-campaigns' ) },
    { value: 'utm_medium',   label: __( 'UTM: Medium',                    'giveflow-fundraising-campaigns' ) },
    { value: 'utm_campaign', label: __( 'UTM: Campaign',                  'giveflow-fundraising-campaigns' ) },
    { value: 'utm_term',     label: __( 'UTM: Term',                      'giveflow-fundraising-campaigns' ) },
    { value: 'utm_content',  label: __( 'UTM: Content',                   'giveflow-fundraising-campaigns' ) },
    { value: 'referrer',     label: __( 'Referrer URL',                   'giveflow-fundraising-campaigns' ) },
    { value: 'landing',      label: __( 'Landing page URL',               'giveflow-fundraising-campaigns' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { field = '', source = 'fixed', queryParam = '', defaultValue = '', condition = DEFAULT_CONDITION } = attributes;
    const blockProps = useBlockProps( { className: 'giveflow-block-preview giveflow-block-preview--hidden' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Hidden field', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <TextControl
                        label={ __( 'Field key', 'giveflow-fundraising-campaigns' ) }
                        value={ field }
                        onChange={ ( v ) => setAttributes( { field: v.replace( /[^a-z0-9_]/gi, '_' ).toLowerCase() } ) }
                        help={ __( 'Lowercase, underscores. This is the column name in donation reports.', 'giveflow-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Source', 'giveflow-fundraising-campaigns' ) }
                        value={ source }
                        options={ SOURCES }
                        onChange={ ( v ) => setAttributes( { source: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { source === 'query' && (
                        <TextControl
                            label={ __( 'Query parameter name', 'giveflow-fundraising-campaigns' ) }
                            value={ queryParam }
                            onChange={ ( v ) => setAttributes( { queryParam: v } ) }
                            placeholder="appeal_code"
                            __nextHasNoMarginBottom
                        />
                    ) }
                    <TextControl
                        label={ __( 'Fallback value', 'giveflow-fundraising-campaigns' ) }
                        value={ defaultValue }
                        onChange={ ( v ) => setAttributes( { defaultValue: v } ) }
                        help={ __( 'Used when the source above resolves to empty (e.g. donor arrived directly).', 'giveflow-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span className="giveflow-block-preview__hidden-tag">{ __( 'Hidden', 'giveflow-fundraising-campaigns' ) }</span>
                <span className="giveflow-block-preview__hidden-meta">
                    { field ? `${ field } ← ${ source }` : __( '(no field key set)', 'giveflow-fundraising-campaigns' ) }
                </span>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Hidden field', 'giveflow-fundraising-campaigns' ),
        description: __( 'Invisible value captured with the donation. Use it for UTM tags, referrer URL, or any appeal code.', 'giveflow-fundraising-campaigns' ),
        category:    'giveflow-fields',
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
