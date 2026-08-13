import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Dialog from '../../_shared/components/Dialog';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import { ToggleRow } from '../../_shared/components/Switch';
import { getDonorTemplates } from '../../_shared/emailTemplates';
import { formatAmount } from '../../_shared/format';
import { tablistKeyDown } from '../../_shared/tablistKeys';

export default function EmailPanel( { s } ) {
    const [ editing, setEditing ]     = useState( null );
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
                    __( 'Test email sent to %s. Check the inbox + spam folder.', 'dono-fundraising-platform' ),
                    res?.to || __( 'the recipient', 'dono-fundraising-platform' )
                ),
            } );
        } catch ( err ) {
            setTestNotice( {
                type: 'error',
                text: err?.message || __( 'Send failed.', 'dono-fundraising-platform' ),
            } );
        } finally {
            setTesting( false );
        }
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Sender identity', 'dono-fundraising-platform' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'From name', 'dono-fundraising-platform' ) }
                    help={ __( 'Shown as the sender in the donor inbox.', 'dono-fundraising-platform' ) }
                >
                    <input type="text" className="dono-input" { ...s.bind( 'from_name' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'From email', 'dono-fundraising-platform' ) }
                    help={ __( 'Use an address on a domain you control.', 'dono-fundraising-platform' ) }
                >
                    <input type="email" className="dono-input" { ...s.bind( 'from_email' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'Reply-to', 'dono-fundraising-platform' ) }
                    help={ __( 'Where donor replies arrive. Defaults to From email.', 'dono-fundraising-platform' ) }
                >
                    <input type="email" className="dono-input" { ...s.bind( 'reply_to' ) } />
                </FormRow>
                <ToggleRow
                    title={ __( 'BCC me on every donation receipt', 'dono-fundraising-platform' ) }
                    sub={ __( 'Sends a copy to the admin email.', 'dono-fundraising-platform' ) }
                    checked={ !! s.value( 'bcc_admin', false ) }
                    onChange={ s.setValue( 'bcc_admin' ) }
                />
            </Card>

            <Card
                title={ __( 'Send a test email', 'dono-fundraising-platform' ) }
                sub={ __( 'Checks that your site can hand a message to its mail server. Arriving is a separate question: a message accepted here can still be rejected later by the recipient. Uses your current user email if you leave the recipient blank.', 'dono-fundraising-platform' ) }
            >
                <FormRow label={ __( 'Recipient', 'dono-fundraising-platform' ) }>
                    <input
                        type="email"
                        className="dono-input"
                        value={ testTo }
                        onChange={ ( e ) => setTestTo( e.target.value ) }
                        placeholder={ __( 'Leave blank to send to your WP user email', 'dono-fundraising-platform' ) }
                    />
                </FormRow>
                <div style={ { display: 'flex', justifyContent: 'flex-end' } }>
                    <Btn variant="secondary" onClick={ sendTest } disabled={ testing } isBusy={ testing }>
                        { testing ? __( 'Sending…', 'dono-fundraising-platform' ) : __( 'Send test email', 'dono-fundraising-platform' ) }
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
                { /* Every self-hosted site on shared hosting meets this wall, and
                     the first symptom is a donor who never got a receipt for
                     money they gave. Named as a category with a link to the
                     directory, not a recommendation of one vendor. */ }
                <p className="dono-muted" style={ { marginTop: 12 } }>
                    { __( 'If test emails arrive but donors report nothing, the cause is almost always authentication rather than Dono. Mailboxes such as Gmail reject mail that is not signed for your domain, and PHP mail on shared hosting is not. An SMTP plugin pointed at an authenticated provider fixes it for every email your site sends.', 'dono-fundraising-platform' ) }
                    { ' ' }
                    <a href="https://wordpress.org/plugins/tags/smtp/" target="_blank" rel="noreferrer noopener">
                        { __( 'SMTP plugins on WordPress.org', 'dono-fundraising-platform' ) }
                    </a>
                </p>
            </Card>

            <Card
                title={ __( 'Donor emails', 'dono-fundraising-platform' ) }
                sub={ __( 'Sent to donors automatically by Dono', 'dono-fundraising-platform' ) }
                meta={ __( 'Click a row to edit', 'dono-fundraising-platform' ) }
            >
                <div className="dono-email-list">
                    { templates.map( ( t ) => {
                        const enabled = !! s.value( `templates.${ t.id }.enabled`, true );
                        return (
                            <button
                                key={ t.id }
                                type="button"
                                className="dono-email-row"
                                onClick={ () => setEditing( t ) }
                            >
                                <span
                                    className={ `dono-email-row__dot${ enabled ? ' is-on' : '' }` }
                                    aria-hidden="true"
                                />
                                <span className="dono-email-row__body">
                                    <span className="dono-email-row__title">
                                        { t.label }
                                        <span className="screen-reader-text">
                                            { enabled ? __( '(enabled)', 'dono-fundraising-platform' ) : __( '(disabled)', 'dono-fundraising-platform' ) }
                                        </span>
                                    </span>
                                    <span className="dono-email-row__desc">{ t.desc }</span>
                                </span>
                                <span className="dono-email-row__recipient">{ t.recipient }</span>
                                <span className="dono-email-row__edit">{ __( 'Edit', 'dono-fundraising-platform' ) }</span>
                            </button>
                        );
                    } ) }
                </div>
            </Card>

            { editing && (
                <TemplateDialog t={ editing } s={ s } onClose={ () => setEditing( null ) } />
            ) }
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

/**
 * Core has no sample for a tag an add-on registered, and a body where half the
 * tags read as prose and half as braces looks broken rather than unfinished.
 * Anything without a sample is shown as a tag, so the preview stays coherent.
 */
function expandTags( text ) {
    return ( text || '' ).split( /(\{[a-z_]+\})/ ).map( ( part, i ) =>
        /^\{[a-z_]+\}$/.test( part ) && SAMPLE_VALUES[ part ] === undefined
            ? <span key={ i } className="dono-email-preview__tag">{ part }</span>
            : ( SAMPLE_VALUES[ part ] ?? part )
    );
}

/**
 * One template, edited in place over the settings draft. The page saves as a
 * whole (see the save bar), so Done applies the change and Cancel drops it
 * without ever touching the draft.
 */
function TemplateDialog( { t, s, onClose } ) {
    const current = {
        enabled: !! s.value( `templates.${ t.id }.enabled`, true ),
        subject: s.value( `templates.${ t.id }.subject`, '' ),
        body:    s.value( `templates.${ t.id }.body`, '' ),
    };

    const [ draft, setDraft ] = useState( current );
    const [ view, setView ]   = useState( 'edit' );
    const bodyRef             = useRef( null );

    const set = ( patch ) => setDraft( ( d ) => ( { ...d, ...patch } ) );

    const done = () => {
        const changed = {};
        for ( const key of [ 'enabled', 'subject', 'body' ] ) {
            if ( draft[ key ] !== current[ key ] ) changed[ key ] = draft[ key ];
        }
        if ( Object.keys( changed ).length ) {
            s.edit( { templates: { [ t.id ]: changed } } );
        }
        onClose();
    };

    // At the caret, not appended: a tag belongs where the sentence needs it.
    const insertTag = ( tag ) => {
        const el = bodyRef.current;
        if ( ! el ) {
            set( { body: draft.body + tag } );
            return;
        }
        const start = el.selectionStart;
        const end   = el.selectionEnd;
        set( { body: draft.body.slice( 0, start ) + tag + draft.body.slice( end ) } );
        window.requestAnimationFrame( () => {
            el.focus();
            el.setSelectionRange( start + tag.length, start + tag.length );
        } );
    };

    return (
        <Dialog
            title={ t.label }
            size="wide"
            onClose={ onClose }
            foot={ (
                <>
                    <Btn onClick={ onClose }>{ __( 'Cancel', 'dono-fundraising-platform' ) }</Btn>
                    <Btn variant="primary" onClick={ done }>{ __( 'Done', 'dono-fundraising-platform' ) }</Btn>
                </>
            ) }
        >
            { t.desc && <p className="dono-dialog__help">{ t.desc }</p> }

            <div
                className="dono-email-editor-tabs"
                role="tablist"
                tabIndex={ -1 }
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
                    { __( 'Edit', 'dono-fundraising-platform' ) }
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected={ view === 'preview' }
                    tabIndex={ view === 'preview' ? 0 : -1 }
                    className={ `dono-email-editor-tab${ view === 'preview' ? ' is-active' : '' }` }
                    onClick={ () => setView( 'preview' ) }
                >
                    { __( 'Preview', 'dono-fundraising-platform' ) }
                </button>
            </div>

            { view === 'preview' ? (
                <div className="dono-email-preview">
                    <div className="dono-email-preview__head">
                        <div>
                            <strong>{ __( 'Subject:', 'dono-fundraising-platform' ) }</strong>{ ' ' }
                            { draft.subject.trim()
                                ? expandTags( draft.subject )
                                : <em>{ __( '(no subject)', 'dono-fundraising-platform' ) }</em> }
                        </div>
                        <div><strong>{ __( 'To:', 'dono-fundraising-platform' ) }</strong> Jane Doe &lt;jane@example.com&gt;</div>
                    </div>
                    <pre className="dono-email-preview__body">{ expandTags( draft.body ) }</pre>
                </div>
            ) : (
                <>
                    <ToggleRow
                        title={ __( 'Send this email', 'dono-fundraising-platform' ) }
                        sub={ __( 'Disable to skip this notification entirely.', 'dono-fundraising-platform' ) }
                        checked={ draft.enabled }
                        onChange={ ( v ) => set( { enabled: v } ) }
                    />

                    <FormRow label={ __( 'Subject', 'dono-fundraising-platform' ) } wide>
                        <input
                            type="text"
                            className="dono-input"
                            value={ draft.subject }
                            onChange={ ( e ) => set( { subject: e.target.value } ) }
                        />
                    </FormRow>

                    <FormRow
                        label={ __( 'Body', 'dono-fundraising-platform' ) }
                        help={ __( 'Plain text. Merge tags expand at send time.', 'dono-fundraising-platform' ) }
                        wide
                    >
                        { !! t.tags.length && (
                            <div className="dono-merge-tags">
                                { t.tags.map( ( tag ) => (
                                    <button
                                        key={ tag }
                                        type="button"
                                        className="dono-merge-tag"
                                        onClick={ () => insertTag( tag ) }
                                    >
                                        { tag }
                                    </button>
                                ) ) }
                            </div>
                        ) }
                        <textarea
                            ref={ bodyRef }
                            className="dono-textarea"
                            rows={ 10 }
                            value={ draft.body }
                            onChange={ ( e ) => set( { body: e.target.value } ) }
                        />
                    </FormRow>
                </>
            ) }
        </Dialog>
    );
}
