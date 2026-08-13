
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { CURRENCIES } from '../_shared/currency';
import CountrySelect from '../_shared/components/CountrySelect';
import DonoMark from '../_shared/components/DonoMark';
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
function deriveNumberFormat( country ) {
    const us = [ 'US', 'CA', 'GB', 'AU', 'NZ', 'IN', 'IE', 'ZA', 'SG', 'HK', 'JP' ];
    return us.includes( ( country || '' ).toUpperCase() ) ? 'us' : 'eu';
}



const USER_TYPES = [
    {
        id:   'nonprofit',
        icon: 'building',
        name: __( 'Nonprofit or charity', 'dono-fundraising-platform' ),
        desc: __( 'Registered organization collecting tax-deductible donations.', 'dono-fundraising-platform' ),
    },
    {
        id:   'community',
        icon: 'users',
        name: __( 'Community or faith group', 'dono-fundraising-platform' ),
        desc: __( 'Church, school, club, mutual-aid group.', 'dono-fundraising-platform' ),
    },
    {
        id:   'individual',
        icon: 'heart',
        name: __( 'Individual fundraiser', 'dono-fundraising-platform' ),
        desc: __( 'Personal cause, crowdfund, or memorial fund.', 'dono-fundraising-platform' ),
    },
    {
        id:   'exploring',
        icon: 'target',
        name: __( 'Just exploring', 'dono-fundraising-platform' ),
        desc: __( 'Trying Dono out. Starts in test mode, so nothing takes real money until you switch it off.', 'dono-fundraising-platform' ),
    },
];

export default function Onboarding() {
    const wp = window.dono?.wp || {};
    const presets   = Array.isArray( window.dono?.styling?.presets ) ? window.dono.styling.presets : [];
    const defaultId = String( window.dono?.styling?.default_id || 'classic' );

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
        const h = frameRef.current?.querySelector( '.dono-onboarding__headline' );
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
        apiFetch( { path: '/dono/v1/admin/settings/org-profile' } )
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
        apiFetch( { path: '/dono/v1/admin/settings/currency-locale' } )
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
        // latest org-brand option, not the page-load snapshot of window.dono.
        apiFetch( { path: '/dono/v1/admin/settings/org-brand' } )
            .then( ( d ) => {
                if ( d?.default_id ) setBrand( { preset_id: String( d.default_id ) } );
            } )
            .catch( () => {} );
    }, [] );

    const persist = async ( group, payload ) => {
        await apiFetch( {
            path:   `/dono/v1/admin/settings/${ group }`,
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
                    throw new Error( __( 'Pick who is fundraising to continue.', 'dono-fundraising-platform' ) );
                }
                await persist( 'org-profile', { user_type: who.user_type } );
            } else if ( step === 1 ) {
                if ( ! org.country ) {
                    throw new Error( __( 'Pick a country to continue.', 'dono-fundraising-platform' ) );
                }
                // A country that subdivides still needs its state: it is part of
                // where the organization is, and it is one click. The postal
                // address is not asked for here -- it is optional in Settings,
                // and the receipt renderer omits the block when it is unset.
                if ( STATES_BY_COUNTRY[ org.country ] && ! ( org.state || '' ).trim() ) {
                    throw new Error( __( 'Pick a state or province to continue.', 'dono-fundraising-platform' ) );
                }
                await persist( 'org-profile', {
                    name:           org.name,
                    email:          org.email,
                    country:        org.country,
                    state:          org.state,
                } );

                const fmt = numberFormatPair( deriveNumberFormat( org.country ) );
                await persist( 'currency-locale', {
                    default_currency:     currency.default_currency,
                    supported_currencies: currency.supported_currencies?.length
                        ? currency.supported_currencies
                        : [ currency.default_currency ],
                    locale:               currency.locale || '',
                    format: {
                        decimal_places:  Number.isFinite( currency.format?.decimal_places )
                            ? currency.format.decimal_places
                            : 2,
                        // Keep separators the user already saved; only derive
                        // from country on a first run with no saved format.
                        decimal_sep:     currency.format?.decimal_sep || fmt.decimal,
                        thousand_sep:    currency.format?.thousand_sep || fmt.thousand,
                        symbol_position: currency.format?.symbol_position || 'before',
                    },
                } );
            } else if ( step === 2 ) {
                await persist( 'org-brand', { default_id: brand.preset_id } );
                const r = await apiFetch( {
                    path:   '/dono/v1/admin/onboarding/finalize',
                    method: 'POST',
                    data:   {
                        campaign_title: org.name
                            ? `${ org.name } - ${ __( 'General donations', 'dono-fundraising-platform' ) }`
                            : __( 'General donations', 'dono-fundraising-platform' ),
                        user_type:      who.user_type,
                    },
                } );
                if ( ! r?.ok ) throw new Error( __( 'Could not finalize onboarding.', 'dono-fundraising-platform' ) );
                setFinalized( r );
            }
            setStep( ( s ) => Math.min( TOTAL - 1, s + 1 ) );
        } catch ( err ) {
            setError( err?.message || __( 'Could not save. Please try again.', 'dono-fundraising-platform' ) );
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
            await apiFetch( { path: '/dono/v1/admin/onboarding/dismiss', method: 'POST' } );
        } catch ( e ) {
            // A failed dismiss leaves onboarding 'pending', so admin_init would
            // bounce us straight back here; surface the error instead of looping.
            setError( __( 'Could not skip setup. Please try again.', 'dono-fundraising-platform' ) );
            return;
        }
        window.location.assign( wp.settings_url || wp.dashboard_url || '' );
    };

    const isChecklist = step === TOTAL - 1;

    return (
        <div className="dono-onboarding">
            <div className={ `dono-onboarding__top${ step === 2 ? ' is-wide' : '' }` }>
                <span className="dono-onboarding__brand">
                    <DonoMark size={ 28 } />
                    <span className="dono-onboarding__brand-name">Dono</span>
                </span>
                { ! isChecklist && (
                    <button type="button" className="dono-onboarding__skip" onClick={ skip }>
                        { __( 'Skip for now', 'dono-fundraising-platform' ) }
                    </button>
                ) }
            </div>

            <section ref={ frameRef } className={ `dono-onboarding__frame${ step === 2 ? ' is-wide' : '' }` }>
                <div className="dono-onboarding__meta">
                    <span className="dono-onboarding__caption">
                        { sprintf( /* translators: %1$d: current step number. %2$d: total number of steps. */ __( 'Step %1$d of %2$d', 'dono-fundraising-platform' ), step + 1, TOTAL ) }
                    </span>
                    <span className="dono-onboarding__dots" aria-hidden="true">
                        { Array.from( { length: TOTAL } ).map( ( _, i ) => (
                            <span
                                key={ i }
                                className={ `dono-onboarding__dot${ i < step ? ' is-done' : '' }${ i === step ? ' is-current' : '' }` }
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

                { error && <div className="dono-onboarding__error" role="alert">{ error }</div> }

                { ! isChecklist && (
                    <footer
                        className={ `dono-onboarding__nav${ step === 0 ? ' dono-onboarding__nav--centered' : '' }` }
                    >
                        { step > 0 && (
                            <button
                                type="button"
                                className="dono-btn dono-btn--ghost"
                                onClick={ back }
                                disabled={ busy }
                            >
                                ← { __( 'Back', 'dono-fundraising-platform' ) }
                            </button>
                        ) }

                        <button
                            type="button"
                            className="dono-btn dono-btn--primary dono-btn--lg"
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
    if ( busy ) return __( 'Saving…', 'dono-fundraising-platform' );
    if ( step === 0 ) return __( 'Get started', 'dono-fundraising-platform' );
    if ( step === 2 ) return __( 'Finish setup', 'dono-fundraising-platform' ) + ' →';
    return __( 'Next', 'dono-fundraising-platform' ) + ' →';
}

// Step 1: who is fundraising
function FundraiserTypeStep( { value, onChange } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    return (
        <div>
            <h2 className="dono-onboarding__headline">
                { __( "Who's fundraising?", 'dono-fundraising-platform' ) }
            </h2>
            <p className="dono-onboarding__subtitle">
                { __( 'Pick the one that fits best.', 'dono-fundraising-platform' ) }
            </p>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__usertype">
                    { USER_TYPES.map( ( t ) => {
                        const isSel = value.user_type === t.id;
                        return (
                            <button
                                key={ t.id }
                                type="button"
                                className={ `dono-onboarding__usertype-tile${ isSel ? ' is-selected' : '' }` }
                                onClick={ () => set( { user_type: t.id } ) }
                                aria-pressed={ isSel }
                            >
                                <span className="dono-onboarding__usertype-check" aria-hidden="true">
                                    <LocalIcon name="check" size={ 12 } strokeWidth={ 3 } />
                                </span>
                                <span className="dono-onboarding__usertype-glyph" aria-hidden="true">
                                    <LocalIcon name={ t.icon } size={ 22 } strokeWidth={ 1.6 } />
                                </span>
                                <strong className="dono-onboarding__usertype-name">{ t.name }</strong>
                                <span className="dono-onboarding__usertype-desc">{ t.desc }</span>
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
            <h2 className="dono-onboarding__headline">{ __( 'Where are you based?', 'dono-fundraising-platform' ) }</h2>
            <p className="dono-onboarding__subtitle">
                { __( "We use this for receipts and your default currency.", 'dono-fundraising-platform' ) }
            </p>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">
                    { isIndividual ? __( 'About you', 'dono-fundraising-platform' ) : __( 'Organization', 'dono-fundraising-platform' ) }
                </div>
                <div className="dono-onboarding__address">
                    <div className="span-2">
                        <label className="dono-onboarding__field-label">
                            { isIndividual ? __( 'Your name', 'dono-fundraising-platform' ) : __( 'Organization name', 'dono-fundraising-platform' ) }
                        </label>
                        <input
                            type="text"
                            className="dono-onboarding__input"
                            value={ value.name }
                            onChange={ ( e ) => set( { name: e.target.value } ) }
                            placeholder={ __( 'Shown on receipts and your campaign', 'dono-fundraising-platform' ) }
                        />
                    </div>
                    <div className="span-2">
                        <label className="dono-onboarding__field-label">{ __( 'Contact email', 'dono-fundraising-platform' ) }</label>
                        <input
                            type="email"
                            className="dono-onboarding__input"
                            value={ value.email }
                            onChange={ ( e ) => set( { email: e.target.value } ) }
                            placeholder={ __( 'Where donors reply and receipts come from', 'dono-fundraising-platform' ) }
                        />
                    </div>
                </div>
            </div>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Country', 'dono-fundraising-platform' ) }</div>
                <div className="dono-onboarding__country-row">
                    <CountrySelect
                        value={ value.country }
                        onChange={ onCountryChange }
                    />
                    { states && (
                        <div>
                            <label className="dono-onboarding__field-label">{ __( 'State', 'dono-fundraising-platform' ) }</label>
                            <SearchableSelect
                                value={ value.state }
                                onChange={ ( v ) => set( { state: v } ) }
                                options={ states.map( ( s ) => ( { value: s, label: s } ) ) }
                                placeholder={ __( 'Select state', 'dono-fundraising-platform' ) }
                            />
                        </div>
                    ) }
                </div>
            </div>


            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Currency', 'dono-fundraising-platform' ) }</div>
                <SearchableSelect
                    value={ currency.default_currency }
                    onChange={ ( code ) => onCurrencyChange( ( prev ) => ( { ...prev, default_currency: code } ) ) }
                    options={ currencyOptions }
                    placeholder={ __( 'Pick a currency', 'dono-fundraising-platform' ) }
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
        name:   __( 'Classic', 'dono-fundraising-platform' ),
        desc:   __( 'Friendly, rounded, green. The safe choice.', 'dono-fundraising-platform' ),
    },
    {
        id:     'bold',
        thumb:  'bold',
        name:   __( 'Bold', 'dono-fundraising-platform' ),
        desc:   __( 'Deep navy, strong type, dramatic shadow.', 'dono-fundraising-platform' ),
    },
    {
        id:     'quiet',
        thumb:  'quiet',
        name:   __( 'Quiet', 'dono-fundraising-platform' ),
        desc:   __( 'Minimal lines, lots of white space.', 'dono-fundraising-platform' ),
    },
    {
        id:     'theme',
        thumb:  'theme',
        name:   __( 'Use my theme', 'dono-fundraising-platform' ),
        // desc filled at runtime from theme detection.
    },
];

// Sample blocks for the live preview (WYSIWYG - real runtime, real tokens).
function sampleBlocks( currency ) {
    const cur = ( currency || 'USD' ).toUpperCase();
    return [
        `<!-- wp:dono/donation-amount {"presets":[2500,5000,10000],"allowCustom":true,"currency":"${ cur }"} /-->`,
        '<!-- wp:dono/name {"requireFirst":true} /-->',
        '<!-- wp:dono/email {"required":true} /-->',
        '<!-- wp:dono/submit-button {"label":"Donate {amount}"} /-->',
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
            path:   '/dono/v1/admin/forms/preview',
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
        win.postMessage( { type: 'dono:apply-tokens', tokens: selectedPreset?.tokens || {} }, '*' );
    }, [ selectedPreset ] );

    useEffect( () => {
        const onMsg = ( e ) => {
            // Same reason: a srcdoc frame announces itself with origin "null".
            if ( e.source !== frameRef.current?.contentWindow ) return;
            if ( e?.data?.type === 'dono:preview-ready' ) {
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
            <h2 className="dono-onboarding__headline">{ __( 'Pick a starting look', 'dono-fundraising-platform' ) }</h2>
            <p className="dono-onboarding__subtitle">
                { __( 'You can edit colors and typography anytime.', 'dono-fundraising-platform' ) }
            </p>
            <div className="dono-onboarding__presets">
                { PRESET_CARDS.map( ( card ) => {
                    const isTheme   = card.id === 'theme';
                    const isDisabled = isTheme && ! themePreset;
                    const isSel     = value.preset_id === card.id;
                    const desc      = isTheme
                        ? ( themePreset
                            ? __( 'Inherits styles from your site theme.', 'dono-fundraising-platform' )
                            : __( 'No theme palette detected.', 'dono-fundraising-platform' ) )
                        : card.desc;
                    return (
                        <button
                            key={ card.id }
                            type="button"
                            className={ `dono-onboarding__preset${ isSel ? ' is-selected' : '' }${ isDisabled ? ' is-disabled' : '' }` }
                            onClick={ () => { if ( ! isDisabled ) onChange( { preset_id: card.id } ); } }
                            aria-pressed={ isSel }
                            disabled={ isDisabled }
                        >
                            { isSel && (
                                <span className="dono-onboarding__preset-check" aria-hidden="true">
                                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none">
                                        <path d="M5 12.5l4 4 10-10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" strokeLinejoin="round" />
                                    </svg>
                                </span>
                            ) }
                            <div className={ `dono-onboarding__preset-thumb dono-onboarding__preset-thumb--${ card.thumb }` }>
                                <span className="dono-onboarding__preset-btn">{ __( 'Donate', 'dono-fundraising-platform' ) }</span>
                            </div>
                            <strong className="dono-onboarding__preset-name">{ card.name }</strong>
                            <span className="dono-onboarding__preset-desc">{ desc }</span>
                        </button>
                    );
                } ) }
            </div>

            <div className="dono-onboarding__preview">
                <div className="dono-onboarding__preview-label">{ __( 'Live preview', 'dono-fundraising-platform' ) }</div>
                { loadState === 'error' ? (
                    <div className="dono-onboarding__preview-fallback">
                        <p>{ __( 'Preview unavailable. Your choice is still saved.', 'dono-fundraising-platform' ) }</p>
                        <button
                            type="button"
                            className="dono-btn dono-btn--ghost"
                            onClick={ () => setReloadKey( ( k ) => k + 1 ) }
                        >
                            { __( 'Retry', 'dono-fundraising-platform' ) }
                        </button>
                    </div>
                ) : (
                    <div className="dono-onboarding__preview-stage">
                        { loadState === 'loading' && (
                            <div className="dono-onboarding__preview-skeleton" aria-hidden="true" />
                        ) }
                        <iframe
                            ref={ frameRef }
                            className="dono-onboarding__preview-frame"
                            title={ __( 'Donation form preview', 'dono-fundraising-platform' ) }
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
            <h1 className="dono-onboarding__headline">{ __( "You're set up", 'dono-fundraising-platform' ) }</h1>
            <p className="dono-onboarding__subtitle">
                { __( 'Your organization details are saved. Here is what is left before you can take a donation.', 'dono-fundraising-platform' ) }
            </p>

            <ul className="dono-onboarding__checklist">
                <ChecklistItem
                    title={ __( 'Connect a payment gateway', 'dono-fundraising-platform' ) }
                    description={ __( 'Stripe, PayPal, or a manual bank-transfer flow. You can change this any time.', 'dono-fundraising-platform' ) }
                    href={ gatewayUrl }
                    cta={ __( 'Connect', 'dono-fundraising-platform' ) }
                />
                { campaignId ? (
                    <ChecklistItem
                        title={ __( 'Build your first form', 'dono-fundraising-platform' ) }
                        description={ __( 'Pick a layout, set amounts, brand it. Donors can give as soon as a gateway is live.', 'dono-fundraising-platform' ) }
                        href={ finalized?.form_edit_url || finalized?.campaign_page || dashboardUrl || '#' }
                        cta={ __( 'Build', 'dono-fundraising-platform' ) }
                    />
                ) : (
                    <ChecklistItem
                        title={ __( 'Create your first campaign', 'dono-fundraising-platform' ) }
                        description={ __( 'A campaign holds your donation forms and totals. We can start one from your answers, or you can build your own later.', 'dono-fundraising-platform' ) }
                        href={ newCampaignUrl }
                        cta={ __( 'Create', 'dono-fundraising-platform' ) }
                    />
                ) }
            </ul>


            <p className="dono-onboarding__checklist-foot">
                <a className="dono-onboarding__checklist-skip" href={ dashboardUrl || '#' }>
                    { __( 'Skip for now', 'dono-fundraising-platform' ) }
                </a>
            </p>
        </div>
    );
}

function ChecklistItem( { title, description, href, cta, onClick, busy } ) {
    return (
        <li className="dono-onboarding__checklist-item">
            <span className="dono-onboarding__checklist-bullet" aria-hidden="true" />
            <div className="dono-onboarding__checklist-body">
                <strong className="dono-onboarding__checklist-title">{ title }</strong>
                <span className="dono-onboarding__checklist-desc">{ description }</span>
            </div>
            { onClick
                ? (
                    <button
                        type="button"
                        className="dono-btn dono-btn--primary"
                        onClick={ onClick }
                        disabled={ busy }
                    >
                        { cta }
                    </button>
                )
                : <a className="dono-btn dono-btn--primary" href={ href }>{ cta }</a> }
        </li>
    );
}

function numberFormatPair( fmt ) {
    return fmt === 'eu'
        ? { decimal: ',', thousand: '.' }
        : { decimal: '.', thousand: ',' };
}

