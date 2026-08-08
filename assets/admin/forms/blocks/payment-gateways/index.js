import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, TextControl, SelectControl, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/payment-gateways';

function registeredGateways() {
    const g = typeof window !== 'undefined' && window.donoFormsEditor && window.donoFormsEditor.gateways;
    return Array.isArray( g ) ? g : [];
}

function Edit( { attributes, setAttributes } ) {
    const { allowed = [], descriptions = {}, style = 'cards', preselected = '' } = attributes;
    const gateways = registeredGateways();
    const allIds   = gateways.map( ( g ) => g.id );

    // Empty allowed means "offer all" - the donor-facing resolver treats it
    // the same. A gateway is on when the list is empty or names it.
    const isOn = ( id ) => allowed.length === 0 || allowed.includes( id );

    const toggle = ( id ) => {
        const on   = new Set( allowed.length === 0 ? allIds : allowed );
        if ( on.has( id ) ) on.delete( id ); else on.add( id );
        const next = allIds.filter( ( x ) => on.has( x ) );
        // All selected collapses back to [] (no restriction).
        setAttributes( { allowed: next.length === allIds.length ? [] : next } );
    };

    const setDesc = ( id, text ) =>
        setAttributes( { descriptions: { ...descriptions, [ id ]: text } } );

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--gateways' } );
    // Off in Settings means no donor sees it, whatever this form allows, so
    // it is left out of the preview and cannot be preselected.
    const live  = gateways.filter( ( g ) => g.enabled !== false );
    const shown = live.filter( ( g ) => isOn( g.id ) );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Payment gateways', 'dono' ) } initialOpen>
                    { gateways.length === 0 && (
                        <Notice status="warning" isDismissible={ false }>
                            { __( 'No gateways are connected yet. Set one up in Settings, Payment gateways.', 'dono' ) }
                        </Notice>
                    ) }
                    { gateways.map( ( g ) => (
                        <div key={ g.id } style={ { marginBottom: 12 } }>
                            <ToggleControl
                                label={ g.enabled === false
                                    ? `${ g.label } ${ __( '(off in Settings)', 'dono' ) }`
                                    : g.label }
                                checked={ isOn( g.id ) }
                                onChange={ () => toggle( g.id ) }
                                __nextHasNoMarginBottom
                            />
                            { isOn( g.id ) && (
                                <TextControl
                                    label={ __( 'Description (optional)', 'dono' ) }
                                    value={ descriptions[ g.id ] || '' }
                                    onChange={ ( v ) => setDesc( g.id, v ) }
                                    __nextHasNoMarginBottom
                                />
                            ) }
                        </div>
                    ) ) }
                    <SelectControl
                        label={ __( 'Preselected', 'dono' ) }
                        help={ __( 'Skipped for a donor whose currency or frequency it cannot take, who then gets the first one that works.', 'dono' ) }
                        value={ shown.some( ( g ) => g.id === preselected ) ? preselected : '' }
                        options={ [
                            { value: '', label: __( 'First one that applies', 'dono' ) },
                            ...shown.map( ( g ) => ( { value: g.id, label: g.label } ) ),
                        ] }
                        onChange={ ( v ) => setAttributes( { preselected: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Style', 'dono' ) }
                        value={ style }
                        options={ [
                            { value: 'cards', label: __( 'Cards', 'dono' ) },
                            { value: 'list',  label: __( 'Compact list', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { style: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <span className="dono-block-preview__label">{ __( 'Payment method', 'dono' ) }</span>
                { shown.length === 0
                    ? <div className="dono-block-preview__field">{ __( 'Gateways appear here for the donor.', 'dono' ) }</div>
                    : shown.map( ( g ) => (
                        <div key={ g.id } className="dono-block-preview__field">
                            { g.label }
                            { descriptions[ g.id ] ? ' - ' + descriptions[ g.id ] : '' }
                        </div>
                    ) ) }
                { shown.length === 1 && (
                    <em className="dono-block-preview__hint">
                        { __( 'With one gateway the selector is hidden for donors.', 'dono' ) }
                    </em>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Payment gateways', 'dono' ),
        description: __( 'Lets the donor choose how to pay. Hidden automatically when only one applies.', 'dono' ),
        category:   'dono-amount',
        icon:       BlockIcons[ 'payment-gateways' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            allowed:      { type: 'array',  default: [] },
            descriptions: { type: 'object', default: {} },
            style:        { type: 'string', default: 'cards' },
            preselected:  { type: 'string', default: '' },
        },
        edit: Edit,
        save: () => null,
    } );
}
