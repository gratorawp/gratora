import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import { ToggleRow } from '../../_shared/components/Switch';

const MERGE_TAGS = [
    '{donor_name}',
    '{organisation_name}',
    '{amount}',
    '{receipt_number}',
    '{date}',
    '{reference}',
];

export default function ReceiptsPanel( { s } ) {
    const [ confirm, setConfirm ] = useState( null );

    const headerTitle = s.value( 'header_title', '' );
    const intro       = s.value( 'intro', '' );
    const signoff     = s.value( 'signoff', '' );
    const footerNote  = s.value( 'footer_note', '' );
    const showTaxId   = !! s.value( 'show_tax_id', true );
    const showAddress = !! s.value( 'show_donor_address', false );
    const logoId      = Number( s.value( 'logo_attachment_id', 0 ) ) || 0;

    const setHeader   = ( v ) => s.edit( { header_title: v } );
    const setIntro    = ( v ) => s.edit( { intro:        v } );
    const setSignoff  = ( v ) => s.edit( { signoff:      v } );
    const setFooter   = ( v ) => s.edit( { footer_note:  v } );
    const setShowTax  = ( v ) => s.edit( { show_tax_id:  v } );
    const setShowAddr = ( v ) => s.edit( { show_donor_address: v } );
    const setLogoId   = ( v ) => s.edit( { logo_attachment_id: Number( v ) || 0 } );

    // WP only persists the attachment id; resolve the URL by asking the
    // media library each time it changes.
    const [ logoUrl, setLogoUrl ] = useState( '' );
    useEffect( () => {
        if ( ! logoId || ! window.wp?.media?.attachment ) {
            setLogoUrl( '' );
            return;
        }
        const att = window.wp.media.attachment( logoId );
        const apply = () => setLogoUrl( att.get( 'url' ) || '' );
        if ( att.get( 'url' ) ) apply();
        else att.fetch().then( apply ).catch( () => setLogoUrl( '' ) );
    }, [ logoId ] );

    const pickLogo = () => {
        const frame = window.wp.media( {
            title:    __( 'Choose receipt logo', 'dono-fundraising-platform' ),
            multiple: false,
            library:  { type: 'image' },
        } );
        frame.on( 'select', () => {
            const att = frame.state().get( 'selection' ).first().toJSON();
            setLogoId( att.id );
            if ( att.url ) setLogoUrl( att.url );
        } );
        frame.open();
    };

    // Open the rendered PDF in a new tab. apiFetch isn't a good fit (it
    // assumes JSON); using the REST URL directly keeps the auth nonce
    // out of the address bar.
    const openPreview = () => {
        const url = `${ window.wpApiSettings.root }dono/v1/admin/receipts/preview?_wpnonce=${ encodeURIComponent( window.wpApiSettings.nonce ) }`;
        window.open( url, '_blank', 'noopener' );
    };

    const previewReceipt = () => {
        // The preview endpoint reads dono_receipt_settings from disk, so any
        // unsaved edits won't show up. Nudge the admin to save first instead of
        // confusing them with a stale PDF.
        if ( s.isDirty ) {
            setConfirm( {
                title:        __( 'Unsaved changes', 'dono-fundraising-platform' ),
                message:      __( 'You have unsaved changes that won\'t show in the preview. Continue anyway?', 'dono-fundraising-platform' ),
                confirmLabel: __( 'Continue', 'dono-fundraising-platform' ),
                destructive:  false,
                onConfirm: async () => {
                    openPreview();
                },
            } );
            return;
        }
        openPreview();
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Generic receipt template', 'dono-fundraising-platform' ) }
                sub={ __( 'Applies to the GenericReceiptRenderer output, not country-specific renderers.', 'dono-fundraising-platform' ) }
                edited={ s.isDirty }
            >
                <div style={ { marginBottom: 16, display: 'flex', justifyContent: 'flex-end' } }>
                    <Btn variant="secondary" onClick={ previewReceipt }>
                        { __( 'Preview receipt', 'dono-fundraising-platform' ) }
                    </Btn>
                </div>
                <FormRow
                    label={ __( 'Logo', 'dono-fundraising-platform' ) }
                    help={ __( 'Shown above the header. PNG or JPG recommended.', 'dono-fundraising-platform' ) }
                >
                    <div style={ { display: 'flex', alignItems: 'center', gap: 12 } }>
                        { logoId > 0 && logoUrl && (
                            <img
                                src={ logoUrl }
                                alt=""
                                style={ { maxHeight: 48, maxWidth: 200, border: '1px solid #e5e7eb', borderRadius: 4 } }
                            />
                        ) }
                        <Btn variant="secondary" onClick={ pickLogo }>
                            { logoId > 0 ? __( 'Replace logo', 'dono-fundraising-platform' ) : __( 'Select logo', 'dono-fundraising-platform' ) }
                        </Btn>
                        { logoId > 0 && (
                            <Btn variant="ghost" onClick={ () => setLogoId( 0 ) }>
                                { __( 'Remove', 'dono-fundraising-platform' ) }
                            </Btn>
                        ) }
                    </div>
                </FormRow>

                <FormRow
                    label={ __( 'Header title', 'dono-fundraising-platform' ) }
                    help={ __( 'Big heading at the top of the receipt. Leave blank for the default "Donation receipt".', 'dono-fundraising-platform' ) }
                >
                    <input
                        type="text"
                        className="dono-input"
                        value={ headerTitle }
                        onChange={ ( e ) => setHeader( e.target.value ) }
                        placeholder={ __( 'Donation receipt', 'dono-fundraising-platform' ) }
                        maxLength={ 80 }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Intro paragraph', 'dono-fundraising-platform' ) }
                    help={ __( 'Optional paragraph between the header and the donation details.', 'dono-fundraising-platform' ) }
                    wide
                >
                    <MergeTagInserter onInsert={ ( t ) => setIntro( `${ intro }${ t }` ) } />
                    <textarea
                        className="dono-textarea"
                        rows={ 3 }
                        value={ intro }
                        onChange={ ( e ) => setIntro( e.target.value ) }
                        placeholder={ __( 'Enter a sign-off line', 'dono-fundraising-platform' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Signoff', 'dono-fundraising-platform' ) }
                    help={ __( 'Short thank-you line near the bottom of the receipt.', 'dono-fundraising-platform' ) }
                    wide
                >
                    <MergeTagInserter onInsert={ ( t ) => setSignoff( `${ signoff }${ t }` ) } />
                    <textarea
                        className="dono-textarea"
                        rows={ 2 }
                        value={ signoff }
                        onChange={ ( e ) => setSignoff( e.target.value ) }
                        placeholder={ __( 'Thank you for your support, {donor_name}.', 'dono-fundraising-platform' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Footer note', 'dono-fundraising-platform' ) }
                    help={ __( 'Small print at the bottom. Use this for the tax-deduction disclaimer, contact info, or organization registration details.', 'dono-fundraising-platform' ) }
                    wide
                >
                    <MergeTagInserter onInsert={ ( t ) => setFooter( `${ footerNote }${ t }` ) } />
                    <textarea
                        className="dono-textarea"
                        rows={ 5 }
                        value={ footerNote }
                        onChange={ ( e ) => setFooter( e.target.value ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Show organization tax ID', 'dono-fundraising-platform' ) }
                    sub={ __( 'Includes the tax ID from your Organization settings in the reference block.', 'dono-fundraising-platform' ) }
                    checked={ showTaxId }
                    onChange={ setShowTax }
                />

                <ToggleRow
                    title={ __( 'Show donor address', 'dono-fundraising-platform' ) }
                    sub={ __( 'Prints the donor billing address on the receipt (recommended for jurisdictions that require it).', 'dono-fundraising-platform' ) }
                    checked={ showAddress }
                    onChange={ setShowAddr }
                />
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}

function MergeTagInserter( { onInsert } ) {
    return (
        <div className="dono-merge-tags">
            { MERGE_TAGS.map( ( t ) => (
                <button
                    key={ t }
                    type="button"
                    className="dono-merge-tag"
                    onClick={ () => onInsert( t ) }
                    title={ __( 'Insert merge tag', 'dono-fundraising-platform' ) }
                >
                    { t }
                </button>
            ) ) }
        </div>
    );
}
