import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import ConfirmDialog from '../../_shared/components/ConfirmDialog';
import { ToggleRow } from '../../_shared/components/Switch';
import { notify } from '../../_shared/notify';

const SCOPES = [
    { key: 'donation', label: __( 'Donation', 'fundkit-fundraising-campaigns' ) },
    { key: 'receipt',  label: __( 'Receipt', 'fundkit-fundraising-campaigns' ) },
    { key: 'refund',   label: __( 'Refund', 'fundkit-fundraising-campaigns' ) },
];

/**
 * Mirrors ReferenceGenerator::format() so previews match minted references.
 * Split so the preview can weight the counter, which is the only part that
 * moves, apart from the prefix and year, which are the operator's own.
 */
function refParts( fmt, prefix, counter, year ) {
    const lead = [ prefix || '' ];
    if ( fmt.includeYear ) lead.push( String( year ) );

    return {
        head: lead.join( fmt.sep ) + fmt.sep,
        seq:  String( Math.max( 1, counter || 1 ) ).padStart( fmt.padding, '0' ),
    };
}

function buildRef( fmt, prefix, counter, year ) {
    const { head, seq } = refParts( fmt, prefix, counter, year );
    return head + seq;
}

// The server clamps padding to at least one digit, and an empty box is sent as
// null and stored as 0, so a preview that reads an empty box as five promises a
// width nothing will mint.
function clampPad( v ) {
    return Math.max( 1, Math.min( 12, Number( v ) || 0 ) );
}

/**
 * The alphabet ReferenceGenerator accepts. A reference outside it cannot be
 * matched by the admin or donor routes, so the generator strips it, and the
 * settings route now refuses it rather than letting the screen promise a
 * numbering scheme nobody would ever be given.
 *
 * @since 1.0.0
 */
export const isRefToken = ( raw ) => /^[A-Za-z0-9_-]+$/.test( String( raw ) );

// What the generator will actually mint from this value. It strips what it
// cannot use and keeps the rest, so a preview that substitutes the fallback
// instead shows a reference that will never exist: a prefix stored as AC/DC
// mints ACDC, not DONATION.
export const asRefToken = ( raw, fallback ) =>
    String( raw ?? '' ).replace( /[^A-Za-z0-9_-]/g, '' ) || fallback;

const tokenHelp = __( 'Letters, numbers, hyphens and underscores only.', 'fundkit-fundraising-campaigns' );

/** @since 1.0.0 */
function TokenInput( { value, bind, maxLength, placeholder, style } ) {
    const invalid = ! isRefToken( value );
    return (
        <>
            <input
                type="text"
                className={ `fundkit-input${ invalid ? ' is-invalid' : '' }` }
                aria-invalid={ invalid || undefined }
                maxLength={ maxLength }
                placeholder={ placeholder }
                style={ style }
                { ...bind }
            />
            { invalid && (
                <p className="fundkit-form-row__field-help" style={ { color: '#b42318' } }>{ tokenHelp }</p>
            ) }
        </>
    );
}

export default function NumberingPanel( { s , active } ) {
    const year = new Date().getFullYear();

    // Live (possibly unsaved) format drives the format-card preview.
    const rawSep    = String( s.value( 'separator', '-' ) );
    const rawPrefix = {
        donation: String( s.value( 'prefixes.donation', 'DON' ) ),
        receipt:  String( s.value( 'prefixes.receipt', 'REC' ) ),
        refund:   String( s.value( 'prefixes.refund', 'REF' ) ),
    };
    // The preview shows what would be minted, so a value the generator would
    // not accept falls back to the one it does rather than being drawn.
    const liveFmt = {
        sep:         asRefToken( rawSep, '-' ),
        padding:     clampPad( s.value( 'padding', 5 ) ),
        includeYear: !! s.value( 'include_year', true ),
    };
    const livePrefix = {
        donation: asRefToken( rawPrefix.donation, 'DONATION' ),
        receipt:  asRefToken( rawPrefix.receipt,  'RECEIPT' ),
        refund:   asRefToken( rawPrefix.refund,   'REFUND' ),
    };

    // Saved format drives the counter card: setting a counter is an immediate
    // server write that uses the persisted format, so previewing unsaved edits
    // there would promise a reference that won't actually be minted.
    const saved = s.savedRecord || {};
    const savedFmt = {
        sep:         String( saved.separator ?? '-' ),
        padding:     clampPad( saved.padding ?? 5 ),
        includeYear: saved.include_year !== false,
    };
    const savedPrefix = {
        donation: String( saved.prefixes?.donation ?? 'DON' ),
        receipt:  String( saved.prefixes?.receipt ?? 'REC' ),
        refund:   String( saved.prefixes?.refund ?? 'REF' ),
    };

    // Live counters (next value per scope) live outside the settings option, so
    // they are fetched and written through their own endpoint.
    const [ counters, setCounters ]   = useState( null );
    const [ drafts, setDrafts ]       = useState( {} );
    const [ busy, setBusy ]           = useState( '' );
    const [ confirm, setConfirm ]     = useState( null );
    const [ loadError, setLoadError ] = useState( false );

    const loadCounters = () => {
        setLoadError( false );
        apiFetch( { path: '/fundkit/v1/admin/numbering/counters' } )
            .then( ( data ) => {
                setCounters( data || {} );
                setDrafts( data || {} );
            } )
            .catch( () => { setCounters( null ); setLoadError( true ); } );
    };

    // The counter a scope reads from depends on the numbering settings above
    // it: reset-each-year and include-year together decide whether the counter
    // is year-scoped. Saving those on this very panel moves which counter is
    // live, so a mount-once fetch showed the previous namespace's number.
    useEffect( () => { if ( active ) loadCounters(); }, [ active ] );

    const setDraft = ( key, v ) => setDrafts( ( prev ) => ( { ...prev, [ key ]: v } ) );

    const doSet = async ( key ) => {
        const next = Number( drafts[ key ] );
        setBusy( key );
        try {
            const res = await apiFetch( {
                path:   '/fundkit/v1/admin/numbering/counter',
                method: 'POST',
                data:   { scope: key, next },
            } );
            setCounters( ( prev ) => ( { ...prev, [ key ]: res.next } ) );
            setDrafts( ( prev ) => ( { ...prev, [ key ]: res.next } ) );
            notify.success( __( 'Next number updated.', 'fundkit-fundraising-campaigns' ) );
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not update the counter.', 'fundkit-fundraising-campaigns' ) );
            setDrafts( ( prev ) => ( { ...prev, [ key ]: counters[ key ] } ) );
        } finally {
            setBusy( '' );
        }
    };

    const confirmSet = ( key, label ) => {
        const next = Number( drafts[ key ] );
        setConfirm( {
            title:        __( 'Set next number', 'fundkit-fundraising-campaigns' ),
            message:      sprintf(
                /* translators: 1: reference type, 2: the formatted next reference */
                __( 'The next %1$s reference will be %2$s. A counter can only move forward, so this cannot be lowered later. Continue?', 'fundkit-fundraising-campaigns' ),
                label.toLowerCase(),
                buildRef( savedFmt, savedPrefix[ key ], next, year ),
            ),
            confirmLabel: __( 'Set number', 'fundkit-fundraising-campaigns' ),
            destructive:  false,
            onConfirm:    () => doSet( key ),
        } );
    };

    return (
        <div className="fundkit-panel">
            <Card
                title={ __( 'Reference numbering', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'How donations, receipts, and refunds are numbered. References are gap-free and increment automatically.', 'fundkit-fundraising-campaigns' ) }
                edited={ s.isDirty }
            >
                <div className="fundkit-ref-previews">
                    { SCOPES.map( ( p ) => {
                        const { head, seq } = refParts( liveFmt, livePrefix[ p.key ], 1, year );
                        return (
                            <div key={ p.key } className="fundkit-ref-preview">
                                <span className="fundkit-ref-preview__label">{ p.label }</span>
                                <span className="fundkit-ref-preview__value">
                                    { head }
                                    <span className="fundkit-ref-preview__seq">{ seq }</span>
                                </span>
                            </div>
                        );
                    } ) }
                </div>

                <FormRow
                    label={ __( 'Donation prefix', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Leads every donation reference.', 'fundkit-fundraising-campaigns' ) }
                >
                    <TokenInput
                        value={ rawPrefix.donation }
                        maxLength={ 8 }
                        placeholder="DON"
                        bind={ s.bind( 'prefixes.donation', 'DON' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Receipt prefix', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Leads every receipt number.', 'fundkit-fundraising-campaigns' ) }
                >
                    <TokenInput
                        value={ rawPrefix.receipt }
                        maxLength={ 8 }
                        placeholder="REC"
                        bind={ s.bind( 'prefixes.receipt', 'REC' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Refund prefix', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Leads every refund reference.', 'fundkit-fundraising-campaigns' ) }
                >
                    <TokenInput
                        value={ rawPrefix.refund }
                        maxLength={ 8 }
                        placeholder="REF"
                        bind={ s.bind( 'prefixes.refund', 'REF' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Separator', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Character between the prefix, year, and number.', 'fundkit-fundraising-campaigns' ) }
                >
                    <TokenInput
                        value={ rawSep }
                        maxLength={ 3 }
                        placeholder="-"
                        style={ { maxWidth: 90 } }
                        bind={ s.bind( 'separator', '-' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Minimum digits', 'fundkit-fundraising-campaigns' ) }
                    help={ __( 'Zero-padded width of the running number. 5 gives 00001.', 'fundkit-fundraising-campaigns' ) }
                >
                    <input
                        type="number"
                        className="fundkit-input"
                        min={ 1 }
                        max={ 12 }
                        style={ { maxWidth: 90 } }
                        { ...s.bindNumber( 'padding' ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Include the year', 'fundkit-fundraising-campaigns' ) }
                    sub={ __( 'Adds the current year, e.g. DON-2026-00001 instead of DON-00001.', 'fundkit-fundraising-campaigns' ) }
                    checked={ liveFmt.includeYear }
                    onChange={ s.setValue( 'include_year' ) }
                />

                <ToggleRow
                    title={ __( 'Reset numbering each year', 'fundkit-fundraising-campaigns' ) }
                    sub={ __( 'Start again at 1 every January. Turn off for one continuous sequence across years.', 'fundkit-fundraising-campaigns' ) }
                    checked={ !! s.value( 'reset_yearly', true ) }
                    onChange={ s.setValue( 'reset_yearly' ) }
                />
            </Card>

            <Card
                title={ __( 'Next numbers', 'fundkit-fundraising-campaigns' ) }
                sub={ __( 'The number each type will use next. Jump a counter forward to continue an existing sequence; it can only increase, never go back.', 'fundkit-fundraising-campaigns' ) }
            >
                { s.isDirty && (
                    <p style={ { margin: '0 0 14px', fontSize: 12.5, color: '#b54708' } }>
                        { __( 'You have unsaved format changes above. Previews here use the saved format, so save first if you want new references to use the updated format.', 'fundkit-fundraising-campaigns' ) }
                    </p>
                ) }
                { loadError ? (
                    <div style={ { display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' } }>
                        <p style={ { color: '#b42318', margin: 0 } }>
                            { __( 'Could not load the current counters.', 'fundkit-fundraising-campaigns' ) }
                        </p>
                        <Btn variant="secondary" onClick={ loadCounters }>{ __( 'Retry', 'fundkit-fundraising-campaigns' ) }</Btn>
                    </div>
                ) : counters === null ? (
                    <p style={ { color: '#6b7280' } }>{ __( 'Loading…', 'fundkit-fundraising-campaigns' ) }</p>
                ) : (
                    SCOPES.map( ( p ) => {
                        const current = Number( counters[ p.key ] ?? 1 );
                        const draft   = drafts[ p.key ] ?? current;
                        // Counters only move forward server-side, so keep the Set
                        // button disabled for backwards/equal values rather than
                        // promising a reference the server will reject with a 400.
                        const changed = Number( draft ) > current;
                        return (
                            <FormRow
                                key={ p.key }
                                label={ p.label }
                                help={ sprintf(
                                    /* translators: %s: the formatted next reference */
                                    __( 'Next reference: %s', 'fundkit-fundraising-campaigns' ),
                                    buildRef( savedFmt, savedPrefix[ p.key ], Number( draft ) || current, year ),
                                ) }
                            >
                                <div style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
                                    <input
                                        type="number"
                                        className="fundkit-input"
                                        min={ current }
                                        style={ { maxWidth: 120 } }
                                        value={ draft }
                                        onChange={ ( e ) => setDraft( p.key, e.target.value ) }
                                    />
                                    <Btn
                                        variant="secondary"
                                        onClick={ () => confirmSet( p.key, p.label ) }
                                        disabled={ ! changed || busy === p.key }
                                        isBusy={ busy === p.key }
                                    >
                                        { __( 'Set', 'fundkit-fundraising-campaigns' ) }
                                    </Btn>
                                </div>
                            </FormRow>
                        );
                    } )
                ) }
            </Card>

            <ConfirmDialog confirm={ confirm } onClose={ () => setConfirm( null ) } />
        </div>
    );
}
