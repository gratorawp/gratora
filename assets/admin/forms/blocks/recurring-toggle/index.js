import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl, Notice } from '@wordpress/components';
import Segmented from '../../../_shared/components/Segmented';
import Field from '../../../_shared/components/Field';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/recurring-toggle';

const FREQ_OPTIONS = [
    { value: 'one-time',  label: __( 'One-time', 'fundraising-toolkit' ) },
    { value: 'weekly',    label: __( 'Weekly', 'fundraising-toolkit' ) },
    { value: 'biweekly',  label: __( 'Every 2 weeks', 'fundraising-toolkit' ) },
    { value: 'monthly',   label: __( 'Monthly', 'fundraising-toolkit' ) },
    { value: 'quarterly', label: __( 'Quarterly', 'fundraising-toolkit' ) },
    { value: 'yearly',    label: __( 'Yearly', 'fundraising-toolkit' ) },
];

// One-time is not listed: every form accepts a single donation, so the server
// prepends it and a checkbox for it would never turn off.
const RECURRING_OPTIONS = FREQ_OPTIONS.filter( ( f ) => f.value !== 'one-time' );

const DEFAULT_FREQUENCIES = [ 'one-time', 'monthly' ];

function Edit( { attributes, setAttributes } ) {
    const {
        label            = '',
        helpText         = '',
        style            = 'pills',
        defaultFrequency = 'one-time',
        frequencies      = DEFAULT_FREQUENCIES,
        condition        = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--recurring' } );

    const toggleFrequency = ( freq ) => {
        const current = Array.isArray( frequencies ) ? frequencies : [];
        const next = current.includes( freq )
            ? current.filter( ( f ) => f !== freq )
            : [ ...current, freq ];
        setAttributes( { frequencies: next } );
    };

    const safeFreqs = Array.isArray( frequencies ) ? frequencies : DEFAULT_FREQUENCIES;

    // Mirror the server cascade (DonationFormShortcode walker): normalize to
    // allowed values, dedup, auto-prepend one-time. The block renders nothing
    // when < 2 remain, so the editor preview shows the exact same pills the
    // donor would see, including the auto-prepended one-time.
    const effectiveFreqs = ( () => {
        const allowed = FREQ_OPTIONS.map( ( o ) => o.value );
        const uniq = [ ...new Set( safeFreqs.filter( ( f ) => allowed.includes( f ) ) ) ];
        if ( uniq.length && ! uniq.includes( 'one-time' ) ) uniq.unshift( 'one-time' );
        return uniq;
    } )();
    const previewKeys = effectiveFreqs.length > 0 ? effectiveFreqs : DEFAULT_FREQUENCIES;
    const willHide    = effectiveFreqs.length < 2;

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Recurring toggle', 'fundraising-toolkit' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'fundraising-toolkit' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Style', 'fundraising-toolkit' ) }
                        value={ style }
                        onChange={ ( v ) => setAttributes( { style: v } ) }
                        options={ [
                            { value: 'pills', label: __( 'Pills', 'fundraising-toolkit' ) },
                            { value: 'tabs',  label: __( 'Tabs',  'fundraising-toolkit' ) },
                        ] }
                    />
                    <Field
                        group
                        label={ __( 'Recurring options', 'fundraising-toolkit' ) }
                        help={ __( 'Donors can always give once. Pick the recurring options to offer alongside it.', 'fundraising-toolkit' ) }
                    >
                        <div className="fundkit-sidebar-list">
                            { RECURRING_OPTIONS.map( ( f ) => (
                                <label key={ f.value } className="fundkit-sidebar-check">
                                    <input
                                        type="checkbox"
                                        checked={ safeFreqs.includes( f.value ) }
                                        onChange={ () => toggleFrequency( f.value ) }
                                    />
                                    <span>{ f.label }</span>
                                </label>
                            ) ) }
                        </div>
                    </Field>
                    { willHide && (
                        <Notice status="warning" isDismissible={ false }>
                            { __( 'Pick at least one recurring option, or this block will not appear on the form.', 'fundraising-toolkit' ) }
                        </Notice>
                    ) }
                    <SelectControl
                        label={ __( 'Default selection', 'fundraising-toolkit' ) }
                        value={ defaultFrequency }
                        options={ FREQ_OPTIONS.filter( ( f ) => effectiveFreqs.includes( f.value ) ) }
                        onChange={ ( v ) => setAttributes( { defaultFrequency: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="div"
                    className="fundkit-block-preview__title"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Make this recurring', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                    style={ { fontSize: 13, fontWeight: 500, marginBottom: 6 } }
                />
                <div
                    style={ {
                        display:       'flex',
                        gap:           style === 'tabs' ? 0 : 6,
                        borderBottom:  style === 'tabs' ? '1px solid var(--fundkit-border, #e5e7eb)' : 'none',
                    } }
                >
                    { previewKeys.map( ( key ) => {
                        const opt = FREQ_OPTIONS.find( ( f ) => f.value === key );
                        if ( ! opt ) return null;
                        const selected = key === defaultFrequency
                            || ( ! previewKeys.includes( defaultFrequency ) && key === previewKeys[ 0 ] );
                        if ( style === 'tabs' ) {
                            return (
                                <span
                                    key={ key }
                                    style={ {
                                        padding:      '8px 14px',
                                        fontSize:     12,
                                        fontWeight:   selected ? 600 : 400,
                                        color:        selected ? 'var(--fundkit-accent, #211d3f)' : 'var(--fundkit-text-muted, #555)',
                                        borderBottom: selected ? '2px solid var(--fundkit-accent, #211d3f)' : '2px solid transparent',
                                        marginBottom: -1,
                                    } }
                                >
                                    { opt.label }
                                </span>
                            );
                        }
                        return (
                            <span
                                key={ key }
                                style={ {
                                    padding:      '6px 14px',
                                    fontSize:     12,
                                    fontWeight:   500,
                                    borderRadius: 'var(--fundkit-radius-sm, 8px)',
                                    background:   selected ? 'var(--fundkit-accent, #211d3f)' : 'var(--fundkit-bg-soft, #f0f0f1)',
                                    color:        selected ? 'var(--fundkit-on-accent, #fff)' : 'var(--fundkit-text-muted, #333)',
                                } }
                            >
                                { opt.label }
                            </span>
                        );
                    } ) }
                </div>
                { helpText !== '' && (
                    <RichText
                        tagName="p"
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Help text', 'fundraising-toolkit' ) }
                        allowedFormats={ [] }
                        style={ { fontSize: 11, color: '#6b7280', margin: '6px 0 0', lineHeight: 1.4 } }
                    />
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Recurring toggle', 'fundraising-toolkit' ),
        description: __( 'Frequency selector (one-time / monthly / yearly / etc).', 'fundraising-toolkit' ),
        category:   'fundkit-amount',
        icon:       BlockIcons[ 'recurring-toggle' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:            { type: 'string', default: '' },
            helpText:         { type: 'string', default: '' },
            style:            { type: 'string', default: 'pills' },
            defaultFrequency: { type: 'string', default: 'one-time' },
            frequencies:      { type: 'array',  default: [ 'one-time', 'monthly' ] },
            condition:        { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
