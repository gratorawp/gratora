import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl, TextControl, SelectControl, Notice } from '@wordpress/components';
import { __, _n, sprintf } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { gatewayIsOn, toggleGatewayAllowed } from '../../../_shared/gatewayAllowList';

const NAME = 'giveflow/payment-gateways';

/**
 * Why the count is lower than the switches suggest. Without this the hint reads
 * "one gateway is live" beside two gateways switched on.
 */
function settingsReason( offInSettings ) {
    const names = offInSettings.map( ( g ) => g.label ).join( ', ' );

    /* translators: %s: comma-separated payment gateway names. */
    const template = _n(
        '%s is allowed here but switched off in Settings.',
        '%s are allowed here but switched off in Settings.',
        offInSettings.length,
        'giveflow-fundraising-campaigns'
    );

    return sprintf( template, names );
}

function registeredGateways() {
    const g = typeof window !== 'undefined' && window.giveflowFormsEditor && window.giveflowFormsEditor.gateways;
    return Array.isArray( g ) ? g : [];
}

function Edit( { attributes, setAttributes } ) {
    const { allowed = [], descriptions = {}, style = 'cards', preselected = '' } = attributes;
    const gateways = registeredGateways();
    const allIds   = gateways.map( ( g ) => g.id );

    const isOn   = ( id ) => gatewayIsOn( allowed, id );
    const toggle = ( id ) => setAttributes( { allowed: toggleGatewayAllowed( allowed, id, allIds ) } );

    const setDesc = ( id, text ) =>
        setAttributes( { descriptions: { ...descriptions, [ id ]: text } } );

    const blockProps = useBlockProps( { className: 'giveflow-block-preview giveflow-block-preview--gateways' } );
    // Off in Settings means no donor sees it, whatever this form allows, so
    // it is left out of the preview and cannot be preselected.
    const live  = gateways.filter( ( g ) => g.enabled !== false );
    const shown = live.filter( ( g ) => isOn( g.id ) );
    // Switched on here, switched off org-wide: the gap between what the
    // toggles say and what the donor gets.
    const offInSettings = gateways.filter( ( g ) => g.enabled === false && isOn( g.id ) );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Payment gateways', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    { gateways.length === 0 && (
                        <Notice status="warning" isDismissible={ false }>
                            { __( 'No gateways are connected yet. Set one up in Settings, Payment gateways.', 'giveflow-fundraising-campaigns' ) }
                        </Notice>
                    ) }
                    { gateways.map( ( g ) => (
                        <div key={ g.id } style={ { marginBottom: 12 } }>
                            <ToggleControl
                                label={ g.enabled === false
                                    ? `${ g.label } ${ __( '(off in Settings)', 'giveflow-fundraising-campaigns' ) }`
                                    : g.label }
                                checked={ isOn( g.id ) }
                                onChange={ () => toggle( g.id ) }
                                __nextHasNoMarginBottom
                            />
                            { isOn( g.id ) && (
                                <TextControl
                                    label={ __( 'Description (optional)', 'giveflow-fundraising-campaigns' ) }
                                    value={ descriptions[ g.id ] || '' }
                                    onChange={ ( v ) => setDesc( g.id, v ) }
                                    __nextHasNoMarginBottom
                                />
                            ) }
                        </div>
                    ) ) }
                    <SelectControl
                        label={ __( 'Preselected', 'giveflow-fundraising-campaigns' ) }
                        help={ __( 'Skipped for a donor whose currency or frequency it cannot take, who then gets the first one that works.', 'giveflow-fundraising-campaigns' ) }
                        value={ shown.some( ( g ) => g.id === preselected ) ? preselected : '' }
                        options={ [
                            { value: '', label: __( 'First one that applies', 'giveflow-fundraising-campaigns' ) },
                            ...shown.map( ( g ) => ( { value: g.id, label: g.label } ) ),
                        ] }
                        onChange={ ( v ) => setAttributes( { preselected: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Style', 'giveflow-fundraising-campaigns' ) }
                        value={ style }
                        options={ [
                            { value: 'cards', label: __( 'Cards', 'giveflow-fundraising-campaigns' ) },
                            { value: 'list',  label: __( 'Compact list', 'giveflow-fundraising-campaigns' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { style: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <span className="giveflow-block-preview__label">{ __( 'Payment method', 'giveflow-fundraising-campaigns' ) }</span>
                { shown.length === 0
                    ? <div className="giveflow-block-preview__field">{ __( 'Gateways appear here for the donor.', 'giveflow-fundraising-campaigns' ) }</div>
                    : shown.map( ( g ) => (
                        <div key={ g.id } className="giveflow-block-preview__field">
                            { g.label }
                            { descriptions[ g.id ] ? ' - ' + descriptions[ g.id ] : '' }
                        </div>
                    ) ) }
                { shown.length <= 1 && (
                    <em className="giveflow-block-preview__hint">
                        { shown.length === 1
                            ? __( 'One gateway is live, so the selector is hidden for donors.', 'giveflow-fundraising-campaigns' )
                            : __( 'No gateway is live, so donors see nothing here.', 'giveflow-fundraising-campaigns' ) }
                        { offInSettings.length > 0 && ' ' + settingsReason( offInSettings ) }
                    </em>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Payment gateways', 'giveflow-fundraising-campaigns' ),
        description: __( 'Lets the donor choose how to pay. Hidden automatically when only one applies.', 'giveflow-fundraising-campaigns' ),
        category:   'giveflow-amount',
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
