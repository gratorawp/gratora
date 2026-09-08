import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import { ToggleRow } from '../../_shared/components/Switch';
import { CURRENCIES, currencyByCode, previewAmount } from '../../_shared/currency';
import { CURRENCY_SYMBOLS } from '../../../_shared/money';

function fmtRate( n ) {
    const v = Number( n );
    return Number.isFinite( v ) ? v.toFixed( 8 ) : '';
}

/**
 * A positive rate, or null when the entry is not one. The decimal key emits
 * ',' on most European keyboards and parseFloat('1,09') is 1, so the separator
 * is normalised first and anything still unreadable is refused outright rather
 * than half-read.
 */
// The symbol every renderer actually prints. The picker table is label data
// and disagrees with the server's own map on CHF and MXN, so a preview drawn
// from it shows money written a way no receipt will ever write it.
export function previewSymbol( code ) {
    const c = String( code || '' ).toUpperCase();

    return CURRENCY_SYMBOLS[ c ] || c;
}

export function parseRate( text ) {
    const raw = String( text ).trim().replace( ',', '.' );
    if ( raw === '' || ! /^\d*\.?\d*$/.test( raw ) ) return null;
    const n = Number( raw );
    return Number.isFinite( n ) && n > 0 ? n : null;
}

// Holds draft text while editing; commits a parsed number on change.
function RateInput( { value, manual, onChange } ) {
    const [ text, setText ] = useState( null );
    const shown = text !== null ? text : fmtRate( value );
    const invalid = text !== null && text.trim() !== '' && parseRate( text ) === null;
    return (
        <>
            <input
                className={ `fundkit-input fundkit-rate-input${ manual ? ' is-manual' : '' }` }
                inputMode="decimal"
                aria-invalid={ invalid || undefined }
                value={ shown }
                onChange={ ( e ) => {
                    setText( e.target.value );
                    const n = parseRate( e.target.value );
                    if ( n !== null ) onChange( n );
                } }
                onBlur={ () => setText( null ) }
            />
            { invalid && (
                <div className="fundkit-fx__hint">
                    <span>{ __( 'Not a rate. Enter a number like 1.09; the current rate stands until you do.', 'fundraising-toolkit' ) }</span>
                </div>
            ) }
        </>
    );
}

function freshnessPill( fx ) {
    if ( ! fx.auto ) {
        return <span className="fundkit-pill fundkit-pill--amber">{ __( 'Manual updates only', 'fundraising-toolkit' ) }</span>;
    }
    if ( fx.stale ) {
        return <span className="fundkit-pill fundkit-pill--amber">{ __( 'Rates are stale', 'fundraising-toolkit' ) }</span>;
    }
    return (
        <span className="fundkit-pill fundkit-pill--green">
            { fx.date
                ? sprintf( /* translators: %s: date */ __( 'Updated %s', 'fundraising-toolkit' ), fx.date )
                : __( 'Up to date', 'fundraising-toolkit' ) }
        </span>
    );
}

function ExchangeRatesCard( { fx, base } ) {
    if ( fx.loading ) {
        return <Card title={ __( 'Exchange rates', 'fundraising-toolkit' ) }><p className="fundkit-muted">{ __( 'Loading rates…', 'fundraising-toolkit' ) }</p></Card>;
    }

    const head = (
        <div className="fundkit-fx-head">
            { freshnessPill( fx ) }
            <Btn size="sm" onClick={ fx.fetchNow } disabled={ fx.fetching }>
                <svg viewBox="0 0 16 16" fill="none" width="13" height="13" aria-hidden="true">
                    <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9M13.5 2v3h-3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                { fx.fetching ? __( 'Fetching…', 'fundraising-toolkit' ) : __( 'Fetch rates now', 'fundraising-toolkit' ) }
            </Btn>
        </div>
    );

    const foot = fx.auto
        ? __( 'Rates are snapshotted onto each donation when it is made. Editing a rate only affects donations created afterwards; existing donations and their totals never change.', 'fundraising-toolkit' )
        : __( 'Automatic updates are off. New donations use whatever rate is set here at the moment they are made.', 'fundraising-toolkit' );

    return (
        <Card
            title={ __( 'Exchange rates', 'fundraising-toolkit' ) }
            sub={ sprintf( /* translators: %s: base currency code */ __( '1 %s equals the amounts below. Used to value non-base donations for reporting.', 'fundraising-toolkit' ), base ) }
            meta={ head }
            foot={ foot }
            edited={ fx.isDirty }
        >
            { ( fx.unconvertible || [] ).length > 0 && (
                <div className="fundkit-connect-notice fundkit-connect-notice--amber">
                    <span className="fundkit-connect-notice__icon" aria-hidden="true">!</span>
                    <div>
                        <strong>
                            { sprintf(
                                /* translators: %s: comma-separated currency codes */
                                __( 'No exchange rate for %s.', 'fundraising-toolkit' ),
                                ( fx.unconvertible || [] ).join( ', ' )
                            ) }
                        </strong>{ ' ' }
                        { __( 'Donations in these currencies are still accepted, but nothing about them converts. A donor who switches is offered your preset amounts at face value, so a preset authored as 100 asks for 100 of that currency however little that is worth, and the donation counts as zero in campaign, fund and donor totals. Add a rate below, or stop offering the currency.', 'fundraising-toolkit' ) }
                    </div>
                </div>
            ) }

            { ( fx.no_gateway || [] ).length > 0 && (
                <div className="fundkit-connect-notice fundkit-connect-notice--amber">
                    <span className="fundkit-connect-notice__icon" aria-hidden="true">!</span>
                    <div>
                        <strong>
                            { sprintf(
                                /* translators: %s: comma-separated currency codes */
                                __( 'No payment method accepts %s.', 'fundraising-toolkit' ),
                                ( fx.no_gateway || [] ).join( ', ' )
                            ) }
                        </strong>{ ' ' }
                        { __( 'A donor who picks one of these gets as far as the payment step and can go no further. Enable a gateway that takes the currency, or stop offering it.', 'fundraising-toolkit' ) }
                    </div>
                </div>
            ) }

            <ToggleRow
                title={ __( 'Update rates automatically every day', 'fundraising-toolkit' ) }
                sub={ sprintf(
                    /* translators: %s: rate source */
                    __( 'Pulled from %s (free, no key). When off, rates only change when you fetch or edit them here.', 'fundraising-toolkit' ),
                    fx.source || __( 'the European Central Bank', 'fundraising-toolkit' )
                ) }
                checked={ fx.auto }
                onChange={ fx.setAuto }
            />

            <table className="fundkit-fx">
                <thead>
                    <tr>
                        <th>{ __( 'Currency', 'fundraising-toolkit' ) }</th>
                        <th className="fundkit-fx__num">
                            { sprintf( /* translators: %s: base currency code */ __( 'Rate (1 %s =)', 'fundraising-toolkit' ), base ) }
                        </th>
                        <th>{ __( 'Source', 'fundraising-toolkit' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { fx.rows.map( ( row ) => {
                        const meta = currencyByCode( row.code );
                        return (
                            <tr key={ row.code }>
                                <td>
                                    <div className="fundkit-fx__ccy">
                                        <span className="fundkit-fx__flag">{ meta?.symbol || row.code }</span>
                                        <span>
                                            <strong>{ row.code }</strong>{ ' ' }
                                            <span className="fundkit-fx__name">{ meta?.label || '' }</span>
                                        </span>
                                    </div>
                                </td>
                                <td className="fundkit-fx__num">
                                    { row.is_base ? (
                                        <input className="fundkit-rate-input" value="1.00000000" disabled />
                                    ) : (
                                        <>
                                            <RateInput
                                                value={ row.rate }
                                                manual={ row.is_manual }
                                                onChange={ ( n ) => fx.setManual( row.code, n ) }
                                            />
                                            { row.is_manual && row.auto_rate != null && (
                                                <div className="fundkit-fx__hint">
                                                    <span>{ sprintf( /* translators: %s: rate */ __( 'auto: %s', 'fundraising-toolkit' ), fmtRate( row.auto_rate ) ) }</span>
                                                    <a
                                                        href="#reset"
                                                        className="fundkit-fx__reset"
                                                        onClick={ ( e ) => { e.preventDefault(); fx.resetManual( row.code ); } }
                                                    >
                                                        { __( 'Reset', 'fundraising-toolkit' ) }
                                                    </a>
                                                </div>
                                            ) }
                                        </>
                                    ) }
                                </td>
                                <td>
                                    { row.is_base ? (
                                        <span className="fundkit-pill fundkit-pill--gray">{ __( 'Base currency', 'fundraising-toolkit' ) }</span>
                                    ) : row.is_manual ? (
                                        <span className="fundkit-fx__src">{ __( 'Set by you', 'fundraising-toolkit' ) }</span>
                                    ) : (
                                        <span className="fundkit-fx__src">{ __( 'Auto', 'fundraising-toolkit' ) }</span>
                                    ) }
                                </td>
                            </tr>
                        );
                    } ) }
                </tbody>
            </table>
        </Card>
    );
}

const SHIPPED_CURRENCY = 'USD';

export default function CurrencyPanel( { s, fx } ) {
    const defaultCurrency = s.value( 'default_currency', 'USD' );
    // Server-computed, read-only: once money is in, every stored base amount is
    // already denominated in this currency and nothing restates them.
    const baseLocked = !! s.record.base_currency_locked;
    const supported = Array.isArray( s.record.supported_currencies ) ? s.record.supported_currencies : [ 'USD' ];

    const [ presetApplied, setPresetApplied ] = useState( '' );

    // These fell back to European separators while the server's defaults are
    // '.' and ',', so an unsaved panel disagreed with what PHP would render.
    const decimalPlaces  = Number( s.value( 'format.decimal_places', 2 ) );
    const decimalSep     = String( s.value( 'format.decimal_sep', '.' ) );
    const thousandSep    = String( s.value( 'format.thousand_sep', ',' ) );
    const symbolPosition = String( s.value( 'format.symbol_position', 'before' ) );

    const symbol = previewSymbol( defaultCurrency );
    const preview = previewAmount( 1234.56, { decimalPlaces, decimalSep, thousandSep, symbol, symbolPosition } );

    // Presets come from the server so PHP stays the one place a currency's
    // conventions are written down.
    const presetFor = ( code ) => window.fundkit?.currency_formats?.[ code ] || null;

    const applyCurrency = ( code ) => {
        // Always persist the base currency. Treat the default lone USD as unconfigured,
        // matching onboarding.
        const onlyShipped = supported.length === 1 && supported[ 0 ] === SHIPPED_CURRENCY;
        const base        = onlyShipped ? [] : supported;
        const nextSupported = base.includes( code ) ? base : [ ...base, code ];
        const patch = { default_currency: code, supported_currencies: nextSupported };

        // Picking a currency is the only moment we know what the format should
        // be. Nothing is written until save, so the fields change in front of
        // the admin and can be edited back.
        const preset = presetFor( code );
        if ( preset ) patch.format = { ...preset };

        s.edit( patch );
        setPresetApplied( preset ? code : '' );
    };

    const toggleSupported = ( code ) => {
        if ( code === defaultCurrency ) return; // base is always on
        const on = supported.includes( code );
        const next = on ? supported.filter( ( c ) => c !== code ) : [ ...supported, code ];
        if ( next.length === 0 ) return;
        s.edit( { supported_currencies: next } );
    };

    return (
        <div className="fundkit-panel">
            <Card title={ __( 'Currencies', 'fundraising-toolkit' ) } edited={ s.isDirty }>
                <FormRow
                    label={ __( 'Base currency', 'fundraising-toolkit' ) }
                    help={ baseLocked
                        ? sprintf(
                            /* translators: %s: base currency code */
                            __( 'Locked to %s: donations are already recorded against it, and their stored totals would be reread as the new currency. Existing campaigns keep their own currency.', 'fundraising-toolkit' ),
                            defaultCurrency
                        )
                        : __( 'All reporting and totals roll up to this, and it cannot be changed once donations come in. Existing campaigns keep their own currency.', 'fundraising-toolkit' ) }
                >
                    <select
                        className="fundkit-select"
                        disabled={ baseLocked }
                        value={ defaultCurrency }
                        onChange={ ( e ) => applyCurrency( e.target.value ) }
                    >
                        { CURRENCIES.map( ( c ) => (
                            <option key={ c.code } value={ c.code }>{ c.code } · { c.label } ({ c.symbol })</option>
                        ) ) }
                    </select>
                </FormRow>

                <FormRow
                    label={ __( 'Currencies donors can use', 'fundraising-toolkit' ) }
                    help={ sprintf(
                        /* translators: %s: base currency code */
                        __( '%s is always on as the base. Enable more to accept donations in other currencies.', 'fundraising-toolkit' ),
                        defaultCurrency
                    ) }
                    wide
                >
                    <div className="fundkit-cur-chips">
                        { CURRENCIES.map( ( c ) => {
                            const on     = supported.includes( c.code ) || c.code === defaultCurrency;
                            const locked = c.code === defaultCurrency;
                            return (
                                <button
                                    type="button"
                                    key={ c.code }
                                    className={ `fundkit-cur-chip${ on ? ' is-on' : '' }${ locked ? ' is-locked' : '' }` }
                                    onClick={ () => toggleSupported( c.code ) }
                                    aria-pressed={ on }
                                >
                                    <span className="fundkit-cur-chip__box">
                                        { on && (
                                            <svg viewBox="0 0 12 12" width="9" height="9" aria-hidden="true">
                                                <path d="M2 6l3 3 5-6" fill="none" stroke="currentColor" strokeWidth="2" />
                                            </svg>
                                        ) }
                                    </span>
                                    { c.code }
                                    { locked && <span className="fundkit-cur-chip__tag">{ __( 'base', 'fundraising-toolkit' ) }</span> }
                                </button>
                            );
                        } ) }
                    </div>
                </FormRow>
            </Card>

            { /* On the rows the server built, not on the settings record: a
                 currency donations were taken in without a rate needs an input
                 here whether or not the org still accepts it. A genuinely
                 single-currency site still gets no card, since rows is the base
                 alone. */ }
            { fx && ( ( fx.rows || [] ).length > 1 || ( fx.loading && supported.length > 1 ) ) && (
                <ExchangeRatesCard fx={ fx } base={ fx.base || defaultCurrency } />
            ) }

            <Card
                title={ __( 'Currency settings', 'fundraising-toolkit' ) }
                meta={ __( 'Receipts, exports, donation form', 'fundraising-toolkit' ) }
                edited={ s.isDirty }
            >
                { presetApplied && (
                    <p className="fundkit-muted" style={ { marginTop: 0 } }>
                        { sprintf(
                            /* translators: %s: currency code */
                            __( 'Set to how %s is usually written. Change anything below if your organisation writes it differently.', 'fundraising-toolkit' ),
                            presetApplied
                        ) }
                    </p>
                ) }
                <div className="fundkit-currency-preview">
                    <span className="fundkit-currency-preview__label">{ __( 'Preview', 'fundraising-toolkit' ) }</span>
                    <span className="fundkit-currency-preview__value num">{ preview }</span>
                </div>

                <FormRow label={ __( 'Decimal places', 'fundraising-toolkit' ) }>
                    <select
                        className="fundkit-select"
                        value={ String( decimalPlaces ) }
                        onChange={ ( e ) => s.edit( { format: { decimal_places: Number( e.target.value ) } } ) }
                    >
                        <option value="0">{ __( '0 (no cents)', 'fundraising-toolkit' ) }</option>
                        <option value="2">{ __( '2 (standard)', 'fundraising-toolkit' ) }</option>
                    </select>
                </FormRow>

                <FormRow label={ __( 'Decimal separator', 'fundraising-toolkit' ) }>
                    <select
                        className="fundkit-select"
                        value={ decimalSep }
                        onChange={ ( e ) => s.edit( { format: { decimal_sep: e.target.value } } ) }
                    >
                        <option value=",">{ __( 'Comma (1.234,56)', 'fundraising-toolkit' ) }</option>
                        <option value=".">{ __( 'Period (1,234.56)', 'fundraising-toolkit' ) }</option>
                    </select>
                </FormRow>

                <FormRow label={ __( 'Thousands separator', 'fundraising-toolkit' ) }>
                    <select
                        className="fundkit-select"
                        value={ thousandSep }
                        onChange={ ( e ) => s.edit( { format: { thousand_sep: e.target.value } } ) }
                    >
                        <option value=".">{ __( 'Period (1.234,56)', 'fundraising-toolkit' ) }</option>
                        <option value=",">{ __( 'Comma (1,234.56)', 'fundraising-toolkit' ) }</option>
                        <option value=" ">{ __( 'Space (1 234,56)', 'fundraising-toolkit' ) }</option>
                        <option value="'">{ __( "Apostrophe (1'234.56)", 'fundraising-toolkit' ) }</option>
                        <option value="">{ __( 'None (1234,56)', 'fundraising-toolkit' ) }</option>
                    </select>
                </FormRow>

                <FormRow label={ __( 'Symbol position', 'fundraising-toolkit' ) }>
                    <select
                        className="fundkit-select"
                        value={ symbolPosition }
                        onChange={ ( e ) => s.edit( { format: { symbol_position: e.target.value } } ) }
                    >
                        { /* Built from the chosen currency and separators: a fixed
                             example contradicts the preview above it. */ }
                        <option value="before">
                            { sprintf(
                                /* translators: %s: an example amount, e.g. $10.00 */
                                __( 'Before amount (%s)', 'fundraising-toolkit' ),
                                previewAmount( 10, { decimalPlaces, decimalSep, thousandSep, symbol, symbolPosition: 'before' } )
                            ) }
                        </option>
                        <option value="after">
                            { sprintf(
                                /* translators: %s: an example amount, e.g. 10.00 $ */
                                __( 'After amount (%s)', 'fundraising-toolkit' ),
                                previewAmount( 10, { decimalPlaces, decimalSep, thousandSep, symbol, symbolPosition: 'after' } )
                            ) }
                        </option>
                    </select>
                </FormRow>
            </Card>
        </div>
    );
}
