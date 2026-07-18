import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import SectionBar from '../../_shared/components/SectionBar';
import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import { ToggleRow } from '../../_shared/components/Switch';
import { getDonorTemplates, getTemplateById } from '../../_shared/emailTemplates';
import { formatAmount } from '../../_shared/format';
import { tablistKeyDown } from '../../_shared/tablistKeys';

export default function EmailPanel( { s } ) {
    const [ editingId, setEditingId ] = useState( null );
    const [ testTo, setTestTo ]       = useState( '' );
    const [ testing, setTesting ]     = useState( false );
    const [ testNotice, setTestNotice ] = useState( null );
    const templates = getDonorTemplates();

    const sendTest = async () => {
        setTesting( true );
        setTestNotice( null );
        try {
            const data = testTo.trim() ? { to: testTo.trim() } : {};
            const res  = await apiFetch( {
                path:   '/dono/v1/admin/email/test-send',
                method: 'POST',
                data,
            } );
            setTestNotice( {
                type: 'success',
                text: sprintf(
                    /* translators: %s: recipient address */
                    __( 'Test email sent to %s. Check the inbox + spam folder.', 'dono' ),
                    res?.to || __( 'the recipient', 'dono' )
                ),
            } );
        } catch ( err ) {
            setTestNotice( {
                type: 'error',
                text: err?.message || __( 'Send failed.', 'dono' ),
            } );
        } finally {
            setTesting( false );
        }
    };

    if ( editingId ) {
        return (
            <TemplateEditor
                id={ editingId }
                s={ s }
                onBack={ () => setEditingId( null ) }
            />
        );
    }

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Sender identity', 'dono' ) }
                meta={ __( 'Verify SPF and DKIM on the from-domain', 'dono' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'From name', 'dono' ) }
                    help={ __( 'Shown as the sender in the donor inbox.', 'dono' ) }
                >
                    <input type="text" className="dono-input" { ...s.bind( 'from_name' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'From email', 'dono' ) }
                    help={ __( 'Use an address on a domain you control.', 'dono' ) }
                >
                    <input type="email" className="dono-input" { ...s.bind( 'from_email' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'Reply-to', 'dono' ) }
                    help={ __( 'Where donor replies arrive. Defaults to From email.', 'dono' ) }
                >
                    <input type="email" className="dono-input" { ...s.bind( 'reply_to' ) } />
                </FormRow>
                <ToggleRow
                    title={ __( 'BCC me on every donation receipt', 'dono' ) }
                    sub={ __( 'Sends a copy to the admin email.', 'dono' ) }
                    checked={ !! s.value( 'bcc_admin', false ) }
                    onChange={ s.setValue( 'bcc_admin' ) }
                />
            </Card>

            <Card
                title={ __( 'Send a test email', 'dono' ) }
                sub={ __( 'Confirms your sender + SMTP transport are working before real receipts go out. Uses your current user email if you leave the recipient blank.', 'dono' ) }
            >
                <FormRow label={ __( 'Recipient', 'dono' ) }>
                    <input
                        type="email"
                        className="dono-input"
                        value={ testTo }
                        onChange={ ( e ) => setTestTo( e.target.value ) }
                        placeholder={ __( 'Leave blank to send to your WP user email', 'dono' ) }
                    />
                </FormRow>
                <div style={ { display: 'flex', justifyContent: 'flex-end' } }>
                    <Btn variant="secondary" onClick={ sendTest } disabled={ testing } isBusy={ testing }>
                        { testing ? __( 'Sending…', 'dono' ) : __( 'Send test email', 'dono' ) }
                    </Btn>
                </div>
                { testNotice && (
                    <div
                        className={ `dono-advanced-notice dono-advanced-notice--${ testNotice.type }` }
                        style={ { marginTop: 12 } }
                    >
                        { testNotice.text }
                    </div>
                ) }
            </Card>

            <Card
                title={ __( 'Donor emails', 'dono' ) }
                sub={ __( 'Sent to donors automatically by Dono', 'dono' ) }
                meta={ __( 'Click a row to edit', 'dono' ) }
            >
                <div className="dono-email-list">
                    { templates.map( ( t ) => {
                        const enabled = !! s.value( `templates.${ t.id }.enabled`, true );
                        return (
                            <div key={ t.id } className="dono-email-row">
                                <span
                                    className={ `dono-email-row__dot${ enabled ? ' is-on' : '' }` }
                                    aria-label={ enabled ? __( 'Enabled', 'dono' ) : __( 'Disabled', 'dono' ) }
                                />
                                <div className="dono-email-row__body">
                                    <div className="dono-email-row__title">{ t.label }</div>
                                    <div className="dono-email-row__desc">{ t.desc }</div>
                                </div>
                                <span className="dono-email-row__recipient">{ t.recipient }</span>
                                <Btn variant="ghost" size="sm" onClick={ () => setEditingId( t.id ) }>
                                    { __( 'Edit', 'dono' ) }
                                </Btn>
                            </div>
                        );
                    } ) }
                </div>
            </Card>
        </div>
    );
}

const SAMPLE_VALUES = {
    '{donor_first_name}':  'Jane',
    '{donor_name}':        'Jane Doe',
    '{donor_email}':       'jane@example.com',
    '{organisation_name}': 'Your Organization',
    '{amount}':            formatAmount( 2500 ),
    '{campaign_title}':    'Spring fundraiser',
    '{receipt_number}':    'R-2026-00042',
    '{reference}':         'DN-XYZ123',
    '{date}':              new Date().toLocaleDateString(),
    '{download_url}':      'https://example.org/receipt/download',
    '{bank_details}':      'IBAN: DE89 3704 0044 0532 0130 00\nBIC: COBADEFFXXX',
};

function expandTags( s ) {
    return ( s || '' ).replace( /\{[a-z_]+\}/g, ( m ) => SAMPLE_VALUES[ m ] ?? m );
}

function TemplateEditor( { id, s, onBack } ) {
    const meta = getTemplateById( id );
    const [ view, setView ] = useState( 'edit' );
    if ( ! meta ) {
        return (
            <div className="dono-panel">
                <Btn variant="ghost" size="sm" onClick={ onBack }>← { __( 'Back to emails', 'dono' ) }</Btn>
                <div className="dono-settings-empty">
                    <div className="dono-settings-empty__title">{ __( 'Unknown template', 'dono' ) }</div>
                </div>
            </div>
        );
    }

    const enabled = !! s.value( `templates.${ id }.enabled`, true );
    const subject = s.value( `templates.${ id }.subject`, '' );
    const body    = s.value( `templates.${ id }.body`, '' );

    const setSubject = ( v ) => s.edit( { templates: { [ id ]: { subject: v } } } );
    const setBody    = ( v ) => s.edit( { templates: { [ id ]: { body:    v } } } );
    const setEnabled = ( v ) => s.edit( { templates: { [ id ]: { enabled: v } } } );

    const insertTag = ( tag ) => setBody( `${ body }${ tag }` );

    return (
        <div className="dono-panel">
            <div className="dono-editor-head">
                <button type="button" className="dono-editor-back" onClick={ onBack }>
                    ← { __( 'Email templates', 'dono' ) }
                </button>
            </div>

            <SectionBar
                title={ meta.label }
                sub={ meta.desc }
            />

            <div
                className="dono-email-editor-tabs"
                role="tablist"
                onKeyDown={ ( e ) => tablistKeyDown( e, [ 'edit', 'preview' ], view, setView ) }
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={ view === 'edit' }
                    tabIndex={ view === 'edit' ? 0 : -1 }
                    className={ `dono-email-editor-tab${ view === 'edit' ? ' is-active' : '' }` }
                    onClick={ () => setView( 'edit' ) }
                >
                    { __( 'Edit', 'dono' ) }
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected={ view === 'preview' }
                    tabIndex={ view === 'preview' ? 0 : -1 }
                    className={ `dono-email-editor-tab${ view === 'preview' ? ' is-active' : '' }` }
                    onClick={ () => setView( 'preview' ) }
                >
                    { __( 'Preview', 'dono' ) }
                </button>
            </div>

            { view === 'preview' && (
                <Card>
                    <div className="dono-email-preview">
                        <div className="dono-email-preview__head">
                            <div><strong>{ __( 'Subject:', 'dono' ) }</strong> { expandTags( subject ) || <em style={ { color: '#999' } }>{ __( '(no subject)', 'dono' ) }</em> }</div>
                            <div><strong>{ __( 'To:', 'dono' ) }</strong> Jane Doe &lt;jane@example.com&gt;</div>
                        </div>
                        <pre className="dono-email-preview__body">{ expandTags( body ) }</pre>
                    </div>
                </Card>
            ) }

            { view === 'edit' && (
            <Card edited={ s.isDirty }>
                <ToggleRow
                    title={ __( 'Send this email', 'dono' ) }
                    sub={ __( 'Disable to skip this notification entirely.', 'dono' ) }
                    checked={ enabled }
                    onChange={ setEnabled }
                />

                <FormRow label={ __( 'Subject', 'dono' ) }>
                    <input
                        type="text"
                        className="dono-input"
                        value={ subject }
                        onChange={ ( e ) => setSubject( e.target.value ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Body', 'dono' ) }
                    help={ __( 'Plain text. Merge tags expand at send time.', 'dono' ) }
                    wide
                >
                    <div className="dono-merge-tags">
                        { meta.tags.map( ( t ) => (
                            <button
                                key={ t }
                                type="button"
                                className="dono-merge-tag"
                                onClick={ () => insertTag( t ) }
                            >
                                { t }
                            </button>
                        ) ) }
                    </div>
                    <textarea
                        className="dono-textarea"
                        rows={ 10 }
                        value={ body }
                        onChange={ ( e ) => setBody( e.target.value ) }
                    />
                </FormRow>
            </Card>
            ) }
        </div>
    );
}
