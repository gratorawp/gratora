import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import { ToggleRow } from '../../_shared/components/Switch';
import { CURRENCIES, LOCALES, currencyByCode, previewAmount } from '../../_shared/currency';

function fmtRate( n ) {
    const v = Number( n );
    return Number.isFinite( v ) ? v.toFixed( 8 ) : '';
}

// Holds draft text while editing; commits a parsed number on change.
function RateInput( { value, manual, onChange } ) {
    const [ text, setText ] = useState( null );
    const shown = text !== null ? text : fmtRate( value );
    return (
        <input
            className={ `dono-input dono-rate-input${ manual ? ' is-manual' : '' }` }
            inputMode="decimal"
            value={ shown }
            onChange={ ( e ) => {
                setText( e.target.value );
                const n = parseFloat( e.target.value );
                if ( Number.isFinite( n ) && n > 0 ) onChange( n );
            } }
            onBlur={ () => setText( null ) }
        />
    );
}

function freshnessPill( fx ) {
    if ( ! fx.auto ) {
        return <span className="dono-pill dono-pill--amber">{ __( 'Manual updates only', 'dono' ) }</span>;
    }
    if ( fx.stale ) {
        return <span className="dono-pill dono-pill--amber">{ __( 'Rates are stale', 'dono' ) }</span>;
    }
    return (
        <span className="dono-pill dono-pill--green">
            { fx.date
                ? sprintf( /* translators: %s: date */ __( 'Updated %s', 'dono' ), fx.date )
                : __( 'Up to date', 'dono' ) }
        </span>
    );
}

function ExchangeRatesCard( { fx, base } ) {
    if ( fx.loading ) {
        return <Card title={ __( 'Exchange rates', 'dono' ) }><p className="dono-muted">{ __( 'Loading rates…', 'dono' ) }</p></Card>;
    }

    const head = (
        <div className="dono-fx-head">
            { freshnessPill( fx ) }
            <Btn size="sm" onClick={ fx.fetchNow } disabled={ fx.fetching }>
                <svg viewBox="0 0 16 16" fill="none" width="13" height="13" aria-hidden="true">
                    <path d="M13.5 8a5.5 5.5 0 1 1-1.6-3.9M13.5 2v3h-3" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
                { fx.fetching ? __( 'Fetching…', 'dono' ) : __( 'Fetch rates now', 'dono' ) }
            </Btn>
        </div>
    );

    const foot = fx.auto
        ? __( 'Rates are snapshotted onto each donation when it is made. Editing a rate only affects donations created afterwards; existing donations and their totals never change.', 'dono' )
        : __( 'Automatic updates are off. New donations use whatever rate is set here at the moment they are made.', 'dono' );

    return (
        <Card
            title={ __( 'Exchange rates', 'dono' ) }
            sub={ sprintf( /* translators: %s: base currency code */ __( '1 %s equals the amounts below. Used to value non-base donations for reporting.', 'dono' ), base ) }
            meta={ head }
            foot={ foot }
            edited={ fx.isDirty }
        >
            { ( fx.unconvertible || [] ).length > 0 && (
                <div className="dono-connect-notice dono-connect-notice--amber">
                    <span className="dono-connect-notice__icon" aria-hidden="true">!</span>
                    <div>
                        <strong>
                            { sprintf(
                                /* translators: %s: comma-separated currency codes */
                                __( 'No exchange rate for %s.', 'dono' ),
                                ( fx.unconvertible || [] ).join( ', ' )
                            ) }
                        </strong>{ ' ' }
                        { __( 'Donations in these currencies are still accepted in full, but they cannot be valued in your base currency, so they count as zero in campaign, fund and donor totals. Add a rate below, or stop offering the currency.', 'dono' ) }
                    </div>
                </div>
            ) }

            { ( fx.no_gateway || [] ).length > 0 && (
                <div className="dono-connect-notice dono-connect-notice--amber">
                    <span className="dono-connect-notice__icon" aria-hidden="true">!</span>
                    <div>
                        <strong>
                            { sprintf(
                                /* translators: %s: comma-separated currency codes */
                                __( 'No payment method accepts %s.', 'dono' ),
                                ( fx.no_gateway || [] ).join( ', ' )
                            ) }
                        </strong>{ ' ' }
                        { __( 'A donor who picks one of these gets as far as the payment step and can go no further. Enable a gateway that takes the currency, or stop offering it.', 'dono' ) }
                    </div>
                </div>
            ) }

            <ToggleRow
                title={ __( 'Update rates automatically every day', 'dono' ) }
                sub={ sprintf(
                    /* translators: %s: rate source */
                    __( 'Pulled from %s (free, no key). When off, rates only change when you fetch or edit them here.', 'dono' ),
                    fx.source || __( 'the European Central Bank', 'dono' )
                ) }
                checked={ fx.auto }
                onChange={ fx.setAuto }
            />

            <table className="dono-fx">
                <thead>
                    <tr>
                        <th>{ __( 'Currency', 'dono' ) }</th>
                        <th className="dono-fx__num">
                            { sprintf( /* translators: %s: base currency code */ __( 'Rate (1 %s =)', 'dono' ), base ) }
                        </th>
                        <th>{ __( 'Source', 'dono' ) }</th>
                    </tr>
                </thead>
                <tbody>
                    { fx.rows.map( ( row ) => {
                        const meta = currencyByCode( row.code );
                        return (
                            <tr key={ row.code }>
                                <td>
                                    <div className="dono-fx__ccy">
                                        <span className="dono-fx__flag">{ meta?.symbol || row.code }</span>
                                        <span>
                                            <strong>{ row.code }</strong>{ ' ' }
                                            <span className="dono-fx__name">{ meta?.label || '' }</span>
                                        </span>
                                    </div>
                                </td>
                                <td className="dono-fx__num">
                                    { row.is_base ? (
                                        <input className="dono-rate-input" value="1.00000000" disabled />
                                    ) : (
                                        <>
                                            <RateInput
                                                value={ row.rate }
                                                manual={ row.is_manual }
                                                onChange={ ( n ) => fx.setManual( row.code, n ) }
                                            />
                                            { row.is_manual && row.auto_rate != null && (
                                                <div className="dono-fx__hint">
                                                    <span>{ sprintf( /* translators: %s: rate */ __( 'auto: %s', 'dono' ), fmtRate( row.auto_rate ) ) }</span>
                                                    <a
                                                        href="#reset"
                                                        className="dono-fx__reset"
                                                        onClick={ ( e ) => { e.preventDefault(); fx.resetManual( row.code ); } }
                                                    >
                                                        { __( 'Reset', 'dono' ) }
                                                    </a>
                                                </div>
                                            ) }
                                        </>
                                    ) }
                                </td>
                                <td>
                                    { row.is_base ? (
                                        <span className="dono-pill dono-pill--gray">{ __( 'Base currency', 'dono' ) }</span>
                                    ) : row.is_manual ? (
                                        <span className="dono-fx__src">{ __( 'Set by you', 'dono' ) }</span>
                                    ) : (
                                        <span className="dono-fx__src">{ __( 'Auto', 'dono' ) }</span>
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

export default function CurrencyPanel( { s, fx } ) {
    const defaultCurrency = s.value( 'default_currency', 'USD' );
    // Server-computed, read-only: once money is in, every stored base amount is
    // already denominated in this currency and nothing restates them.
    const baseLocked = !! s.record.base_currency_locked;
    const supported = Array.isArray( s.record.supported_currencies ) ? s.record.supported_currencies : [ 'USD' ];

    const decimalPlaces  = Number( s.value( 'format.decimal_places', 2 ) );
    const decimalSep     = String( s.value( 'format.decimal_sep', ',' ) );
    const thousandSep    = String( s.value( 'format.thousand_sep', '.' ) );
    const symbolPosition = String( s.value( 'format.symbol_position', 'before' ) );

    const symbol = currencyByCode( defaultCurrency )?.symbol || defaultCurrency;
    const preview = previewAmount( 1234.56, { decimalPlaces, decimalSep, thousandSep, symbol, symbolPosition } );

    const toggleSupported = ( code ) => {
        if ( code === defaultCurrency ) return; // base is always on
        const on = supported.includes( code );
        const next = on ? supported.filter( ( c ) => c !== code ) : [ ...supported, code ];
        if ( next.length === 0 ) return;
        s.edit( { supported_currencies: next } );
    };

    return (
        <div className="dono-panel">
            <Card title={ __( 'Currencies', 'dono' ) } edited={ s.isDirty }>
                <FormRow
                    label={ __( 'Base currency', 'dono' ) }
                    help={ baseLocked
                        ? sprintf(
                            /* translators: %s: base currency code */
                            __( 'Locked to %s: donations are already recorded against it, and their stored totals would be reread as the new currency. Existing campaigns keep their own currency.', 'dono' ),
                            defaultCurrency
                        )
                        : __( 'All reporting and totals roll up to this, and it cannot be changed once donations come in. Existing campaigns keep their own currency.', 'dono' ) }
                >
                    <select
                        className="dono-select"
                        disabled={ baseLocked }
                        value={ defaultCurrency }
                        onChange={ ( e ) => {
                            const code = e.target.value;
                            // Base is always accepted, so persist it into the
                            // supported list too - otherwise the UI shows it on
                            // while the saved set silently excludes it.
                            const nextSupported = supported.includes( code ) ? supported : [ ...supported, code ];
                            s.edit( { default_currency: code, supported_currencies: nextSupported } );
                        } }
                    >
                        { CURRENCIES.map( ( c ) => (
                            <option key={ c.code } value={ c.code }>{ c.code } · { c.label } ({ c.symbol })</option>
                        ) ) }
                    </select>
                </FormRow>

                <FormRow
                    label={ __( 'Currencies donors can use', 'dono' ) }
                    help={ sprintf(
                        /* translators: %s: base currency code */
                        __( '%s is always on as the base. Enable more to accept donations in other currencies.', 'dono' ),
                        defaultCurrency
                    ) }
                    wide
                >
                    <div className="dono-cur-chips">
                        { CURRENCIES.map( ( c ) => {
                            const on     = supported.includes( c.code ) || c.code === defaultCurrency;
                            const locked = c.code === defaultCurrency;
                            return (
                                <button
                                    type="button"
                                    key={ c.code }
                                    className={ `dono-cur-chip${ on ? ' is-on' : '' }${ locked ? ' is-locked' : '' }` }
                                    onClick={ () => toggleSupported( c.code ) }
                                    aria-pressed={ on }
                                >
                                    <span className="dono-cur-chip__box">
                                        { on && (
                                            <svg viewBox="0 0 12 12" width="9" height="9" aria-hidden="true">
                                                <path d="M2 6l3 3 5-6" fill="none" stroke="currentColor" strokeWidth="2" />
                                            </svg>
                                        ) }
                                    </span>
                                    { c.code }
                                    { locked && <span className="dono-cur-chip__tag">{ __( 'base', 'dono' ) }</span> }
                                </button>
                            );
                        } ) }
                    </div>
                </FormRow>
            </Card>

            { supported.length > 1 && fx && (
                <ExchangeRatesCard fx={ fx } base={ defaultCurrency } />
            ) }

            <Card
                title={ __( 'Currency settings', 'dono' ) }
                meta={ __( 'Receipts, exports, donation form', 'dono' ) }
                edited={ s.isDirty }
            >
                <div className="dono-currency-preview">
                    <span className="dono-currency-preview__label">{ __( 'Preview', 'dono' ) }</span>
                    <span className="dono-currency-preview__value num">{ preview }</span>
                </div>

                <FormRow label={ __( 'Decimal places', 'dono' ) }>
                    <select
                        className="dono-select"
                        value={ String( decimalPlaces ) }
                        onChange={ ( e ) => s.edit( { format: { decimal_places: Number( e.target.value ) } } ) }
                    >
                        <option value="0">{ __( '0 (no cents)', 'dono' ) }</option>
                        <option value="2">{ __( '2 (standard)', 'dono' ) }</option>
                    </select>
                </FormRow>

                <FormRow label={ __( 'Decimal separator', 'dono' ) }>
                    <select
                        className="dono-select"
                        value={ decimalSep }
                        onChange={ ( e ) => s.edit( { format: { decimal_sep: e.target.value } } ) }
                    >
                        <option value=",">{ __( 'Comma (1.234,56)', 'dono' ) }</option>
                        <option value=".">{ __( 'Period (1,234.56)', 'dono' ) }</option>
                    </select>
                </FormRow>

                <FormRow label={ __( 'Thousands separator', 'dono' ) }>
                    <select
                        className="dono-select"
                        value={ thousandSep }
                        onChange={ ( e ) => s.edit( { format: { thousand_sep: e.target.value } } ) }
                    >
                        <option value=".">{ __( 'Period (1.234,56)', 'dono' ) }</option>
                        <option value=",">{ __( 'Comma (1,234.56)', 'dono' ) }</option>
                        <option value=" ">{ __( 'Space (1 234,56)', 'dono' ) }</option>
                        <option value="'">{ __( "Apostrophe (1'234.56)", 'dono' ) }</option>
                        <option value="">{ __( 'None (1234,56)', 'dono' ) }</option>
                    </select>
                </FormRow>

                <FormRow label={ __( 'Symbol position', 'dono' ) }>
                    <select
                        className="dono-select"
                        value={ symbolPosition }
                        onChange={ ( e ) => s.edit( { format: { symbol_position: e.target.value } } ) }
                    >
                        { /* Built from the chosen currency and separators: a fixed
                             example contradicts the preview above it. */ }
                        <option value="before">
                            { sprintf(
                                /* translators: %s: an example amount, e.g. $10.00 */
                                __( 'Before amount (%s)', 'dono' ),
                                previewAmount( 10, { decimalPlaces, decimalSep, thousandSep, symbol, symbolPosition: 'before' } )
                            ) }
                        </option>
                        <option value="after">
                            { sprintf(
                                /* translators: %s: an example amount, e.g. 10.00 $ */
                                __( 'After amount (%s)', 'dono' ),
                                previewAmount( 10, { decimalPlaces, decimalSep, thousandSep, symbol, symbolPosition: 'after' } )
                            ) }
                        </option>
                    </select>
                </FormRow>
            </Card>

            <Card
                title={ __( 'Locale', 'dono' ) }
                meta={ __( 'Dates on receipts and exports', 'dono' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Locale', 'dono' ) }
                    help={ __( 'Defaults to your WordPress site language.', 'dono' ) }
                >
                    <select
                        className="dono-select"
                        value={ s.value( 'locale', '' ) }
                        onChange={ ( e ) => s.setValue( 'locale' )( e.target.value ) }
                    >
                        { LOCALES.map( ( l ) => (
                            <option key={ l.code } value={ l.code }>{ l.label }</option>
                        ) ) }
                    </select>
                </FormRow>
            </Card>
        </div>
    );
}
