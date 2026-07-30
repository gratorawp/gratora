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
                                            { enabled ? __( '(enabled)', 'dono' ) : __( '(disabled)', 'dono' ) }
                                        </span>
                                    </span>
                                    <span className="dono-email-row__desc">{ t.desc }</span>
                                </span>
                                <span className="dono-email-row__recipient">{ t.recipient }</span>
                                <span className="dono-email-row__edit">{ __( 'Edit', 'dono' ) }</span>
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
                    <Btn onClick={ onClose }>{ __( 'Cancel', 'dono' ) }</Btn>
                    <Btn variant="primary" onClick={ done }>{ __( 'Done', 'dono' ) }</Btn>
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

            { view === 'preview' ? (
                <div className="dono-email-preview">
                    <div className="dono-email-preview__head">
                        <div>
                            <strong>{ __( 'Subject:', 'dono' ) }</strong>{ ' ' }
                            { draft.subject.trim()
                                ? expandTags( draft.subject )
                                : <em>{ __( '(no subject)', 'dono' ) }</em> }
                        </div>
                        <div><strong>{ __( 'To:', 'dono' ) }</strong> Jane Doe &lt;jane@example.com&gt;</div>
                    </div>
                    <pre className="dono-email-preview__body">{ expandTags( draft.body ) }</pre>
                </div>
            ) : (
                <>
                    <ToggleRow
                        title={ __( 'Send this email', 'dono' ) }
                        sub={ __( 'Disable to skip this notification entirely.', 'dono' ) }
                        checked={ draft.enabled }
                        onChange={ ( v ) => set( { enabled: v } ) }
                    />

                    <FormRow label={ __( 'Subject', 'dono' ) } wide>
                        <input
                            type="text"
                            className="dono-input"
                            value={ draft.subject }
                            onChange={ ( e ) => set( { subject: e.target.value } ) }
                        />
                    </FormRow>

                    <FormRow
                        label={ __( 'Body', 'dono' ) }
                        help={ __( 'Plain text. Merge tags expand at send time.', 'dono' ) }
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
