import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, Spinner, Notice, ExternalLink } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'dono/currency-switcher';

const SETTINGS_URL = 'admin.php?page=dono-settings';

function Edit( { attributes, setAttributes } ) {
    const { currencies = [], label = '', style = 'dropdown', align = 'left' } = attributes;
    const blockProps = useBlockProps( { className: 'dono-block-preview' } );

    // org = { base, currencies: [codes] } enabled under Settings → Currency.
    const [ org, setOrg ] = useState( null );

    useEffect( () => {
        let cancelled = false;
        apiFetch( { path: '/dono/v1/admin/forms/currencies' } )
            .then( ( r ) => { if ( ! cancelled ) setOrg( r && Array.isArray( r.currencies ) ? r : { base: '', currencies: [] } ); } )
            .catch( () => { if ( ! cancelled ) setOrg( { base: '', currencies: [] } ); } );
        return () => { cancelled = true; };
    }, [] );

    const available = org?.currencies || [];
    const base      = org?.base || '';

    // Effective selection: only org-enabled codes, base always included.
    const isOn = ( code ) =>
        code === base || ( currencies.includes( code ) && available.includes( code ) );

    const toggle = ( code ) => {
        if ( code === base ) return; // base is always offered
        const next = available.filter(
            ( c ) => c === code ? ! isOn( c ) : isOn( c )
        );
        setAttributes( { currencies: next.length ? next : [ base ].filter( Boolean ) } );
    };

    const selected = available.filter( isOn );

    const manageLink = (
        <p style={ { margin: '10px 0 0' } }>
            <ExternalLink href={ SETTINGS_URL }>
                { __( 'Manage enabled currencies', 'dono' ) }
            </ExternalLink>
        </p>
    );

    let panelBody;
    if ( org === null ) {
        panelBody = <div style={ { display: 'flex', justifyContent: 'center', padding: 12 } }><Spinner /></div>;
    } else if ( available.length === 0 ) {
        panelBody = (
            <Notice status="warning" isDismissible={ false }>
                { __( 'No currencies are enabled yet. Enable them under Settings → Currency.', 'dono' ) }
            </Notice>
        );
    } else if ( available.length === 1 ) {
        panelBody = (
            <Notice status="warning" isDismissible={ false }>
                { __( 'Only one currency is enabled, so there is nothing for donors to switch between. Enable more under Settings → Currency.', 'dono' ) }
            </Notice>
        );
    } else {
        panelBody = (
            <>
                <p style={ { margin: '0 0 8px', fontSize: 12, color: '#6b7280' } }>
                    { __( 'Choose which of your enabled currencies donors can switch between on this form.', 'dono' ) }
                </p>
                <div style={ { display: 'flex', flexWrap: 'wrap', gap: 8 } }>
                    { available.map( ( code ) => {
                        const on     = isOn( code );
                        const locked = code === base;
                        return (
                            <button
                                type="button"
                                key={ code }
                                onClick={ () => toggle( code ) }
                                aria-pressed={ on }
                                disabled={ locked }
                                style={ {
                                    display:      'inline-flex',
                                    alignItems:   'center',
                                    gap:          6,
                                    padding:      '6px 11px',
                                    borderRadius: 999,
                                    fontSize:     12.5,
                                    cursor:       locked ? 'default' : 'pointer',
                                    border:       `1px solid ${ on ? '#1e8a4e' : '#e5e7eb' }`,
                                    background:   locked ? '#f3f4f6' : on ? '#f3faf5' : '#fff',
                                    color:        on ? '#14693a' : '#111827',
                                    fontWeight:   on ? 600 : 400,
                                } }
                            >
                                <span
                                    style={ {
                                        width: 13, height: 13, borderRadius: 4,
                                        display: 'grid', placeItems: 'center',
                                        border: `1.5px solid ${ on ? '#1e8a4e' : '#c4c9d0' }`,
                                        background: on ? '#1e8a4e' : '#fff',
                                        color: '#fff',
                                    } }
                                >
                                    { on && (
                                        <svg viewBox="0 0 12 12" width="8" height="8" aria-hidden="true">
                                            <path d="M2 6l3 3 5-6" fill="none" stroke="currentColor" stroke-width="2" />
                                        </svg>
                                    ) }
                                </span>
                                { code }
                                { locked && (
                                    <span style={ { fontSize: 10, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.04em' } }>
                                        { __( 'base', 'dono' ) }
                                    </span>
                                ) }
                            </button>
                        );
                    } ) }
                </div>
                { manageLink }
            </>
        );
    }

    const previewCodes = selected.length ? selected : ( base ? [ base ] : [] );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Currency switcher', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Currency', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Style', 'dono' ) }
                        value={ style }
                        onChange={ ( v ) => setAttributes( { style: v } ) }
                        options={ [
                            { value: 'dropdown', label: __( 'Dropdown', 'dono' ) },
                            { value: 'pills',    label: __( 'Pills', 'dono' ) },
                        ] }
                    />
                    <Segmented
                        label={ __( 'Alignment', 'dono' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ [
                            { value: 'left',  label: __( 'Left', 'dono' ) },
                            { value: 'right', label: __( 'Right', 'dono' ) },
                        ] }
                    />
                    { panelBody }
                </PanelBody>
            </InspectorControls>
            <div
                { ...blockProps }
                style={ {
                    display:        'flex',
                    alignItems:     'center',
                    gap:            10,
                    justifyContent: align === 'right' ? 'flex-end' : 'flex-start',
                } }
            >
                { label && (
                    <span className="dono-block-preview__label">{ label }</span>
                ) }
                { style === 'pills' ? (
                    // Mirror the runtime .dono-form__currency-pills look so
                    // Develop matches Preview.
                    <span
                        style={ {
                            display:      'inline-flex',
                            gap:          6,
                            flexWrap:     'wrap',
                            padding:      4,
                            background:   'var(--dono-bg-soft, #f8fafb)',
                            borderRadius: 'var(--dono-switcher-radius, var(--dono-radius-sm, 8px))',
                        } }
                    >
                        { previewCodes.map( ( c, i ) => {
                            const on = i === 0;
                            return (
                                <span
                                    key={ c }
                                    style={ {
                                        padding:      '6px 14px',
                                        borderRadius: 'var(--dono-switcher-radius, var(--dono-radius-sm, 8px))',
                                        fontSize:     13,
                                        fontWeight:   500,
                                        background:   on ? 'var(--dono-accent, #1e8a4e)' : 'transparent',
                                        color:        on ? 'var(--dono-on-accent, #fff)' : '#6b7280',
                                    } }
                                >
                                    { c }
                                </span>
                            );
                        } ) }
                    </span>
                ) : (
                    <span
                        style={ {
                            display:      'inline-flex',
                            alignItems:   'center',
                            gap:          8,
                            padding:      '6px 10px',
                            border:       '1px solid var(--dono-border, #e5e7eb)',
                            borderRadius: 'var(--dono-switcher-radius, var(--dono-radius-sm, 8px))',
                            fontSize:     13,
                            background:   'var(--dono-bg, #fff)',
                        } }
                    >
                        { previewCodes[ 0 ] || '-' }
                        <span aria-hidden="true" style={ { color: '#9ca3af' } }>▾</span>
                    </span>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Currency switcher', 'dono' ),
        description: __( 'Lets the donor pick which currency to donate in.', 'dono' ),
        category:   'dono-amount',
        icon:       BlockIcons[ 'currency-switcher' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            currencies: { type: 'array',  default: [] },
            label:      { type: 'string', default: '' },
            style:      { type: 'string', default: 'dropdown' },
            align:      { type: 'string', default: 'left' },
        },
        edit: Edit,
        save: () => null,
    } );
}
