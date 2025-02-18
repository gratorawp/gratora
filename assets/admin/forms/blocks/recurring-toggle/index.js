import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, SelectControl, Notice } from '@wordpress/components';
import Segmented from '../../../_shared/components/Segmented';
import Field from '../../../_shared/components/Field';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/recurring-toggle';

const FREQ_OPTIONS = [
    { value: 'one-time',  label: __( 'One-time', 'dono' ) },
    { value: 'weekly',    label: __( 'Weekly', 'dono' ) },
    { value: 'biweekly',  label: __( 'Every 2 weeks', 'dono' ) },
    { value: 'monthly',   label: __( 'Monthly', 'dono' ) },
    { value: 'quarterly', label: __( 'Quarterly', 'dono' ) },
    { value: 'yearly',    label: __( 'Yearly', 'dono' ) },
];

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

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--recurring' } );

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
                <PanelBody title={ __( 'Recurring toggle', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        help={ __( 'Click the label in the canvas to edit it inline.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'dono' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Style', 'dono' ) }
                        value={ style }
                        onChange={ ( v ) => setAttributes( { style: v } ) }
                        options={ [
                            { value: 'pills', label: __( 'Pills', 'dono' ) },
                            { value: 'tabs',  label: __( 'Tabs',  'dono' ) },
                        ] }
                    />
                    <Field
                        label={ __( 'Allowed frequencies', 'dono' ) }
                        help={ __( 'Donors pick from these. The block hides itself if fewer than two are selected.', 'dono' ) }
                    >
                        <div className="dono-sidebar-list">
                            { FREQ_OPTIONS.map( ( f ) => (
                                <label key={ f.value } className="dono-sidebar-check">
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
                            { __( 'Enable at least two frequencies (for example one-time and monthly) or this block will not appear on the form.', 'dono' ) }
                        </Notice>
                    ) }
                    <SelectControl
                        label={ __( 'Default selection', 'dono' ) }
                        value={ defaultFrequency }
                        options={ FREQ_OPTIONS.filter( ( f ) => safeFreqs.includes( f.value ) ) }
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
                    className="dono-block-preview__title"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Make this recurring', 'dono' ) }
                    allowedFormats={ [] }
                    style={ { fontSize: 13, fontWeight: 500, marginBottom: 6 } }
                />
                <div
                    style={ {
                        display:       'flex',
                        gap:           style === 'tabs' ? 0 : 6,
                        borderBottom:  style === 'tabs' ? '1px solid var(--dono-border, #e5e7eb)' : 'none',
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
                                        color:        selected ? 'var(--dono-accent, #1e8a4e)' : 'var(--dono-text-muted, #555)',
                                        borderBottom: selected ? '2px solid var(--dono-accent, #1e8a4e)' : '2px solid transparent',
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
                                    borderRadius: 'var(--dono-radius-sm, 8px)',
                                    background:   selected ? 'var(--dono-accent, #1e8a4e)' : 'var(--dono-bg-soft, #f0f0f1)',
                                    color:        selected ? 'var(--dono-on-accent, #fff)' : 'var(--dono-text-muted, #333)',
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
                        placeholder={ __( 'Help text', 'dono' ) }
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
        title:      __( 'Recurring toggle', 'dono' ),
        description: __( 'Frequency selector (one-time / monthly / yearly / etc).', 'dono' ),
        category:   'dono-amount',
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
