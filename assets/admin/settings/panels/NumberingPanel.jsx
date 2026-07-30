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
    { key: 'donation', label: __( 'Donation', 'dono' ) },
    { key: 'receipt',  label: __( 'Receipt', 'dono' ) },
    { key: 'refund',   label: __( 'Refund', 'dono' ) },
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

function clampPad( v ) {
    return Math.max( 1, Math.min( 12, Number( v ) || 5 ) );
}

export default function NumberingPanel( { s } ) {
    const year = new Date().getFullYear();

    // Live (possibly unsaved) format drives the format-card preview.
    const liveFmt = {
        sep:         String( s.value( 'separator', '-' ) ),
        padding:     clampPad( s.value( 'padding', 5 ) ),
        includeYear: !! s.value( 'include_year', true ),
    };
    const livePrefix = {
        donation: String( s.value( 'prefixes.donation', 'DONO' ) ),
        receipt:  String( s.value( 'prefixes.receipt', 'REC' ) ),
        refund:   String( s.value( 'prefixes.refund', 'REF' ) ),
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
        donation: String( saved.prefixes?.donation ?? 'DONO' ),
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
        apiFetch( { path: '/dono/v1/admin/numbering/counters' } )
            .then( ( data ) => {
                setCounters( data || {} );
                setDrafts( data || {} );
            } )
            .catch( () => { setCounters( null ); setLoadError( true ); } );
    };

    useEffect( () => { loadCounters(); }, [] );

    const setDraft = ( key, v ) => setDrafts( ( prev ) => ( { ...prev, [ key ]: v } ) );

    const doSet = async ( key ) => {
        const next = Number( drafts[ key ] );
        setBusy( key );
        try {
            const res = await apiFetch( {
                path:   '/dono/v1/admin/numbering/counter',
                method: 'POST',
                data:   { scope: key, next },
            } );
            setCounters( ( prev ) => ( { ...prev, [ key ]: res.next } ) );
            setDrafts( ( prev ) => ( { ...prev, [ key ]: res.next } ) );
            notify.success( __( 'Next number updated.', 'dono' ) );
        } catch ( err ) {
            notify.error( err?.message || __( 'Could not update the counter.', 'dono' ) );
            setDrafts( ( prev ) => ( { ...prev, [ key ]: counters[ key ] } ) );
        } finally {
            setBusy( '' );
        }
    };

    const confirmSet = ( key, label ) => {
        const next = Number( drafts[ key ] );
        setConfirm( {
            title:        __( 'Set next number', 'dono' ),
            message:      sprintf(
                /* translators: 1: reference type, 2: the formatted next reference */
                __( 'The next %1$s reference will be %2$s. A counter can only move forward, so this cannot be lowered later. Continue?', 'dono' ),
                label.toLowerCase(),
                buildRef( savedFmt, savedPrefix[ key ], next, year ),
            ),
            confirmLabel: __( 'Set number', 'dono' ),
            destructive:  false,
            onConfirm:    () => doSet( key ),
        } );
    };

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Reference numbering', 'dono' ) }
                sub={ __( 'How donations, receipts, and refunds are numbered. References are gap-free and increment automatically.', 'dono' ) }
                edited={ s.isDirty }
            >
                <div className="dono-ref-previews">
                    { SCOPES.map( ( p ) => {
                        const { head, seq } = refParts( liveFmt, livePrefix[ p.key ], 1, year );
                        return (
                            <div key={ p.key } className="dono-ref-preview">
                                <span className="dono-ref-preview__label">{ p.label }</span>
                                <span className="dono-ref-preview__value">
                                    { head }
                                    <span className="dono-ref-preview__seq">{ seq }</span>
                                </span>
                            </div>
                        );
                    } ) }
                </div>

                <FormRow
                    label={ __( 'Donation prefix', 'dono' ) }
                    help={ __( 'Leads every donation reference.', 'dono' ) }
                >
                    <input
                        type="text"
                        className="dono-input"
                        maxLength={ 8 }
                        placeholder="DONO"
                        { ...s.bind( 'prefixes.donation', 'DONO' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Receipt prefix', 'dono' ) }
                    help={ __( 'Leads every receipt number.', 'dono' ) }
                >
                    <input
                        type="text"
                        className="dono-input"
                        maxLength={ 8 }
                        placeholder="REC"
                        { ...s.bind( 'prefixes.receipt', 'REC' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Refund prefix', 'dono' ) }
                    help={ __( 'Leads every refund reference.', 'dono' ) }
                >
                    <input
                        type="text"
                        className="dono-input"
                        maxLength={ 8 }
                        placeholder="REF"
                        { ...s.bind( 'prefixes.refund', 'REF' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Separator', 'dono' ) }
                    help={ __( 'Character between the prefix, year, and number.', 'dono' ) }
                >
                    <input
                        type="text"
                        className="dono-input"
                        maxLength={ 3 }
                        placeholder="-"
                        style={ { maxWidth: 90 } }
                        { ...s.bind( 'separator', '-' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Minimum digits', 'dono' ) }
                    help={ __( 'Zero-padded width of the running number. 5 gives 00001.', 'dono' ) }
                >
                    <input
                        type="number"
                        className="dono-input"
                        min={ 1 }
                        max={ 12 }
                        style={ { maxWidth: 90 } }
                        { ...s.bindNumber( 'padding' ) }
                    />
                </FormRow>

                <ToggleRow
                    title={ __( 'Include the year', 'dono' ) }
                    sub={ __( 'Adds the current year, e.g. DONO-2026-00001 instead of DONO-00001.', 'dono' ) }
                    checked={ liveFmt.includeYear }
                    onChange={ s.setValue( 'include_year' ) }
                />

                <ToggleRow
                    title={ __( 'Reset numbering each year', 'dono' ) }
                    sub={ __( 'Start again at 1 every January. Turn off for one continuous sequence across years.', 'dono' ) }
                    checked={ !! s.value( 'reset_yearly', true ) }
                    onChange={ s.setValue( 'reset_yearly' ) }
                />
            </Card>

            <Card
                title={ __( 'Next numbers', 'dono' ) }
                sub={ __( 'The number each type will use next. Jump a counter forward to continue an existing sequence; it can only increase, never go back.', 'dono' ) }
            >
                { s.isDirty && (
                    <p style={ { margin: '0 0 14px', fontSize: 12.5, color: '#b54708' } }>
                        { __( 'You have unsaved format changes above. Previews here use the saved format, so save first if you want new references to use the updated format.', 'dono' ) }
                    </p>
                ) }
                { loadError ? (
                    <div style={ { display: 'flex', alignItems: 'center', gap: 12, flexWrap: 'wrap' } }>
                        <p style={ { color: '#b42318', margin: 0 } }>
                            { __( 'Could not load the current counters.', 'dono' ) }
                        </p>
                        <Btn variant="secondary" onClick={ loadCounters }>{ __( 'Retry', 'dono' ) }</Btn>
                    </div>
                ) : counters === null ? (
                    <p style={ { color: '#6b7280' } }>{ __( 'Loading…', 'dono' ) }</p>
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
                                    __( 'Next reference: %s', 'dono' ),
                                    buildRef( savedFmt, savedPrefix[ p.key ], Number( draft ) || current, year ),
                                ) }
                            >
                                <div style={ { display: 'flex', gap: 8, alignItems: 'center' } }>
                                    <input
                                        type="number"
                                        className="dono-input"
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
                                        { __( 'Set', 'dono' ) }
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
