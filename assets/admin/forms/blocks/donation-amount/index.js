import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatAmount, defaultCurrency } from '../../../_shared/format';
import { BlockIcons } from '../_shared/block-icons';
import Segmented from '../../../_shared/components/Segmented';
import AmountInput from '../../../_shared/components/AmountInput';

const NAME = 'dono/donation-amount';

// Stable per-row id so React keys survive reorder/delete instead of tracking
// array position. Persisted in attributes; the server normaliser ignores it.
const uid = () => Math.random().toString( 36 ).slice( 2, 9 );

const DEFAULT_PRESETS = [
    { id: 'preset-1', cents: 1000,  impact: '', preselected: false },
    { id: 'preset-2', cents: 2500,  impact: '', preselected: false },
    { id: 'preset-3', cents: 5000,  impact: '', preselected: false },
    { id: 'preset-4', cents: 10000, impact: '', preselected: false },
];

function normalizePresets( presets ) {
    if ( ! Array.isArray( presets ) ) return DEFAULT_PRESETS;
    return presets.map( ( p ) => {
        if ( typeof p === 'number' ) return { cents: Math.max( 0, p ), impact: '', preselected: false };
        return {
            id:      p?.id,
            cents:   Math.max( 0, Number( p?.cents ) || 0 ),
            impact:  String( p?.impact || '' ),
            preselected: !! p?.preselected,
        };
    } );
}

function Edit( { attributes, setAttributes, clientId } ) {
    // Preview in the org currency, not a hardcoded USD, when the block has none.
    const { allowCustom = true, currency: currencyAttr = '', donationType = 'multi', minCents = 0 } = attributes;
    const currency = currencyAttr || defaultCurrency();
    const presets = normalizePresets( attributes.presets );

    const blockProps = useBlockProps( {
        style: { padding: 16 },
    } );

    const [ dragIndex, setDragIndex ] = useState( null );

    const updatePreset = ( i, patch ) => {
        const next = presets.map( ( p, idx ) => idx === i ? { ...p, ...patch } : p );
        setAttributes( { presets: next } );
    };

    const addPreset = () => {
        const lastCents = presets.length ? presets[ presets.length - 1 ].cents : 0;
        const nextCents = lastCents > 0 ? lastCents * 2 : 1000;
        setAttributes( { presets: [ ...presets, { id: uid(), cents: nextCents, impact: '', preselected: false } ] } );
    };

    const removePreset = ( i ) => {
        if ( presets.length <= 1 ) return;
        setAttributes( { presets: presets.filter( ( _, idx ) => idx !== i ) } );
    };

    const reorder = ( from, to ) => {
        if ( from === null || from === to ) return;
        if ( from < 0 || to < 0 || from >= presets.length || to >= presets.length ) return;
        const next = presets.slice();
        const [ moved ] = next.splice( from, 1 );
        next.splice( to, 0, moved );
        setAttributes( { presets: next } );
    };

    const setPreselected = ( i ) => setAttributes( {
        presets: presets.map( ( p, idx ) => ( {
            ...p,
            preselected: idx === i ? ! p.preselected : false,
        } ) ),
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Amounts', 'dono' ) } initialOpen>
                    <Segmented
                        label={ __( 'Donation type', 'dono' ) }
                        value={ donationType }
                        onChange={ ( v ) => setAttributes( { donationType: v } ) }
                        options={ [
                            { value: 'multi', label: __( 'Multi-level', 'dono' ) },
                            { value: 'fixed', label: __( 'Open amount', 'dono' ) },
                        ] }
                    />
                    <p style={ { margin: '6px 0 12px', fontSize: 11, color: '#6b7280' } }>
                        { donationType === 'fixed'
                            ? __( 'Donors enter any amount. No preset tiles.', 'dono' )
                            : __( 'Show preset amounts donors can pick from.', 'dono' ) }
                    </p>

                    { donationType === 'multi' && (
                    <>
                    <ToggleControl
                        label={ __( 'Allow custom amount', 'dono' ) }
                        checked={ allowCustom }
                        onChange={ ( v ) => setAttributes( { allowCustom: v } ) }
                        __nextHasNoMarginBottom
                    />

                    { allowCustom && (
                        <TextControl
                            type="number"
                            min="0"
                            step="0.01"
                            label={ __( 'Minimum amount', 'dono' ) }
                            help={ __( 'Leave empty for no minimum beyond the site default.', 'dono' ) }
                            value={ minCents ? String( minCents / 100 ) : '' }
                            onChange={ ( v ) => setAttributes( {
                                minCents: v === '' ? 0 : Math.max( 0, Math.round( parseFloat( v ) * 100 ) || 0 ),
                            } ) }
                            __nextHasNoMarginBottom
                            __next40pxDefaultSize
                        />
                    ) }
                    <div className="dono-amounts-head">
                        <span className="dono-amounts-head__label">{ __( 'Options', 'dono' ) }</span>
                        <button
                            type="button"
                            className="dono-amounts-add"
                            onClick={ addPreset }
                            aria-label={ __( 'Add amount', 'dono' ) }
                            title={ __( 'Add amount', 'dono' ) }
                        >
                            +
                        </button>
                    </div>

                    { presets.map( ( p, i ) => (
                        <div
                            key={ p.id || i }
                            className={ `dono-preset-row${ dragIndex === i ? ' is-dragging' : '' }` }
                            onDragOver={ ( e ) => e.preventDefault() }
                            onDrop={ () => { reorder( dragIndex, i ); setDragIndex( null ); } }
                        >
                            <span
                                className="dono-preset-row__drag"
                                draggable
                                tabIndex={ 0 }
                                onDragStart={ ( e ) => {
                                    e.dataTransfer.effectAllowed = 'move';
                                    try { e.dataTransfer.setData( 'text/plain', String( i ) ); } catch ( _e ) {}
                                    setDragIndex( i );
                                } }
                                onDragEnd={ () => setDragIndex( null ) }
                                onKeyDown={ ( e ) => {
                                    if ( e.key === 'ArrowUp' )   { e.preventDefault(); reorder( i, i - 1 ); }
                                    if ( e.key === 'ArrowDown' ) { e.preventDefault(); reorder( i, i + 1 ); }
                                } }
                                role="button"
                                aria-label={ __( 'Drag to reorder, or use the arrow keys', 'dono' ) }
                                title={ __( 'Drag to reorder', 'dono' ) }
                            >
                                ⠿
                            </span>
                            <input
                                type="radio"
                                className="dono-preset-row__radio"
                                name={ `dono-amount-highlight-${ clientId }` }
                                checked={ !! p.preselected }
                                onChange={ () => setPreselected( i ) }
                                onClick={ () => { if ( p.preselected ) setPreselected( i ); } }
                                aria-label={ __( 'Preselect this amount', 'dono' ) }
                                title={ __( 'Preselect this amount', 'dono' ) }
                            />
                            <span className="dono-preset-row__amt">
                                <AmountInput
                                    value={ p.cents > 0 ? p.cents / 100 : 0 }
                                    onChange={ ( n ) => updatePreset( i, { cents: Math.max( 0, Math.round( Number( n || 0 ) * 100 ) ) } ) }
                                    currency={ currency }
                                    min={ 0 }
                                    symbolOnly
                                />
                            </span>
                            <button
                                type="button"
                                className="dono-preset-row__remove"
                                onClick={ () => removePreset( i ) }
                                disabled={ presets.length <= 1 }
                                aria-label={ __( 'Remove amount', 'dono' ) }
                                title={ __( 'Remove amount', 'dono' ) }
                            >
                                −
                            </button>
                        </div>
                    ) ) }
                    <p style={ { margin: '6px 0 0', fontSize: 11, color: '#6b7280' } }>
                        { __( 'Select a radio to preselect an amount.', 'dono' ) }
                    </p>
                    </>
                    ) }
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <div style={ { fontSize: 12, color: '#666', marginBottom: 8 } }>
                    { __( 'Donation amount', 'dono' ) }
                </div>
                { donationType === 'multi' && (
                <div style={ { display: 'grid', gridTemplateColumns: 'repeat(auto-fit, minmax(120px, 1fr))', gap: 8 } }>
                    { presets.map( ( p, i ) => {
                        // The runtime falls back to the first tile when the admin
                        // marked none, so outline it here to match what donors see.
                        const selected = p.preselected
                            || ( i === 0 && ! presets.some( ( x ) => x && x.preselected ) );
                        return (
                        <div
                            key={ p.id || i }
                            style={ {
                                padding:      '10px 12px',
                                background:   '#f0f0f1',
                                borderRadius: 'var(--dono-radius-sm, 6px)',
                                textAlign:    'center',
                                display:      'flex',
                                flexDirection: 'column',
                                gap:          2,
                                position:     'relative',
                                outline:      selected ? '2px solid var(--dono-accent, #1e8a4e)' : 'none',
                                outlineOffset: selected ? -2 : 0,
                            } }
                        >
                            <div style={ { fontSize: 15, fontWeight: 600, fontVariantNumeric: 'tabular-nums' } }>
                                { formatAmount( p.cents, currency ) }
                            </div>
                            <RichText
                                tagName="div"
                                value={ p.impact }
                                onChange={ ( v ) => updatePreset( i, { impact: v } ) }
                                placeholder={ __( 'Add a caption', 'dono' ) }
                                allowedFormats={ [] }
                                style={ {
                                    fontSize: 11,
                                    color:    '#666',
                                    minHeight: 14,
                                } }
                            />
                        </div>
                        );
                    } ) }
                </div>
                ) }
                { ( donationType === 'fixed' || allowCustom ) && (
                    <div
                        style={ {
                            marginTop:    donationType === 'multi' ? 8 : 0,
                            padding:      '10px 12px',
                            background:   '#fff',
                            border:       '1px solid #c3c4c7',
                            borderRadius: 'var(--dono-radius-sm, 6px)',
                            fontSize:     13,
                            color:        '#9ca3af',
                            textAlign:    'left',
                        } }
                    >
                        { __( 'Custom amount', 'dono' ) }
                    </div>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Donation amount', 'dono' ),
        description: __( 'Amount picker with preset buttons and an optional custom-amount input.', 'dono' ),
        category:   'dono-amount',
        icon:       BlockIcons[ 'donation-amount' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            presets:     { type: 'array', default: DEFAULT_PRESETS },
            allowCustom:      { type: 'boolean', default: true },
            currency:         { type: 'string',  default: '' },
            donationType:     { type: 'string',  default: 'multi' },
        },
        edit: Edit,
        save: () => null,
    } );
}
