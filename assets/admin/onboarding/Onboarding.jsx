
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { CURRENCIES } from '../_shared/currency';
import CountrySelect from '../_shared/components/CountrySelect';
import FundKitMark from '../_shared/components/FundKitMark';
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

// Derives digit separators from country (US: 1,234.56; EU: 1.234,56).
/**
 * How the chosen currency is conventionally written, from the presets the
 * server publishes (CurrencyFormats).
 *
 * This used to be derived from the country instead, on a list of which nations
 * write money the American way. That conflates where an organisation is with
 * what it counts in: a Croatian charity raising in USD picked USD and got
 * 1.234,56 $ anyway, because its country was not on the list. Deriving from the
 * currency keeps every case the country list existed to protect, since a German
 * org raising euros still gets euro separators.
 */
export function formatForCurrency( code ) {
    const preset = ( typeof window !== 'undefined' ? window.fundkit?.currency_formats : null )
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
        name: __( 'Nonprofit or charity', 'fundraising-toolkit' ),
        desc: __( 'Registered organization collecting tax-deductible donations.', 'fundraising-toolkit' ),
    },
    {
        id:   'community',
        icon: 'users',
        name: __( 'Community or faith group', 'fundraising-toolkit' ),
        desc: __( 'Church, school, club, mutual-aid group.', 'fundraising-toolkit' ),
    },
    {
        id:   'individual',
        icon: 'heart',
        name: __( 'Individual fundraiser', 'fundraising-toolkit' ),
        desc: __( 'Personal cause, crowdfund, or memorial fund.', 'fundraising-toolkit' ),
    },
    {
        id:   'exploring',
        icon: 'target',
        name: __( 'Just exploring', 'fundraising-toolkit' ),
        desc: __( 'Trying Fundraising Toolkit out. Starts in test mode, so nothing takes real money until you switch it off.', 'fundraising-toolkit' ),
    },
];

export default function Onboarding() {
    const wp = window.fundkit?.wp || {};
    const presets   = Array.isArray( window.fundkit?.styling?.presets ) ? window.fundkit.styling.presets : [];
    const defaultId = String( window.fundkit?.styling?.default_id || 'classic' );

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
        const h = frameRef.current?.querySelector( '.fundkit-onboarding__headline' );
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

    // Holds finalize response (campaign + form URLs) for checklist links.
    const [ finalized, setFinalized ] = useState( null );

    // Pre-populate from saved settings so a resumed onboarding starts from prior inputs.
    useEffect( () => {
        apiFetch( { path: '/fundkit/v1/admin/settings/org-profile' } )
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
        apiFetch( { path: '/fundkit/v1/admin/settings/currency-locale' } )
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
        // latest org-brand option, not the page-load snapshot of window.fundkit.
        apiFetch( { path: '/fundkit/v1/admin/settings/org-brand' } )
            .then( ( d ) => {
                if ( d?.default_id ) setBrand( { preset_id: String( d.default_id ) } );
            } )
            .catch( () => {} );
    }, [] );

    const persist = async ( group, payload ) => {
        await apiFetch( {
            path:   `/fundkit/v1/admin/settings/${ group }`,
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
                    throw new Error( __( 'Pick who is fundraising to continue.', 'fundraising-toolkit' ) );
                }
                await persist( 'org-profile', { user_type: who.user_type } );
            } else if ( step === 1 ) {
                if ( ! org.country ) {
                    throw new Error( __( 'Pick a country to continue.', 'fundraising-toolkit' ) );
                }
                // A country that subdivides still needs its state: it is part of
                // where the organization is, and it is one click. The postal
                // address is not asked for here -- it is optional in Settings,
                // and the receipt renderer omits the block when it is unset.
                if ( STATES_BY_COUNTRY[ org.country ] && ! ( org.state || '' ).trim() ) {
                    throw new Error( __( 'Pick a state or province to continue.', 'fundraising-toolkit' ) );
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
                        // the rest from the country they just picked.
                        //
                        // This used to test for an EMPTY value, and there is no
                        // such thing: the server merges its defaults into every
                        // settings read, so decimal_sep always arrived as '.'
                        // and the derivation right above could never fire. Every
                        // German, French, Dutch, Spanish, Italian and Nordic
                        // install finished the wizard with en-US separators and
                        // printed 1,234.56 on its donation form, its receipts,
                        // its tax statements and every admin screen, with
                        // nothing saying so and four fields to hand-fix.
                        //
                        // A value counts as chosen when it differs from what
                        // ships. The wizard has no separator field of its own,
                        // so anything else here came from Settings > Currency.
                        decimal_sep:     chosenFormat( currency, 'decimal_sep', fmt.decimal ),
                        thousand_sep:    chosenFormat( currency, 'thousand_sep', fmt.thousand ),
                        symbol_position: chosenFormat( currency, 'symbol_position', fmt.symbolPosition ),
                    },
                } );
            } else if ( step === 2 ) {
                await persist( 'org-brand', { default_id: brand.preset_id } );
                const r = await apiFetch( {
                    path:   '/fundkit/v1/admin/onboarding/finalize',
                    method: 'POST',
                    data:   {
                        campaign_title: org.name
                            ? `${ org.name } - ${ __( 'General donations', 'fundraising-toolkit' ) }`
                            : __( 'General donations', 'fundraising-toolkit' ),
                        user_type:      who.user_type,
                    },
                } );
                if ( ! r?.ok ) throw new Error( __( 'Could not finalize onboarding.', 'fundraising-toolkit' ) );
                setFinalized( r );
            }
            setStep( ( s ) => Math.min( TOTAL - 1, s + 1 ) );
        } catch ( err ) {
            setError( err?.message || __( 'Could not save. Please try again.', 'fundraising-toolkit' ) );
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
            await apiFetch( { path: '/fundkit/v1/admin/onboarding/dismiss', method: 'POST' } );
        } catch ( e ) {
            // A failed dismiss leaves onboarding 'pending', so admin_init would
            // bounce us straight back here; surface the error instead of looping.
            setError( __( 'Could not skip setup. Please try again.', 'fundraising-toolkit' ) );
            return;
        }
        window.location.assign( wp.settings_url || wp.dashboard_url || '' );
    };

    const isChecklist = step === TOTAL - 1;

    return (
        <div className="fundkit-onboarding">
            <div className={ `fundkit-onboarding__top${ step === 2 ? ' is-wide' : '' }` }>
                <span className="fundkit-onboarding__brand">
                    <FundKitMark size={ 28 } />
                    <span className="fundkit-onboarding__brand-name">FundKit</span>
                </span>
                { ! isChecklist && (
                    <button type="button" className="fundkit-onboarding__skip" onClick={ skip }>
                        { __( 'Skip for now', 'fundraising-toolkit' ) }
                    </button>
                ) }
            </div>

            <section ref={ frameRef } className={ `fundkit-onboarding__frame${ step === 2 ? ' is-wide' : '' }` }>
                <div className="fundkit-onboarding__meta">
                    <span className="fundkit-onboarding__caption">
                        { sprintf( /* translators: %1$d: current step number. %2$d: total number of steps. */ __( 'Step %1$d of %2$d', 'fundraising-toolkit' ), step + 1, TOTAL ) }
                    </span>
                    <span className="fundkit-onboarding__dots" aria-hidden="true">
                        { Array.from( { length: TOTAL } ).map( ( _, i ) => (
                            <span
                                key={ i }
                                className={ `fundkit-onboarding__dot${ i < step ? ' is-done' : '' }${ i === step ? ' is-current' : '' }` }
                            />
                        ) ) }
                    </span>
                </div>

                { step === 0 && <FundraiserTypeStep value={ who } onChange={ setWho } /> }
                { step === 1 && <LocationStep value={ org } onChange={ setOrg } currency={ currency } onCurrencyChange={ setCurrency } userType={ who.user_type } /> }
                { step === 2 && <BrandStep value={ brand } onChange={ setBrand } presets={ presets } currency={ currency.default_currency } /> }
                { step === 3 && (
                    <ChecklistStep
                        finalized={ finalized }
                        settingsUrl={ wp.settings_url }
                        dashboardUrl={ wp.dashboard_url }
                        campaignsUrl={ wp.campaigns_url }
                    />
                ) }

                { error && <div className="fundkit-onboarding__error" role="alert">{ error }</div> }

                { ! isChecklist && (
                    <footer
                        className={ `fundkit-onboarding__nav${ step === 0 ? ' fundkit-onboarding__nav--centered' : '' }` }
                    >
                        { step > 0 && (
                            <button
                                type="button"
                                className="fundkit-btn fundkit-btn--ghost"
                                onClick={ back }
                                disabled={ busy }
                            >
                                ← { __( 'Back', 'fundraising-toolkit' ) }
                            </button>
                        ) }

                        <button
                            type="button"
                            className="fundkit-btn fundkit-btn--primary fundkit-btn--lg"
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
    if ( busy ) return __( 'Saving…', 'fundraising-toolkit' );
    if ( step === 0 ) return __( 'Get started', 'fundraising-toolkit' );
    if ( step === 2 ) return __( 'Finish setup', 'fundraising-toolkit' ) + ' →';
    return __( 'Next', 'fundraising-toolkit' ) + ' →';
}

// Step 1: who is fundraising
function FundraiserTypeStep( { value, onChange } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    return (
        <div>
            <h2 className="fundkit-onboarding__headline">
                { __( "Who's fundraising?", 'fundraising-toolkit' ) }
            </h2>
            <p className="fundkit-onboarding__subtitle">
                { __( 'Pick the one that fits best.', 'fundraising-toolkit' ) }
            </p>

            <div className="fundkit-onboarding__section">
                <div className="fundkit-onboarding__usertype">
                    { USER_TYPES.map( ( t ) => {
                        const isSel = value.user_type === t.id;
                        return (
                            <button
                                key={ t.id }
                                type="button"
                                className={ `fundkit-onboarding__usertype-tile${ isSel ? ' is-selected' : '' }` }
                                onClick={ () => set( { user_type: t.id } ) }
                                aria-pressed={ isSel }
                            >
                                <span className="fundkit-onboarding__usertype-check" aria-hidden="true">
                                    <LocalIcon name="check" size={ 12 } strokeWidth={ 3 } />
                                </span>
                                <span className="fundkit-onboarding__usertype-glyph" aria-hidden="true">
                                    <LocalIcon name={ t.icon } size={ 22 } strokeWidth={ 1.6 } />
                                </span>
                                <strong className="fundkit-onboarding__usertype-name">{ t.name }</strong>
                                <span className="fundkit-onboarding__usertype-desc">{ t.desc }</span>
                            </button>
                        );
                    } ) }
                </div>
            </div>

        </div>
    );
}

// Step 3: Location & money
function LocationStep( { value, onChange, currency, onCurrencyChange, userType } ) {
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
            <h2 className="fundkit-onboarding__headline">{ __( 'Where are you based?', 'fundraising-toolkit' ) }</h2>
            <p className="fundkit-onboarding__subtitle">
                { __( "We use this for receipts and your default currency.", 'fundraising-toolkit' ) }
            </p>

            <div className="fundkit-onboarding__section">
                <div className="fundkit-onboarding__section-label">
                    { isIndividual ? __( 'About you', 'fundraising-toolkit' ) : __( 'Organization', 'fundraising-toolkit' ) }
                </div>
                <div className="fundkit-onboarding__address">
                    <div className="span-2">
                        <label className="fundkit-onboarding__field-label">
                            { isIndividual ? __( 'Your name', 'fundraising-toolkit' ) : __( 'Organization name', 'fundraising-toolkit' ) }
                        </label>
                        <input
                            type="text"
                            className="fundkit-onboarding__input"
                            value={ value.name }
                            onChange={ ( e ) => set( { name: e.target.value } ) }
                            placeholder={ __( 'Shown on receipts and your campaign', 'fundraising-toolkit' ) }
                        />
                    </div>
                    <div className="span-2">
                        <label className="fundkit-onboarding__field-label">{ __( 'Contact email', 'fundraising-toolkit' ) }</label>
                        <input
                            type="email"
                            className="fundkit-onboarding__input"
                            value={ value.email }
                            onChange={ ( e ) => set( { email: e.target.value } ) }
                            placeholder={ __( 'Where donors reply and receipts come from', 'fundraising-toolkit' ) }
                        />
                    </div>
                </div>
            </div>

            <div className="fundkit-onboarding__section">
                <div className="fundkit-onboarding__section-label">{ __( 'Country', 'fundraising-toolkit' ) }</div>
                <div className="fundkit-onboarding__country-row">
                    <CountrySelect
                        value={ value.country }
                        onChange={ onCountryChange }
                    />
                    { states && (
                        <div>
                            <label className="fundkit-onboarding__field-label">{ __( 'State', 'fundraising-toolkit' ) }</label>
                            <SearchableSelect
                                value={ value.state }
                                onChange={ ( v ) => set( { state: v } ) }
                                options={ states.map( ( s ) => ( { value: s, label: s } ) ) }
                                placeholder={ __( 'Select state', 'fundraising-toolkit' ) }
                            />
                        </div>
                    ) }
                </div>
            </div>


            <div className="fundkit-onboarding__section">
                <div className="fundkit-onboarding__section-label">{ __( 'Currency', 'fundraising-toolkit' ) }</div>
                <SearchableSelect
                    value={ currency.default_currency }
                    onChange={ ( code ) => onCurrencyChange( ( prev ) => ( { ...prev, default_currency: code } ) ) }
                    options={ currencyOptions }
                    placeholder={ __( 'Pick a currency', 'fundraising-toolkit' ) }
                />
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
        name:   __( 'Classic', 'fundraising-toolkit' ),
        desc:   __( 'Friendly, rounded, green. The safe choice.', 'fundraising-toolkit' ),
    },
    {
        id:     'bold',
        thumb:  'bold',
        name:   __( 'Bold', 'fundraising-toolkit' ),
        desc:   __( 'Deep navy, strong type, dramatic shadow.', 'fundraising-toolkit' ),
    },
    {
        id:     'quiet',
        thumb:  'quiet',
        name:   __( 'Quiet', 'fundraising-toolkit' ),
        desc:   __( 'Minimal lines, lots of white space.', 'fundraising-toolkit' ),
    },
    {
        id:     'theme',
        thumb:  'theme',
        name:   __( 'Use my theme', 'fundraising-toolkit' ),
        // desc filled at runtime from theme detection.
    },
];

// Sample blocks for the live preview (WYSIWYG - real runtime, real tokens).
function sampleBlocks( currency ) {
    const cur = ( currency || 'USD' ).toUpperCase();
    return [
        `<!-- wp:fundkit/donation-amount {"presets":[2500,5000,10000],"allowCustom":true,"currency":"${ cur }"} /-->`,
        '<!-- wp:fundkit/name {"requireFirst":true} /-->',
        '<!-- wp:fundkit/email {"required":true} /-->',
        '<!-- wp:fundkit/submit-button {"label":"Donate {amount}"} /-->',
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
            path:   '/fundkit/v1/admin/forms/preview',
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
        win.postMessage( { type: 'fundkit:apply-tokens', tokens: selectedPreset?.tokens || {} }, '*' );
    }, [ selectedPreset ] );

    useEffect( () => {
        const onMsg = ( e ) => {
            // Same reason: a srcdoc frame announces itself with origin "null".
            if ( e.source !== frameRef.current?.contentWindow ) return;
            if ( e?.data?.type === 'fundkit:preview-ready' ) {
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
            <h2 className="fundkit-onboarding__headline">{ __( 'Pick a starting look', 'fundraising-toolkit' ) }</h2>
            <p className="fundkit-onboarding__subtitle">
                { __( 'You can edit colors and typography anytime.', 'fundraising-toolkit' ) }
            </p>
            <div className="fundkit-onboarding__presets">
                { PRESET_CARDS.map( ( card ) => {
                    const isTheme   = card.id === 'theme';
                    const isDisabled = isTheme && ! themePreset;
                    const isSel     = value.preset_id === card.id;
                    const desc      = isTheme
                        ? ( themePreset
                            ? __( 'Inherits styles from your site theme.', 'fundraising-toolkit' )
                            : __( 'No theme palette detected.', 'fundraising-toolkit' ) )
                        : card.desc;
                    return (
                        <button
                            key={ card.id }
                            type="button"
                            className={ `fundkit-onboarding__preset${ isSel ? ' is-selected' : '' }${ isDisabled ? ' is-disabled' : '' }` }
                            onClick={ () => { if ( ! isDisabled ) onChange( { preset_id: card.id } ); } }
                            aria-pressed={ isSel }
                            disabled={ isDisabled }
                        >
                            { isSel && (
                                <span className="fundkit-onboarding__preset-check" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                                        <path d="M5 12.5l4 4 10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
                                    </svg>
                                </span>
                            ) }
                            <div className={ `fundkit-onboarding__preset-thumb fundkit-onboarding__preset-thumb--${ card.thumb }` }>
                                <span className="fundkit-onboarding__preset-btn">{ __( 'Donate', 'fundraising-toolkit' ) }</span>
                            </div>
                            <strong className="fundkit-onboarding__preset-name">{ card.name }</strong>
                            <span className="fundkit-onboarding__preset-desc">{ desc }</span>
                        </button>
                    );
                } ) }
            </div>

            <div className="fundkit-onboarding__preview">
                <div className="fundkit-onboarding__preview-label">{ __( 'Live preview', 'fundraising-toolkit' ) }</div>
                { loadState === 'error' ? (
                    <div className="fundkit-onboarding__preview-fallback">
                        <p>{ __( 'Preview unavailable. Your choice is still saved.', 'fundraising-toolkit' ) }</p>
                        <button
                            type="button"
                            className="fundkit-btn fundkit-btn--ghost"
                            onClick={ () => setReloadKey( ( k ) => k + 1 ) }
                        >
                            { __( 'Retry', 'fundraising-toolkit' ) }
                        </button>
                    </div>
                ) : (
                    <div className="fundkit-onboarding__preview-stage">
                        { loadState === 'loading' && (
                            <div className="fundkit-onboarding__preview-skeleton" aria-hidden="true" />
                        ) }
                        <iframe
                            ref={ frameRef }
                            className="fundkit-onboarding__preview-frame"
                            title={ __( 'Donation form preview', 'fundraising-toolkit' ) }
                            // allow-scripts without allow-same-origin: the preview
                            // needs to run the form's own JS, but a srcdoc frame
                            // otherwise inherits this admin origin, so anything
                            // scripted inside it would carry the admin's cookies
                            // and nonce. An opaque origin costs nothing here -
                            // both sides of the token push already identify each
                            // other by window reference rather than by origin.
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

// Step 5: Get-started checklist
function ChecklistStep( { finalized, settingsUrl, dashboardUrl, campaignsUrl } ) {
    const campaignId = finalized?.campaign_id || 0;
    const gatewayUrl = settingsUrl ? `${ settingsUrl }#gateways` : ( dashboardUrl || '#' );
    // Hands off to the campaigns screen with the create drawer already open,
    // so a campaign is built with the same form as every other one rather than
    // conjured from the wizard's answers.
    const newCampaignUrl = campaignsUrl
        ? `${ campaignsUrl }${ campaignsUrl.includes( '?' ) ? '&' : '?' }action=new`
        : ( dashboardUrl || '#' );

    return (
        <div>
            <h1 className="fundkit-onboarding__headline">{ __( "You're set up", 'fundraising-toolkit' ) }</h1>
            <p className="fundkit-onboarding__subtitle">
                { __( 'Your organization details are saved. Here is what is left before you can take a donation.', 'fundraising-toolkit' ) }
            </p>

            <ul className="fundkit-onboarding__checklist">
                <ChecklistItem
                    title={ __( 'Connect a payment gateway', 'fundraising-toolkit' ) }
                    description={ __( 'Stripe, PayPal, or a manual bank-transfer flow. You can change this any time.', 'fundraising-toolkit' ) }
                    href={ gatewayUrl }
                    cta={ __( 'Connect', 'fundraising-toolkit' ) }
                />
                { campaignId ? (
                    <ChecklistItem
                        title={ __( 'Build your first form', 'fundraising-toolkit' ) }
                        description={ __( 'Pick a layout, set amounts, brand it. Donors can give as soon as a gateway is live.', 'fundraising-toolkit' ) }
                        href={ finalized?.form_edit_url || finalized?.campaign_page || dashboardUrl || '#' }
                        cta={ __( 'Build', 'fundraising-toolkit' ) }
                    />
                ) : (
                    <ChecklistItem
                        title={ __( 'Create your first campaign', 'fundraising-toolkit' ) }
                        description={ __( 'A campaign holds your donation forms and totals. We can start one from your answers, or you can build your own later.', 'fundraising-toolkit' ) }
                        href={ newCampaignUrl }
                        cta={ __( 'Create', 'fundraising-toolkit' ) }
                    />
                ) }
            </ul>


            <p className="fundkit-onboarding__checklist-foot">
                <a className="fundkit-onboarding__checklist-skip" href={ dashboardUrl || '#' }>
                    { __( 'Skip for now', 'fundraising-toolkit' ) }
                </a>
            </p>
        </div>
    );
}

function ChecklistItem( { title, description, href, cta, onClick, busy } ) {
    return (
        <li className="fundkit-onboarding__checklist-item">
            <span className="fundkit-onboarding__checklist-bullet" aria-hidden="true" />
            <div className="fundkit-onboarding__checklist-body">
                <strong className="fundkit-onboarding__checklist-title">{ title }</strong>
                <span className="fundkit-onboarding__checklist-desc">{ description }</span>
            </div>
            { onClick
                ? (
                    <button
                        type="button"
                        className="fundkit-btn fundkit-btn--primary"
                        onClick={ onClick }
                        disabled={ busy }
                    >
                        { cta }
                    </button>
                )
                : <a className="fundkit-btn fundkit-btn--primary" href={ href }>{ cta }</a> }
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

/**
 * The currencies the operator enabled, with their base always among them.
 *
 * Same trap as the separators below: every settings read comes back with
 * [ 'USD' ] merged in, so a length test can never see "unset". A lone USD is
 * what ships rather than a choice, and keeping it beside a EUR base makes a
 * single-currency charity look multi-currency to the rate fetcher, which then
 * starts a daily third-party call it has no use for, and leaves USD
 * unremovable on Settings > Currency.
 */
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

