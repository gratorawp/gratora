
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { __, _x, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { CURRENCIES } from '../_shared/currency';
import { backGlyph, forwardGlyph } from '../_shared/arrow';
import CountrySelect from '../_shared/components/CountrySelect';
import GratoraMark from '../_shared/components/GratoraMark';
import LocalIcon from '../_shared/components/Icon';
import SearchableSelect from '../_shared/components/SearchableSelect';

const TOTAL = 4;

// Pre-fills currency when country changes; falls back to USD.
const COUNTRY_TO_CURRENCY = {
    US: 'USD', CA: 'CAD', GB: 'GBP', AU: 'AUD', NZ: 'NZD',
    DE: 'EUR', FR: 'EUR', NL: 'EUR', IT: 'EUR', ES: 'EUR', AT: 'EUR',
    BE: 'EUR', PT: 'EUR', IE: 'EUR', FI: 'EUR', GR: 'EUR', LU: 'EUR',
    SI: 'EUR', SK: 'EUR', EE: 'EUR', LV: 'EUR', LT: 'EUR', CY: 'EUR', MT: 'EUR',
    CH: 'CHF', SE: 'SEK', NO: 'NOK', DK: 'DKK',
    JP: 'JPY', IN: 'INR', BR: 'BRL', MX: 'MXN', ZA: 'ZAR', SG: 'SGD', HK: 'HKD',
};

// Countries whose sub-divisions we collect; drives reveal logic and dropdown options.
const STATES_BY_COUNTRY = {
    US: [
        'Alabama','Alaska','Arizona','Arkansas','California','Colorado','Connecticut','Delaware',
        'District of Columbia','Florida','Georgia','Hawaii','Idaho','Illinois','Indiana','Iowa',
        'Kansas','Kentucky','Louisiana','Maine','Maryland','Massachusetts','Michigan','Minnesota',
        'Mississippi','Missouri','Montana','Nebraska','Nevada','New Hampshire','New Jersey',
        'New Mexico','New York','North Carolina','North Dakota','Ohio','Oklahoma','Oregon',
        'Pennsylvania','Rhode Island','South Carolina','South Dakota','Tennessee','Texas','Utah',
        'Vermont','Virginia','Washington','West Virginia','Wisconsin','Wyoming',
    ],
    CA: [
        'Alberta','British Columbia','Manitoba','New Brunswick','Newfoundland and Labrador',
        'Northwest Territories','Nova Scotia','Nunavut','Ontario','Prince Edward Island',
        'Quebec','Saskatchewan','Yukon',
    ],
    AU: [
        'Australian Capital Territory','New South Wales','Northern Territory','Queensland',
        'South Australia','Tasmania','Victoria','Western Australia',
    ],
};

/**
 * How the chosen currency is conventionally written, from the presets the
 * server publishes (CurrencyFormats).
 *
 * Currency, not country: where an organisation is and what it counts in are
 * different questions, and a Croatian charity raising in USD writes 1,234.56.
 */
export function formatForCurrency( code ) {
    const preset = ( typeof window !== 'undefined' ? window.gratora?.currency_formats : null )
        ?.[ String( code || '' ).trim().toUpperCase() ];

    if ( ! preset ) {
        return { decimal: '.', thousand: ',', symbolPosition: 'before', places: 2 };
    }

    return {
        decimal:        preset.decimal_sep,
        thousand:       preset.thousand_sep,
        symbolPosition: preset.symbol_position,
        places:         preset.decimal_places,
    };
}



const USER_TYPES = [
    {
        id:   'nonprofit',
        icon: 'building',
        name: __( 'Nonprofit or charity', 'gratora-donation-platform' ),
        desc: __( 'Registered organization collecting tax-deductible donations.', 'gratora-donation-platform' ),
    },
    {
        id:   'community',
        icon: 'users',
        name: __( 'Community or faith group', 'gratora-donation-platform' ),
        desc: __( 'Church, school, club, mutual-aid group.', 'gratora-donation-platform' ),
    },
    {
        id:   'individual',
        icon: 'heart',
        name: __( 'Individual fundraiser', 'gratora-donation-platform' ),
        desc: __( 'Personal cause, crowdfund, or memorial fund.', 'gratora-donation-platform' ),
    },
    {
        id:   'exploring',
        icon: 'target',
        name: __( 'Just exploring', 'gratora-donation-platform' ),
        desc: __( 'Trying Gratora out before deciding.', 'gratora-donation-platform' ),
    },
];

export default function Onboarding() {
    const wp = window.gratora?.wp || {};
    const presets   = Array.isArray( window.gratora?.styling?.presets ) ? window.gratora.styling.presets : [];
    const defaultId = String( window.gratora?.styling?.default_id || 'classic' );

    const [ step, setStep ]     = useState( 0 );
    const [ busy, setBusy ]     = useState( false );
    const [ error, setError ]   = useState( null );

    // Move focus to the new step's heading on step change (not initial mount) so
    // keyboard and screen-reader users land on the fresh content instead of the
    // nav button whose label just changed under them.
    const frameRef    = useRef( null );
    const stepMounted = useRef( false );
    useEffect( () => {
        if ( ! stepMounted.current ) { stepMounted.current = true; return; }
        const h = frameRef.current?.querySelector( '.gratora-onboarding__headline' );
        if ( h ) { h.setAttribute( 'tabindex', '-1' ); h.focus(); }
    }, [ step ] );

    const [ org, setOrg ] = useState( {
        name:           wp.site_name || '',
        email:          wp.admin_email || '',
        country:        '',
        state:          '',
    } );
    const [ currency, setCurrency ] = useState( { default_currency: 'USD' } );
    const [ brand, setBrand ] = useState( { preset_id: defaultId } );
    const [ who, setWho ] = useState( {
        user_type: '',
    } );

    // The finalize response: what the site has, for the last screen.
    const [ finalized, setFinalized ] = useState( null );

    // Pre-populate from saved settings so a resumed onboarding starts from prior inputs.
    useEffect( () => {
        apiFetch( { path: '/gratora/v1/admin/settings/org-profile' } )
            .then( ( d ) => {
                if ( ! d ) return;
                setOrg( ( prev ) => ( {
                    name:          d.name          || prev.name,
                    email:         d.email         || prev.email,
                    country:       d.country       || prev.country,
                    state:         d.state         || prev.state,
                } ) );
                setWho( ( prev ) => ( {
                    ...prev,
                    user_type: d.user_type || prev.user_type,
                } ) );
            } )
            .catch( () => {} );
        apiFetch( { path: '/gratora/v1/admin/settings/currency-locale' } )
            .then( ( d ) => {
                if ( ! d ) return;
                // Hydrate the full record so a re-run preserves multi-currency,
                // locale, and format instead of collapsing them to defaults.
                setCurrency( ( prev ) => ( {
                    default_currency:     d.default_currency || prev.default_currency,
                    supported_currencies: Array.isArray( d.supported_currencies )
                        ? d.supported_currencies
                        : prev.supported_currencies,
                    locale:               d.locale || prev.locale,
                    format:               d.format && typeof d.format === 'object'
                        ? d.format
                        : prev.format,
                } ) );
            } )
            .catch( () => {} );
        // Reload the saved brand preset id so a resumed wizard reflects the
        // latest org-brand option, not the page-load snapshot of window.gratora.
        apiFetch( { path: '/gratora/v1/admin/settings/org-brand' } )
            .then( ( d ) => {
                if ( d?.default_id ) setBrand( { preset_id: String( d.default_id ) } );
            } )
            .catch( () => {} );
    }, [] );

    const persist = async ( group, payload ) => {
        await apiFetch( {
            path:   `/gratora/v1/admin/settings/${ group }`,
            method: 'PUT',
            data:   payload,
        } );
    };

    const next = async () => {
        setError( null );
        setBusy( true );
        try {
            if ( step === 0 ) {
                if ( ! who.user_type ) {
                    throw new Error( __( 'Pick who is fundraising to continue.', 'gratora-donation-platform' ) );
                }
                await persist( 'org-profile', { user_type: who.user_type } );
            } else if ( step === 1 ) {
                if ( ! org.country ) {
                    throw new Error( __( 'Pick a country to continue.', 'gratora-donation-platform' ) );
                }
                // A country that subdivides still needs its state: it is part of
                // where the organization is, and it is one click. The postal
                // address is not asked for here -- it is optional in Settings,
                // and the receipt renderer omits the block when it is unset.
                if ( STATES_BY_COUNTRY[ org.country ] && ! ( org.state || '' ).trim() ) {
                    throw new Error( __( 'Pick a state or province to continue.', 'gratora-donation-platform' ) );
                }
                await persist( 'org-profile', {
                    name:           org.name,
                    email:          org.email,
                    country:        org.country,
                    state:          org.state,
                } );

                const fmt = formatForCurrency( currency.default_currency );
                await persist( 'currency-locale', {
                    default_currency:     currency.default_currency,
                    supported_currencies: chosenCurrencies( currency ),
                    locale:               currency.locale || '',
                    format: {
                        decimal_places:  chosenFormat( currency, 'decimal_places', fmt.places ),
                        // Keep separators the operator already chose; derive
                        // the rest from the currency they just picked. A value
                        // counts as chosen when it differs from what ships:
                        // the wizard has no separator field of its own, so
                        // anything else here came from Settings > Currency.
                        decimal_sep:     chosenFormat( currency, 'decimal_sep', fmt.decimal ),
                        thousand_sep:    chosenFormat( currency, 'thousand_sep', fmt.thousand ),
                        symbol_position: chosenFormat( currency, 'symbol_position', fmt.symbolPosition ),
                    },
                } );
            } else if ( step === 2 ) {
                await persist( 'org-brand', { default_id: brand.preset_id } );
                const r = await apiFetch( {
                    path:   '/gratora/v1/admin/onboarding/finalize',
                    method: 'POST',
                } );
                if ( ! r?.ok ) throw new Error( __( 'Could not finalize onboarding.', 'gratora-donation-platform' ) );
                setFinalized( r );
            }
            setStep( ( s ) => Math.min( TOTAL - 1, s + 1 ) );
        } catch ( err ) {
            setError( err?.message || __( 'Could not save. Please try again.', 'gratora-donation-platform' ) );
        } finally {
            setBusy( false );
        }
    };

    const back = () => {
        setError( null );
        setStep( ( s ) => Math.max( 0, s - 1 ) );
    };

    const skip = async () => {
        if ( busy ) return;
        setError( null );
        try {
            await apiFetch( { path: '/gratora/v1/admin/onboarding/dismiss', method: 'POST' } );
        } catch ( e ) {
            // A failed dismiss leaves onboarding 'pending', so admin_init would
            // bounce us straight back here; surface the error instead of looping.
            setError( __( 'Could not skip setup. Please try again.', 'gratora-donation-platform' ) );
            return;
        }
        window.location.assign( wp.settings_url || wp.dashboard_url || '' );
    };

    const isChecklist = step === TOTAL - 1;

    return (
        <div className="gratora-onboarding">
            <div className={ `gratora-onboarding__top${ step === 2 ? ' is-wide' : '' }` }>
                <span className="gratora-onboarding__brand">
                    <GratoraMark size={ 28 } />
                    <span className="gratora-onboarding__brand-name">Gratora</span>
                </span>
                { ! isChecklist && (
                    <button type="button" className="gratora-onboarding__skip" onClick={ skip }>
                        { __( 'Skip for now', 'gratora-donation-platform' ) }
                    </button>
                ) }
            </div>

            <section ref={ frameRef } className={ `gratora-onboarding__frame${ step === 2 ? ' is-wide' : '' }` }>
                <div className="gratora-onboarding__meta">
                    <span className="gratora-onboarding__caption">
                        { sprintf( /* translators: %1$d: current step number. %2$d: total number of steps. */ __( 'Step %1$d of %2$d', 'gratora-donation-platform' ), step + 1, TOTAL ) }
                    </span>
                    <span className="gratora-onboarding__dots" aria-hidden="true">
                        { Array.from( { length: TOTAL } ).map( ( _, i ) => (
                            <span
                                key={ i }
                                className={ `gratora-onboarding__dot${ i < step ? ' is-done' : '' }${ i === step ? ' is-current' : '' }` }
                            />
                        ) ) }
                    </span>
                </div>

                { step === 0 && <FundraiserTypeStep value={ who } onChange={ setWho } /> }
                { step === 1 && <LocationStep value={ org } onChange={ setOrg } currency={ currency } onCurrencyChange={ setCurrency } userType={ who.user_type } /> }
                { step === 2 && <BrandStep value={ brand } onChange={ setBrand } presets={ presets } currency={ currency.default_currency } /> }
                { step === 3 && (
                    <ChecklistStep
                        facts={ finalized?.first_run }
                        dashboardUrl={ wp.dashboard_url }
                        campaignsUrl={ wp.campaigns_url }
                    />
                ) }

                { error && <div className="gratora-onboarding__error" role="alert">{ error }</div> }

                { ! isChecklist && (
                    <footer
                        className={ `gratora-onboarding__nav${ step === 0 ? ' gratora-onboarding__nav--centered' : '' }` }
                    >
                        { step > 0 && (
                            <button
                                type="button"
                                className="gratora-btn gratora-btn--ghost"
                                onClick={ back }
                                disabled={ busy }
                            >
                                { backGlyph() } { __( 'Back', 'gratora-donation-platform' ) }
                            </button>
                        ) }

                        <button
                            type="button"
                            className="gratora-btn gratora-btn--primary gratora-btn--lg"
                            onClick={ next }
                            disabled={ busy }
                        >
                            { ctaLabel( step, busy ) }
                        </button>
                    </footer>
                ) }
            </section>
        </div>
    );
}

function ctaLabel( step, busy ) {
    if ( busy ) return __( 'Saving…', 'gratora-donation-platform' );
    if ( step === 0 ) return __( 'Get started', 'gratora-donation-platform' );
    if ( step === 2 ) return __( 'Finish setup', 'gratora-donation-platform' ) + ' ' + forwardGlyph();
    return __( 'Next', 'gratora-donation-platform' ) + ' ' + forwardGlyph();
}

function FundraiserTypeStep( { value, onChange } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    return (
        <div>
            <h2 className="gratora-onboarding__headline">
                { __( "Who's fundraising?", 'gratora-donation-platform' ) }
            </h2>
            <p className="gratora-onboarding__subtitle">
                { __( 'Pick the one that fits best.', 'gratora-donation-platform' ) }
            </p>

            <div className="gratora-onboarding__section">
                <div className="gratora-onboarding__usertype">
                    { USER_TYPES.map( ( t ) => {
                        const isSel = value.user_type === t.id;
                        return (
                            <button
                                key={ t.id }
                                type="button"
                                className={ `gratora-onboarding__usertype-tile${ isSel ? ' is-selected' : '' }` }
                                onClick={ () => set( { user_type: t.id } ) }
                                aria-pressed={ isSel }
                            >
                                <span className="gratora-onboarding__usertype-check" aria-hidden="true">
                                    <LocalIcon name="check" size={ 12 } strokeWidth={ 3 } />
                                </span>
                                <span className="gratora-onboarding__usertype-glyph" aria-hidden="true">
                                    <LocalIcon name={ t.icon } size={ 22 } strokeWidth={ 1.6 } />
                                </span>
                                <strong className="gratora-onboarding__usertype-name">{ t.name }</strong>
                                <span className="gratora-onboarding__usertype-desc">{ t.desc }</span>
                            </button>
                        );
                    } ) }
                </div>
            </div>

        </div>
    );
}

export function LocationStep( { value, onChange, currency, onCurrencyChange, userType } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    const isIndividual = userType === 'individual';

    const currencyOptions = useMemo(
        () => CURRENCIES.map( ( c ) => ( {
            value: c.code,
            label: `${ c.code } · ${ c.label } (${ c.symbol })`,
            hint:  c.label,
        } ) ),
        []
    );

    const onCountryChange = ( code ) => {
        const patch = { country: code };
        // Clear state if country has no sub-divisions.
        if ( ! STATES_BY_COUNTRY[ code ] ) patch.state = '';
        set( patch );
        const nextCurrency = COUNTRY_TO_CURRENCY[ code ];
        if ( nextCurrency ) {
            onCurrencyChange( ( prev ) => ( { ...prev, default_currency: nextCurrency } ) );
        }
    };

    const states = STATES_BY_COUNTRY[ value.country ];

    return (
        <div>
            <h2 className="gratora-onboarding__headline">{ __( 'Where are you based?', 'gratora-donation-platform' ) }</h2>
            <p className="gratora-onboarding__subtitle">
                { __( "We use this for receipts and your default currency.", 'gratora-donation-platform' ) }
            </p>

            <div className="gratora-onboarding__section">
                <div className="gratora-onboarding__section-label">
                    { isIndividual ? __( 'About you', 'gratora-donation-platform' ) : __( 'Organization', 'gratora-donation-platform' ) }
                </div>
                <div className="gratora-onboarding__address">
                    <div className="span-2">
                        <label className="gratora-onboarding__field-label" htmlFor="gratora-onboarding-name">
                            { isIndividual ? __( 'Your name', 'gratora-donation-platform' ) : __( 'Organization name', 'gratora-donation-platform' ) }
                        </label>
                        <input
                            id="gratora-onboarding-name"
                            type="text"
                            className="gratora-onboarding__input"
                            value={ value.name }
                            onChange={ ( e ) => set( { name: e.target.value } ) }
                            placeholder={ __( 'Shown on receipts and your campaign', 'gratora-donation-platform' ) }
                        />
                    </div>
                    <div className="span-2">
                        <label className="gratora-onboarding__field-label" htmlFor="gratora-onboarding-email">{ __( 'Contact email', 'gratora-donation-platform' ) }</label>
                        <input
                            id="gratora-onboarding-email"
                            type="email"
                            className="gratora-onboarding__input"
                            value={ value.email }
                            onChange={ ( e ) => set( { email: e.target.value } ) }
                            placeholder={ __( 'Where donors reply and receipts come from', 'gratora-donation-platform' ) }
                        />
                    </div>
                </div>
            </div>

            <div className="gratora-onboarding__section">
                <div className="gratora-onboarding__country-row">
                    { /* The pickers take no id or aria-label prop, so the name
                         only exists if the label contains the control. */ }
                    <label className="gratora-onboarding__control-label">
                        <span className="gratora-onboarding__section-label">{ __( 'Country', 'gratora-donation-platform' ) }</span>
                        <CountrySelect
                            value={ value.country }
                            onChange={ onCountryChange }
                        />
                    </label>
                    { states && (
                        <label className="gratora-onboarding__control-label">
                            <span className="gratora-onboarding__field-label">{ __( 'State', 'gratora-donation-platform' ) }</span>
                            <SearchableSelect
                                value={ value.state }
                                onChange={ ( v ) => set( { state: v } ) }
                                options={ states.map( ( s ) => ( { value: s, label: s } ) ) }
                                placeholder={ __( 'Select state', 'gratora-donation-platform' ) }
                            />
                        </label>
                    ) }
                </div>
            </div>


            <div className="gratora-onboarding__section">
                <label className="gratora-onboarding__control-label">
                    <span className="gratora-onboarding__section-label">{ __( 'Currency', 'gratora-donation-platform' ) }</span>
                    <SearchableSelect
                    value={ currency.default_currency }
                    onChange={ ( code ) => onCurrencyChange( ( prev ) => ( { ...prev, default_currency: code } ) ) }
                    options={ currencyOptions }
                    placeholder={ __( 'Pick a currency', 'gratora-donation-platform' ) }
                    />
                </label>
            </div>
        </div>
    );
}

// Step 4: Goal

// Step 4: Brand preset
const PRESET_CARDS = [
    {
        id:     'classic',
        thumb:  'classic',
        name:   __( 'Classic', 'gratora-donation-platform' ),
        desc:   __( 'Friendly, rounded, green. The safe choice.', 'gratora-donation-platform' ),
    },
    {
        id:     'bold',
        thumb:  'bold',
        name:   _x( 'Bold', 'style preset name', 'gratora-donation-platform' ),
        desc:   __( 'Deep navy, strong type, dramatic shadow.', 'gratora-donation-platform' ),
    },
    {
        id:     'quiet',
        thumb:  'quiet',
        name:   __( 'Quiet', 'gratora-donation-platform' ),
        desc:   __( 'Minimal lines, lots of white space.', 'gratora-donation-platform' ),
    },
    {
        id:     'theme',
        thumb:  'theme',
        name:   __( 'Use my theme', 'gratora-donation-platform' ),
        // desc filled at runtime from theme detection.
    },
];

// Sample blocks for the live preview (WYSIWYG - real runtime, real tokens).
function sampleBlocks( currency ) {
    const cur = ( currency || 'USD' ).toUpperCase();
    return [
        `<!-- wp:gratora/donation-amount {"presets":[2500,5000,10000],"allowCustom":true,"currency":"${ cur }"} /-->`,
        '<!-- wp:gratora/name {"requireFirst":true} /-->',
        '<!-- wp:gratora/email {"required":true} /-->',
        '<!-- wp:gratora/submit-button {"label":"Donate {amount}"} /-->',
    ].join( '\n\n' );
}

function BrandStep( { value, onChange, presets, currency = 'USD' } ) {
    const themePreset = presets.find( ( p ) => p.id === 'theme' );
    const selectedPreset = presets.find( ( p ) => p.id === value.preset_id ) || presets[ 0 ] || null;

    const blocks = useMemo( () => sampleBlocks( currency ), [ currency ] );
    const [ previewHtml, setPreviewHtml ] = useState( '' );
    const [ loadState, setLoadState ] = useState( 'loading' ); // 'loading' | 'loaded' | 'error'
    const [ reloadKey, setReloadKey ] = useState( 0 );
    const [ ready, setReady ] = useState( false );
    const frameRef = useRef( null );

    // Fetch once; preset switching pushes tokens without re-fetching.
    useEffect( () => {
        let cancelled = false;
        setReady( false );
        setLoadState( 'loading' );
        apiFetch( {
            path:   '/gratora/v1/admin/forms/preview',
            method: 'POST',
            data:   { blocks, settings: { container: { width: 460 } }, campaign_id: null },
        } )
            .then( ( res ) => { if ( ! cancelled ) { setPreviewHtml( res?.html || '' ); setLoadState( 'loaded' ); } } )
            .catch( () => { if ( ! cancelled ) { setPreviewHtml( '' ); setLoadState( 'error' ); } } );
        return () => { cancelled = true; };
    }, [ blocks, reloadKey ] );

    // Push only explicit token overrides; derived tokens resolve via the runtime.
    const pushTokens = useCallback( () => {
        const win = frameRef.current?.contentWindow;
        if ( ! win ) return;
        // '*' rather than our own origin: the preview is a srcdoc document with
        // an opaque origin, so a targeted post is never delivered. The frame is
        // one we built and its HTML is ours, so there is no third party to leak
        // a preset's colours to.
        win.postMessage( { type: 'gratora:apply-tokens', tokens: selectedPreset?.tokens || {} }, '*' );
    }, [ selectedPreset ] );

    useEffect( () => {
        const onMsg = ( e ) => {
            // Same reason: a srcdoc frame announces itself with origin "null".
            if ( e.source !== frameRef.current?.contentWindow ) return;
            if ( e?.data?.type === 'gratora:preview-ready' ) {
                setReady( true );
                pushTokens();
            }
        };
        window.addEventListener( 'message', onMsg );
        return () => window.removeEventListener( 'message', onMsg );
    }, [ pushTokens ] );

    useEffect( () => { if ( ready ) pushTokens(); }, [ ready, pushTokens ] );

    return (
        <div>
            <h2 className="gratora-onboarding__headline">{ __( 'Pick a starting look', 'gratora-donation-platform' ) }</h2>
            <p className="gratora-onboarding__subtitle">
                { __( 'You can edit colors and typography anytime.', 'gratora-donation-platform' ) }
            </p>
            <div className="gratora-onboarding__presets">
                { PRESET_CARDS.map( ( card ) => {
                    const isTheme   = card.id === 'theme';
                    const isDisabled = isTheme && ! themePreset;
                    const isSel     = value.preset_id === card.id;
                    const desc      = isTheme
                        ? ( themePreset
                            ? __( 'Inherits styles from your site theme.', 'gratora-donation-platform' )
                            : __( 'No theme palette detected.', 'gratora-donation-platform' ) )
                        : card.desc;
                    return (
                        <button
                            key={ card.id }
                            type="button"
                            className={ `gratora-onboarding__preset${ isSel ? ' is-selected' : '' }${ isDisabled ? ' is-disabled' : '' }` }
                            onClick={ () => { if ( ! isDisabled ) onChange( { preset_id: card.id } ); } }
                            aria-pressed={ isSel }
                            disabled={ isDisabled }
                        >
                            { isSel && (
                                <span className="gratora-onboarding__preset-check" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                                        <path d="M5 12.5l4 4 10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
                                    </svg>
                                </span>
                            ) }
                            <div className={ `gratora-onboarding__preset-thumb gratora-onboarding__preset-thumb--${ card.thumb }` }>
                                <span className="gratora-onboarding__preset-btn">{ __( 'Donate', 'gratora-donation-platform' ) }</span>
                            </div>
                            <strong className="gratora-onboarding__preset-name">{ card.name }</strong>
                            <span className="gratora-onboarding__preset-desc">{ desc }</span>
                        </button>
                    );
                } ) }
            </div>

            <div className="gratora-onboarding__preview">
                <div className="gratora-onboarding__preview-label">{ __( 'Live preview', 'gratora-donation-platform' ) }</div>
                { loadState === 'error' ? (
                    <div className="gratora-onboarding__preview-fallback">
                        <p>{ __( 'Preview unavailable. Your choice is still saved.', 'gratora-donation-platform' ) }</p>
                        <button
                            type="button"
                            className="gratora-btn gratora-btn--ghost"
                            onClick={ () => setReloadKey( ( k ) => k + 1 ) }
                        >
                            { __( 'Retry', 'gratora-donation-platform' ) }
                        </button>
                    </div>
                ) : (
                    <div className="gratora-onboarding__preview-stage">
                        { loadState === 'loading' && (
                            <div className="gratora-onboarding__preview-skeleton" aria-hidden="true" />
                        ) }
                        <iframe
                            ref={ frameRef }
                            className="gratora-onboarding__preview-frame"
                            title={ __( 'Donation form preview', 'gratora-donation-platform' ) }
                            // Omit allow-same-origin so preview scripts cannot use admin
                            // credentials. Token messages validate window references.
                            sandbox="allow-scripts"
                            srcDoc={ previewHtml }
                            style={ loadState === 'loaded' ? undefined : { visibility: 'hidden' } }
                        />
                    </div>
                ) }
            </div>
        </div>
    );
}

const DEMO_URL = 'https://gratora.net/demo/?utm_source=plugin&utm_medium=setup';

/**
 * What the first item offers, by what the site already has. A page is made
 * only when someone asks for one here: a campaign published at the end of
 * setup left every install with one whether or not it was wanted.
 */
function firstItem( facts, campaignsUrl ) {
    if ( facts.page === 'live' ) {
        return facts.test_mode
            ? {
                title: __( 'Make a test donation', 'gratora-donation-platform' ),
                /* translators: "Test donation" is the payment method as the donation form names it. Word it as that string is worded. */
                description: __( 'Open your page and give with the Test donation method. No card is charged.', 'gratora-donation-platform' ),
                cta: __( 'Open the page', 'gratora-donation-platform' ),
                href: facts.page_url,
            }
            : {
                title: __( 'Your donation page', 'gratora-donation-platform' ),
                description: __( 'Your page is published.', 'gratora-donation-platform' ),
                cta: __( 'Open the page', 'gratora-donation-platform' ),
                href: facts.page_url,
            };
    }

    if ( facts.page === 'closed' ) {
        return {
            title: __( 'Open a campaign for donations', 'gratora-donation-platform' ),
            description: __( 'You have a campaign, but none of them is taking donations right now.', 'gratora-donation-platform' ),
            cta: __( 'Open campaigns', 'gratora-donation-platform' ),
            href: campaignsUrl,
        };
    }

    return facts.test_mode
        ? {
            title: __( 'Make a test donation', 'gratora-donation-platform' ),
            description: __( 'We will add one donation page to your site, named after your organization, and open it. Test mode is on, so no card is charged.', 'gratora-donation-platform' ),
            cta: __( 'Create the page and try it', 'gratora-donation-platform' ),
        }
        : {
            title: __( 'Create your donation page', 'gratora-donation-platform' ),
            description: __( 'We will add one donation page to your site, named after your organization, and open it.', 'gratora-donation-platform' ),
            cta: __( 'Create the page', 'gratora-donation-platform' ),
        };
}

export function ChecklistStep( { facts = {}, dashboardUrl, campaignsUrl } ) {
    const [ busy, setBusy ]   = useState( false );
    const [ error, setError ] = useState( null );

    const first = firstItem( facts, campaignsUrl || dashboardUrl || '#' );

    const createAndOpen = async () => {
        if ( busy ) return;
        setBusy( true );
        setError( null );
        try {
            const made = await apiFetch( { path: '/gratora/v1/admin/onboarding/starter-campaign', method: 'POST' } );
            // This tab, not a new one: a window opened after a request has
            // answered is what pop-up blockers stop.
            window.location.href = made.page_url;
        } catch ( err ) {
            setError( err?.message || __( 'Could not create the page. Please try again.', 'gratora-donation-platform' ) );
            setBusy( false );
        }
    };

    return (
        <div>
            <h1 className="gratora-onboarding__headline">{ __( "You're set up", 'gratora-donation-platform' ) }</h1>
            <p className="gratora-onboarding__subtitle">
                { __( 'Your organization details are saved. Next, see a donation go through.', 'gratora-donation-platform' ) }
            </p>

            <ul className="gratora-onboarding__checklist">
                <ChecklistItem
                    title={ first.title }
                    description={ first.description }
                    cta={ first.cta }
                    href={ first.href }
                    onClick={ first.href ? undefined : createAndOpen }
                    busy={ busy }
                />
                <ChecklistItem
                    title={ __( 'See it with a year of sample data', 'gratora-donation-platform' ) }
                    description={ __( 'A demo site opens in a new tab. Nothing is added to your site.', 'gratora-donation-platform' ) }
                    cta={ __( 'Open the demo', 'gratora-donation-platform' ) }
                    href={ DEMO_URL }
                    newTab
                    secondary
                />
            </ul>

            { error && <div className="gratora-onboarding__error" role="alert">{ error }</div> }

            <p className="gratora-onboarding__checklist-foot">
                <a className="gratora-onboarding__checklist-skip" href={ dashboardUrl || '#' }>
                    { __( 'Go to the dashboard', 'gratora-donation-platform' ) }
                </a>
            </p>
        </div>
    );
}

function ChecklistItem( { title, description, href, cta, onClick, busy, newTab, secondary } ) {
    const classes = `gratora-btn gratora-btn--${ secondary ? 'secondary' : 'primary' }`;

    return (
        <li className="gratora-onboarding__checklist-item">
            <span className="gratora-onboarding__checklist-bullet" aria-hidden="true" />
            <div className="gratora-onboarding__checklist-body">
                <strong className="gratora-onboarding__checklist-title">{ title }</strong>
                <span className="gratora-onboarding__checklist-desc">{ description }</span>
            </div>
            { onClick
                ? (
                    <button
                        type="button"
                        className={ classes }
                        onClick={ onClick }
                        disabled={ busy }
                    >
                        { cta }
                    </button>
                )
                : (
                    <a className={ classes } href={ href } { ...( newTab ? { target: '_blank', rel: 'noreferrer' } : {} ) }>
                        { cta }
                        { newTab && <span className="screen-reader-text">{ __( '(opens in a new tab)', 'gratora-donation-platform' ) }</span> }
                    </a>
                ) }
        </li>
    );
}

/**
 * What ships when nobody has chosen anything, mirroring the currency-locale
 * defaults in SettingsService.
 */
const SHIPPED_FORMAT = {
    decimal_places:  2,
    decimal_sep:     '.',
    thousand_sep:    ',',
    symbol_position: 'before',
};

/** What ships when nobody has chosen: the currency-locale default in SettingsService. */
const SHIPPED_CURRENCIES = [ 'USD' ];

/** Include the base currency and treat the default lone USD as unconfigured. */
export function chosenCurrencies( currency ) {
    const base = String( currency?.default_currency || 'USD' ).toUpperCase();
    const list = ( Array.isArray( currency?.supported_currencies ) ? currency.supported_currencies : [] )
        .map( ( c ) => String( c ).toUpperCase() );

    const shipped = list.length === 1 && list[ 0 ] === SHIPPED_CURRENCIES[ 0 ];
    if ( list.length === 0 || shipped ) return [ base ];

    // Base is always accepted, so it belongs in the saved set: the panel shows
    // it on either way, and a resumed wizard whose operator changes country
    // would otherwise store a list its own base is missing from.
    return list.includes( base ) ? list : [ base, ...list ];
}

/**
 * The operator's own value, or the one derived from their country.
 *
 * Every settings read comes back with the shipped defaults merged in, so
 * "unset" is indistinguishable from "set to the default" by emptiness alone.
 * Differing from what ships is the only evidence of a choice there is.
 */
export function chosenFormat( currency, key, derived ) {
    const value = currency?.format?.[ key ];

    // Tested for presence rather than truthiness: decimal_places of 0 is a
    // real choice, and a yen org that made it would have had it read as unset.
    if ( value === undefined || value === null || value === '' ) return derived;

    return value !== SHIPPED_FORMAT[ key ] ? value : derived;
}

