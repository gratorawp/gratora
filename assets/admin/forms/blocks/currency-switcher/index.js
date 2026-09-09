import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, Spinner, Notice, ExternalLink, Button } from '@wordpress/components';
import { useState, useEffect, useRef, useCallback } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'gratora/currency-switcher';

const SETTINGS_URL = 'admin.php?page=gratora-settings#currency';

function Edit( { attributes, setAttributes } ) {
    const { currencies = [], label = '', style = 'dropdown', align = 'left' } = attributes;
    const blockProps = useBlockProps( { className: 'gratora-block-preview' } );

    // org = { base, currencies: [codes] } enabled under Settings → Currency.
    const [ org, setOrg ] = useState( null );

    // Tracked apart from the list: an empty list reads back as "no currencies
    // are enabled", a statement about the org rather than about the request,
    // and the route always answers with at least the base currency.
    const [ failed, setFailed ] = useState( false );
    const alive = useRef( true );
    useEffect( () => () => { alive.current = false; }, [] );

    const load = useCallback( () => {
        setOrg( null );
        setFailed( false );
        apiFetch( { path: '/gratora/v1/admin/forms/currencies' } )
            .then( ( r ) => { if ( alive.current ) setOrg( r && Array.isArray( r.currencies ) ? r : { base: '', currencies: [] } ); } )
            .catch( () => { if ( alive.current ) setFailed( true ); } );
    }, [] );

    useEffect( () => { load(); }, [ load ] );

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
                { __( 'Manage currencies', 'gratora' ) }
            </ExternalLink>
        </p>
    );

    let panelBody;
    if ( failed ) {
        panelBody = (
            <>
                <Notice status="error" isDismissible={ false }>
                    { __( 'The currencies this site offers could not be loaded, so this block cannot say which ones it will show.', 'gratora' ) }
                </Notice>
                <p style={ { margin: '10px 0 0' } }>
                    <Button variant="secondary" onClick={ load }>
                        { __( 'Try again', 'gratora' ) }
                    </Button>
                </p>
            </>
        );
    } else if ( org === null ) {
        panelBody = <div style={ { display: 'flex', justifyContent: 'center', padding: 12 } }><Spinner /></div>;
    } else if ( available.length === 0 ) {
        panelBody = (
            <>
                <Notice status="warning" isDismissible={ false }>
                    { __( 'No currencies are enabled yet.', 'gratora' ) }
                </Notice>
                { manageLink }
            </>
        );
    } else if ( available.length === 1 ) {
        panelBody = (
            <>
                <Notice status="warning" isDismissible={ false }>
                    { __( 'Only one currency is enabled, so there is nothing for donors to switch between.', 'gratora' ) }
                </Notice>
                { manageLink }
            </>
        );
    } else {
        panelBody = (
            <>
                <p style={ { margin: '0 0 8px', fontSize: 12, color: '#6b7280' } }>
                    { __( 'Choose which of your enabled currencies donors can switch between on this form.', 'gratora' ) }
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
                                    border:       `1px solid ${ on ? '#211d3f' : '#e5e7eb' }`,
                                    background:   locked ? '#f3f4f6' : on ? '#f5f4fa' : '#fff',
                                    color:        on ? '#34306b' : '#111827',
                                    fontWeight:   on ? 600 : 400,
                                } }
                            >
                                <span
                                    style={ {
                                        width: 13, height: 13, borderRadius: 4,
                                        display: 'grid', placeItems: 'center',
                                        border: `1.5px solid ${ on ? '#211d3f' : '#c4c9d0' }`,
                                        background: on ? '#211d3f' : '#fff',
                                        color: '#fff',
                                    } }
                                >
                                    { on && (
                                        <svg viewBox="0 0 12 12" width="8" height="8" aria-hidden="true">
                                            <path d="M2 6l3 3 5-6" fill="none" stroke="currentColor" strokeWidth="2" />
                                        </svg>
                                    ) }
                                </span>
                                { code }
                                { locked && (
                                    <span style={ { fontSize: 10, color: '#9ca3af', textTransform: 'uppercase', letterSpacing: '.04em' } }>
                                        { __( 'base', 'gratora' ) }
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
                <PanelBody title={ __( 'Currency switcher', 'gratora' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'gratora' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Currency', 'gratora' ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Style', 'gratora' ) }
                        value={ style }
                        onChange={ ( v ) => setAttributes( { style: v } ) }
                        options={ [
                            { value: 'dropdown', label: __( 'Dropdown', 'gratora' ) },
                            { value: 'pills',    label: __( 'Pills', 'gratora' ) },
                        ] }
                    />
                    <Segmented
                        label={ __( 'Alignment', 'gratora' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ [
                            { value: 'left',  label: __( 'Left', 'gratora' ) },
                            { value: 'right', label: __( 'Right', 'gratora' ) },
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
                    <span className="gratora-block-preview__label">{ label }</span>
                ) }
                { style === 'pills' ? (
                    // Mirror the runtime .gratora-form__currency-pills look so
                    // Develop matches Preview.
                    <span
                        style={ {
                            display:      'inline-flex',
                            gap:          6,
                            flexWrap:     'wrap',
                            padding:      4,
                            background:   'var(--gratora-bg-soft, #f8fafb)',
                            borderRadius: 'var(--gratora-switcher-radius, var(--gratora-radius-sm, 8px))',
                        } }
                    >
                        { previewCodes.map( ( c, i ) => {
                            const on = i === 0;
                            return (
                                <span
                                    key={ c }
                                    style={ {
                                        padding:      '6px 14px',
                                        borderRadius: 'var(--gratora-switcher-radius, var(--gratora-radius-sm, 8px))',
                                        fontSize:     13,
                                        fontWeight:   500,
                                        background:   on ? 'var(--gratora-accent, #211d3f)' : 'transparent',
                                        color:        on ? 'var(--gratora-on-accent, #fff)' : '#6b7280',
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
                            border:       '1px solid var(--gratora-border, #e5e7eb)',
                            borderRadius: 'var(--gratora-switcher-radius, var(--gratora-radius-sm, 8px))',
                            fontSize:     13,
                            background:   'var(--gratora-bg, #fff)',
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
        title:      __( 'Currency switcher', 'gratora' ),
        description: __( 'Lets the donor pick which currency to donate in.', 'gratora' ),
        category:   'gratora-amount',
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
