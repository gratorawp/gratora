import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';

import { useExtensionPanels, ExtensionSection } from '../../_shared/extensionTabs';
import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import BrandMark from '../../_shared/components/BrandMark';
import useCardOpen from '../../_shared/useCardOpen';
import { ToggleRow } from '../../_shared/components/Switch';
import StripeKeysCard from './StripeKeysCard';
import PayPalKeysCard from './PayPalKeysCard';
import { bankDetailsSample } from './bankDetailsSample';

/** Placeholders the offline bank-details template expands at render time. */
const BANK_PLACEHOLDERS = [ '{amount}', '{reference}', '{donor_name}' ];

export default function GatewaysPanel( { s, org } ) {
    const bankRef         = useRef( null );
    const instructionsRef = useRef( null );

    // At the caret, matching the email templates: a placeholder belongs where
    // the line needs it, and these sit inside a formatted block where appending
    // to the end is never what was meant.
    const insertPlaceholder = ( tag ) => {
        const el      = bankRef.current;
        const current = String( s.value( 'offline.bank_details', '' ) );
        const write   = s.setValue( 'offline.bank_details' );

        if ( ! el ) {
            write( current + tag );
            return;
        }

        const start = el.selectionStart;
        const end   = el.selectionEnd;
        write( current.slice( 0, start ) + tag + current.slice( end ) );

        window.requestAnimationFrame( () => {
            el.focus();
            el.setSelectionRange( start + tag.length, start + tag.length );
        } );
    };

    // Gateways that ship in an add-on belong beside the ones core ships, not
    // in a tab of their own: an admin looking for how to take a payment should
    // find every answer in one place.
    const gatewayPanels = useExtensionPanels( 'settings-gateways' );

    const offlineEnabled = !! s.value( 'offline.enabled', true );

    // The same rule OfflineGateway::canCharge() applies: either field is a way
    // to pay, and whitespace is not. Checking only instructions told a site
    // that had written bank details it was not configured, and opened this card
    // to nag about it, while donors were paying through it perfectly well.
    const offlineConfigured = [ 'offline.instructions', 'offline.bank_details' ]
        .some( ( key ) => String( s.value( key, '' ) ).trim() !== '' );

    const [ offlineOpen, setOfflineOpen ] = useCardOpen( offlineEnabled && ! offlineConfigured, 'payments', 'offline' );

    const [ showOffline, setShowOffline ] = useState(
        () => ( window.location.hash || '' ).replace( /^#/, '' ) === 'gateways/offline'
    );

    useEffect( () => {
        if ( ! showOffline ) return;
        if ( ! offlineOpen ) {
            setOfflineOpen( true );
            return;
        }
        const box = instructionsRef.current;
        if ( box ) {
            box.scrollIntoView( { block: 'center' } );
            box.focus( { preventScroll: true } );
        }
        setShowOffline( false );
    }, [ showOffline, offlineOpen, setOfflineOpen ] );

    const country = org ? String( org.value( 'country', '' ) ) : '';
    const orgName = org ? String( org.value( 'name', '' ) ).trim() : '';

    const offlinePill = ! offlineEnabled
        ? <span className="gratora-pill gratora-pill--gray"><span className="gratora-pill__dot gratora-pill__dot--soft" />{ __( 'Disabled', 'gratora-donation-platform' ) }</span>
        : offlineConfigured
            ? <span className="gratora-pill gratora-pill--green"><span className="gratora-pill__dot" />{ __( 'Configured', 'gratora-donation-platform' ) }</span>
            : <span className="gratora-pill gratora-pill--amber"><span className="gratora-pill__dot" />{ __( 'Add how donors should pay', 'gratora-donation-platform' ) }</span>;

    return (
        <div className="gratora-panel">
            <Card
                title={ __( 'Test mode', 'gratora-donation-platform' ) }
                sub={ __( 'Org-wide rehearsal switch, also settable per form', 'gratora-donation-platform' ) }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Enable test mode for all forms', 'gratora-donation-platform' ) }
                    sub={ __( 'No real payment is taken and these donations are excluded from reporting.', 'gratora-donation-platform' ) }
                    checked={ !! s.value( 'test_mode', false ) }
                    onChange={ s.setValue( 'test_mode' ) }
                />
            </Card>

            <StripeKeysCard s={ s } onOffline={ offlineConfigured ? null : () => setShowOffline( true ) } />
            <PayPalKeysCard s={ s } />

            { gatewayPanels.map( ( panel ) => (
                <ExtensionSection key={ panel.id } panel={ panel } />
            ) ) }


            <Card
                leading={ <BrandMark letter="O" variant="offline" /> }
                title={ __( 'Check or bank transfer', 'gratora-donation-platform' ) }
                sub={ __( 'Donor sees your bank details and pays offline', 'gratora-donation-platform' ) }
                meta={ offlinePill }
                edited={ s.isDirty }
                collapsible
                open={ offlineOpen }
                onToggle={ setOfflineOpen }
            >
                <ToggleRow
                    title={ __( 'Enable offline donations', 'gratora-donation-platform' ) }
                    sub={ __( 'For cash, check, or bank transfer donations marked paid by admin.', 'gratora-donation-platform' ) }
                    checked={ offlineEnabled }
                    onChange={ s.setValue( 'offline.enabled' ) }
                />

                <FormRow
                    label={ __( 'How should donors pay you?', 'gratora-donation-platform' ) }
                    help={ sprintf(
                        /* translators: %s: the organization's name. */
                        __( 'For example: make checks payable to %s and mail them to… Donors who choose this get it by email with their donation reference.', 'gratora-donation-platform' ),
                        orgName || __( 'your organization', 'gratora-donation-platform' )
                    ) }
                    wide
                >
                    <textarea
                        ref={ instructionsRef }
                        className="gratora-textarea"
                        rows={ 4 }
                        aria-label={ __( 'How should donors pay you?', 'gratora-donation-platform' ) }
                        { ...s.bind( 'offline.instructions' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Bank details template', 'gratora-donation-platform' ) }
                    help={ __( 'Click a placeholder to drop it in. They expand when the donor is shown their transfer details.', 'gratora-donation-platform' ) }
                    wide
                >
                    <div className="gratora-merge-tags">
                        { BANK_PLACEHOLDERS.map( ( tag ) => (
                            <button
                                key={ tag }
                                type="button"
                                className="gratora-merge-tag"
                                onClick={ () => insertPlaceholder( tag ) }
                            >
                                { tag }
                            </button>
                        ) ) }
                    </div>
                    <textarea
                        ref={ bankRef }
                        className="gratora-textarea gratora-textarea--mono"
                        rows={ 5 }
                        placeholder={ bankDetailsSample( country ) }
                        { ...s.bind( 'offline.bank_details' ) }
                    />
                </FormRow>
            </Card>
        </div>
    );
}
