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
            title:    __( 'Choose receipt logo', 'fundraising-toolkit' ),
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
        const url = `${ window.wpApiSettings.root }fundkit/v1/admin/receipts/preview?_wpnonce=${ encodeURIComponent( window.wpApiSettings.nonce ) }`;
        window.open( url, '_blank', 'noopener' );
    };

    const previewReceipt = () => {
        // The preview endpoint reads fundkit_receipt_settings from disk, so any
        // unsaved edits won't show up. Nudge the admin to save first instead of
        // confusing them with a stale PDF.
        if ( s.isDirty ) {
            setConfirm( {
                title:        __( 'Unsaved changes', 'fundraising-toolkit' ),
                message:      __( 'You have unsaved changes that won\'t show in the preview. Continue anyway?', 'fundraising-toolkit' ),
                confirmLabel: __( 'Continue', 'fundraising-toolkit' ),
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
        <div className="fundkit-panel">
            <Card
                title={ __( 'Generic receipt template', 'fundraising-toolkit' ) }
                sub={ __( 'The default receipt every donor gets, unless their country has its own format.', 'fundraising-toolkit' ) }
                edited={ s.isDirty }
            >
                <div style={ { marginBottom: 16, display: 'flex', justifyContent: 'flex-end' } }>
                    <Btn variant="secondary" onClick={ previewReceipt }>
                        { __( 'Preview receipt', 'fundraising-toolkit' ) }
                    </Btn>
                </div>
                <FormRow
                    label={ __( 'Logo', 'fundraising-toolkit' ) }
                    help={ __( 'Shown above the header. PNG or JPG recommended.', 'fundraising-toolkit' ) }
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
                            { logoId > 0 ? __( 'Replace logo', 'fundraising-toolkit' ) : __( 'Select logo', 'fundraising-toolkit' ) }
                        </Btn>
                        { logoId > 0 && (
                            <Btn variant="ghost" onClick={ () => setLogoId( 0 ) }>
                                { __( 'Remove', 'fundraising-toolkit' ) }
                            </Btn>
                        ) }
                    </div>
                </FormRow>

                <FormRow
                    label={ __( 'Header title', 'fundraising-toolkit' ) }
                    help={ __( 'Big heading at the top of the receipt. Leave blank for the default "Donation receipt".', 'fundraising-toolkit' ) }
                >
                    <input
                        type="text"
                        className="fundkit-input"
                        value={ headerTitle }
                        onChange={ ( e ) => setHeader( e.target.value ) }
                        placeholder={ __( 'Donation receipt', 'fundraising-toolkit' ) }
                        maxLength={ 80 }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Intro paragraph', 'fundraising-toolkit' ) }
                    help={ __( 'Optional paragraph between the header and the donation details.', 'fundraising-toolkit' ) }
                    wide
                >
                    <MergeTagInserter onInsert={ ( t ) => setIntro( `${ intro }${ t }` ) } />
                    <textarea
                        className="fundkit-textarea"
                        rows={ 3 }
                        value={ intro }
                        onChange={ ( e ) => setIntro( e.target.value ) }
                        placeholder={ __( 'Enter an opening paragraph', 'fundraising-toolkit' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Signoff', 'fundraising-toolkit' ) }
                    help={ __( 'Short thank-you line near the bottom of the receipt.', 'fundraising-toolkit' ) }
                    wide
                >
                    <MergeTagInserter onInsert={ ( t ) => setSignoff( `${ signoff }${ t }` ) } />
                    <textarea
                        className="fundkit-textarea"
                        rows={ 2 }
                        value={ signoff }
                        onChange={ ( e ) => setSignoff( e.target.value ) }
                        placeholder={ __( 'Thank you for your support, {donor_name}.', 'fundraising-toolkit' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Footer note', 'fundraising-toolkit' ) }
                    help={ __( 'Small print at the bottom. Use this for the tax-deduction disclaimer, contact info, or organization registration details.', 'fundraising-toolkit' ) }
                    wide
                >
                    <MergeTagInserter onInsert={ ( t ) => setFooter( `${ footerNote }${ t }` ) } />
                    <textarea
                        className="fundkit-textarea"
                        rows={ 5 }
                        value={ footerNote }
                        onChange={ ( e ) => setFooter( e.target.value ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Show organization tax ID', 'fundraising-toolkit' ) }
                    sub={ __( 'Includes the tax ID from your Organization settings in the reference block.', 'fundraising-toolkit' ) }
                    checked={ showTaxId }
                    onChange={ setShowTax }
                />

                <ToggleRow
                    title={ __( 'Show donor address', 'fundraising-toolkit' ) }
                    sub={ __( 'Prints the donor billing address on the receipt (recommended for jurisdictions that require it).', 'fundraising-toolkit' ) }
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
        <div className="fundkit-merge-tags">
            { MERGE_TAGS.map( ( t ) => (
                <button
                    key={ t }
                    type="button"
                    className="fundkit-merge-tag"
                    onClick={ () => onInsert( t ) }
                    title={ __( 'Insert merge tag', 'fundraising-toolkit' ) }
                >
                    { t }
                </button>
            ) ) }
        </div>
    );
}
