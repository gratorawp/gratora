import { useRef, useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __, sprintf } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import Notice from '../../_shared/components/Notice';
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
                path:   '/fundkit/v1/admin/email/test-send',
                method: 'POST',
                data,
            } );
            // Handing off is not arriving, and the difference is the transport.
            // Saying only "sent" is what let a green result sit over receipts
            // that every mailbox was rejecting.
            const unauthenticated = res?.authenticated === false;
            setTestNotice( {
                type: unauthenticated ? 'warning' : 'success',
                text: sprintf(
                    /* translators: %s: recipient address */
                    __( 'Test email sent to %s. Check the inbox and the spam folder.', 'fundkit-fundraising-campaigns' ),
                    res?.to || __( 'the recipient', 'fundkit-fundraising-campaigns' )
                ) + ( unauthenticated
                    ? ' ' + __( 'It went out over PHP mail, which does not authenticate your domain. Gmail and Outlook reject unauthenticated mail outright, so this can be accepted here and still never arrive.', 'fundkit-fundraising-campaigns' )
                    : '' ),
            } );
        } catch ( err ) {
            setTestNotice( {
                type: 'error',
                text: err?.message || __( 'Send failed.', 'fundkit-fundraising-campaigns' ),
            } );
        } finally {
            setTesting( false );
        }
    };

    return (
        <div className="fundkit-panel">
            <Card
                title={ __( 'Sender identity', 'fundkit-fundraising-campaigns' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'From name', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Shown as the sender in the donor inbox.', 'fundkit-fundraising-campaigns' ) }
                >
                    <input type="text" className="fundkit-input" { ...s.bind( 'from_name' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'From email', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Use an address on a domain you control.', 'fundkit-fundraising-campaigns' ) }
                >
                    <input type="email" className="fundkit-input" { ...s.bind( 'from_email' ) } />
                </FormRow>
                <FormRow
                    label={ __( 'Reply-to', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Where donor replies arrive. Defaults to From email.', 'fundkit-fundraising-campaigns' ) }
                >
                    <input type="email" className="fundkit-input" { ...s.bind( 'reply_to' ) } />
                </FormRow>
                <ToggleRow
                    title={ __( 'BCC me on every donation receipt', 'fundkit-fundraising-campaigns' ) }
                    sub={ __( 'Sends a copy to the admin email.', 'fundkit-fundraising-campaigns' ) }
                    checked={ !! s.value( 'bcc_admin', false ) }
                    onChange={ s.setValue( 'bcc_admin' ) }
                />
            </Card>

            <Card
                title={ __( 'Send a test email', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Checks that your site can hand a message to its mail server. Arriving is a separate question: a message accepted here can still be rejected later by the recipient. Uses your current user email if you leave the recipient blank.', 'fundkit-fundraising-campaigns' ) }
            >
                <FormRow label={ __( 'Recipient', 'fundkit-fundraising-campaigns' ) }>
                    <input
                        type="email"
                        className="fundkit-input"
                        value={ testTo }
                        onChange={ ( e ) => setTestTo( e.target.value ) }
                        placeholder={ __( 'Leave blank to send to your WP user email', 'fundkit-fundraising-campaigns' ) }
                    />
                </FormRow>
                <div style={ { display: 'flex', justifyContent: 'flex-end' } }>
                    <Btn variant="secondary" onClick={ sendTest } disabled={ testing } isBusy={ testing }>
                        { testing ? __( 'Sending…', 'fundkit-fundraising-campaigns' ) : __( 'Send test email', 'fundkit-fundraising-campaigns' ) }
                    </Btn>
                </div>
                { testNotice && (
                    <div style={ { marginTop: 12 } }>
                        <Notice status={ testNotice.type } isDismissible={ false }>
                            { testNotice.text }
                        </Notice>
                    </div>
                ) }
                { /* Every self-hosted site on shared hosting meets this wall, and
                     the first symptom is a donor who never got a receipt for
                     money they gave. Named as a category with a link to the
                     directory, not a recommendation of one vendor. */ }
                <p className="fundkit-muted" style={ { marginTop: 12 } }>
                    { __( 'If test emails arrive but donors report nothing, the cause is almost always authentication rather than FundKit. Mailboxes such as Gmail reject mail that is not signed for your domain, and PHP mail on shared hosting is not. An SMTP plugin pointed at an authenticated provider fixes it for every email your site sends.', 'fundkit-fundraising-campaigns' ) }
                    { ' ' }
                    <a href="https://wordpress.org/plugins/tags/smtp/" target="_blank" rel="noreferrer noopener">
                        { __( 'SMTP plugins on WordPress.org', 'fundkit-fundraising-campaigns' ) }
                    </a>
                </p>
            </Card>

            <Card
                title={ __( 'Donor emails', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'Sent to donors automatically by FundKit', 'fundkit-fundraising-campaigns' ) }
                meta={ __( 'Click a row to edit', 'fundkit-fundraising-campaigns' ) }
            >
                <div className="fundkit-email-list">
                    { templates.map( ( t ) => {
                        const enabled = !! s.value( `templates.${ t.id }.enabled`, true );
                        return (
                            <button
                                key={ t.id }
                                type="button"
                                className="fundkit-email-row"
                                onClick={ () => setEditing( t ) }
                            >
                                <span
                                    className={ `fundkit-email-row__dot${ enabled ? ' is-on' : '' }` }
                                    aria-hidden="true"
                                />
                                <span className="fundkit-email-row__body">
                                    <span className="fundkit-email-row__title">
                                        { t.label }
                                        <span className="screen-reader-text">
                                            { enabled ? __( '(enabled)', 'fundkit-fundraising-campaigns' ) : __( '(disabled)', 'fundkit-fundraising-campaigns' ) }
                                        </span>
                                    </span>
                                    <span className="fundkit-email-row__desc">{ t.desc }</span>
                                </span>
                                <span className="fundkit-email-row__recipient">{ t.recipient }</span>
                                <span className="fundkit-email-row__edit">{ __( 'Edit', 'fundkit-fundraising-campaigns' ) }</span>
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
            ? <span key={ i } className="fundkit-email-preview__tag">{ part }</span>
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
                    <Btn onClick={ onClose }>{ __( 'Cancel', 'fundkit-fundraising-campaigns' ) }</Btn>
                    <Btn variant="primary" onClick={ done }>{ __( 'Done', 'fundkit-fundraising-campaigns' ) }</Btn>
                </>
            ) }
        >
            { t.desc && <p className="fundkit-dialog__help">{ t.desc }</p> }

            <div
                className="fundkit-email-editor-tabs"
                role="tablist"
                tabIndex={ -1 }
                onKeyDown={ ( e ) => tablistKeyDown( e, [ 'edit', 'preview' ], view, setView ) }
            >
                <button
                    type="button"
                    role="tab"
                    aria-selected={ view === 'edit' }
                    tabIndex={ view === 'edit' ? 0 : -1 }
                    className={ `fundkit-email-editor-tab${ view === 'edit' ? ' is-active' : '' }` }
                    onClick={ () => setView( 'edit' ) }
                >
                    { __( 'Edit', 'fundkit-fundraising-campaigns' ) }
                </button>
                <button
                    type="button"
                    role="tab"
                    aria-selected={ view === 'preview' }
                    tabIndex={ view === 'preview' ? 0 : -1 }
                    className={ `fundkit-email-editor-tab${ view === 'preview' ? ' is-active' : '' }` }
                    onClick={ () => setView( 'preview' ) }
                >
                    { __( 'Preview', 'fundkit-fundraising-campaigns' ) }
                </button>
            </div>

            { view === 'preview' ? (
                <div className="fundkit-email-preview">
                    <div className="fundkit-email-preview__head">
                        <div>
                            <strong>{ __( 'Subject:', 'fundkit-fundraising-campaigns' ) }</strong>{ ' ' }
                            { draft.subject.trim()
                                ? expandTags( draft.subject )
                                : <em>{ __( '(no subject)', 'fundkit-fundraising-campaigns' ) }</em> }
                        </div>
                        <div><strong>{ __( 'To:', 'fundkit-fundraising-campaigns' ) }</strong> Jane Doe &lt;jane@example.com&gt;</div>
                    </div>
                    <pre className="fundkit-email-preview__body">{ expandTags( draft.body ) }</pre>
                </div>
            ) : (
                <>
                    <ToggleRow
                        title={ __( 'Send this email', 'fundkit-fundraising-campaigns' ) }
                        sub={ __( 'Disable to skip this notification entirely.', 'fundkit-fundraising-campaigns' ) }
                        checked={ draft.enabled }
                        onChange={ ( v ) => set( { enabled: v } ) }
                    />

                    <FormRow label={ __( 'Subject', 'fundkit-fundraising-campaigns' ) } wide>
                        <input
                            type="text"
                            className="fundkit-input"
                            value={ draft.subject }
                            onChange={ ( e ) => set( { subject: e.target.value } ) }
                        />
                    </FormRow>

                    <FormRow
                        label={ __( 'Body', 'fundkit-fundraising-campaigns' ) }
                        help={ __( 'Plain text. Merge tags expand at send time.', 'fundkit-fundraising-campaigns' ) }
                        wide
                    >
                        { !! t.tags.length && (
                            <div className="fundkit-merge-tags">
                                { t.tags.map( ( tag ) => (
                                    <button
                                        key={ tag }
                                        type="button"
                                        className="fundkit-merge-tag"
                                        onClick={ () => insertTag( tag ) }
                                    >
                                        { tag }
                                    </button>
                                ) ) }
                            </div>
                        ) }
                        <textarea
                            ref={ bodyRef }
                            className="fundkit-textarea"
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
