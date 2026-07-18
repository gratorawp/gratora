
import { useState, useEffect, useMemo, useRef, useCallback } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import { CURRENCIES, currencySymbol, groupDigits } from '../_shared/currency';
import AmountInput from '../_shared/components/AmountInput';
import CountrySelect from '../_shared/components/CountrySelect';
import DonoMark from '../_shared/components/DonoMark';
import LocalIcon from '../_shared/components/Icon';
import SearchableSelect from '../_shared/components/SearchableSelect';

const TOTAL = 5;

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

// Suggested goal presets by cause; plain integer amount in chosen currency.
const GOAL_PRESETS_DEFAULT = [ 5000, 25000, 50000, 100000 ];
const GOAL_PRESETS_BY_CAUSE = {
    memorial:    [ 2000, 5000, 10000, 25000 ],
    personal:    [ 1000, 3000, 5000, 10000 ],
    sports:      [ 2500, 5000, 15000, 50000 ],
    animals:     [ 5000, 15000, 50000, 100000 ],
    arts:        [ 5000, 15000, 50000, 100000 ],
    community:   [ 5000, 15000, 50000, 100000 ],
    education:   [ 10000, 25000, 100000, 250000 ],
    health:      [ 10000, 25000, 100000, 250000 ],
    environment: [ 10000, 25000, 100000, 250000 ],
    humanitarian:[ 10000, 25000, 100000, 250000 ],
    human_rights:[ 10000, 25000, 100000, 250000 ],
    faith:       [ 5000, 25000, 50000, 100000 ],
    other:       GOAL_PRESETS_DEFAULT,
};

function goalPresetsFor( cause ) {
    return GOAL_PRESETS_BY_CAUSE[ cause ] || GOAL_PRESETS_DEFAULT;
}


const USER_TYPES = [
    {
        id:   'nonprofit',
        icon: 'building',
        name: __( 'Nonprofit or charity', 'dono' ),
        desc: __( 'Registered organisation collecting tax-deductible donations.', 'dono' ),
    },
    {
        id:   'community',
        icon: 'users',
        name: __( 'Community or faith group', 'dono' ),
        desc: __( 'Church, school, club, mutual-aid group.', 'dono' ),
    },
    {
        id:   'individual',
        icon: 'heart',
        name: __( 'Individual fundraiser', 'dono' ),
        desc: __( 'Personal cause, crowdfund, or memorial fund.', 'dono' ),
    },
    {
        id:   'exploring',
        icon: 'target',
        name: __( 'Just exploring', 'dono' ),
        desc: __( 'Trying Dono out before committing.', 'dono' ),
    },
];

const CAUSE_OPTIONS = [
    { value: 'education',     label: __( 'Education and schools', 'dono' ) },
    { value: 'health',        label: __( 'Health and medical', 'dono' ) },
    { value: 'animals',       label: __( 'Animals and wildlife', 'dono' ) },
    { value: 'environment',   label: __( 'Environment and climate', 'dono' ) },
    { value: 'arts',          label: __( 'Arts and culture', 'dono' ) },
    { value: 'faith',         label: __( 'Faith and religious community', 'dono' ) },
    { value: 'community',     label: __( 'Community and local causes', 'dono' ) },
    { value: 'humanitarian',  label: __( 'Humanitarian and disaster relief', 'dono' ) },
    { value: 'human_rights',  label: __( 'Human rights and advocacy', 'dono' ) },
    { value: 'sports',        label: __( 'Sports, youth, and recreation', 'dono' ) },
    { value: 'memorial',      label: __( 'Memorial or in-memoriam', 'dono' ) },
    { value: 'personal',      label: __( 'Personal or family need', 'dono' ) },
    { value: 'other',         label: __( 'Something else', 'dono' ) },
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
        address_line1:  '',
        address_line2:  '',
        city:           '',
        postal_code:    '',
        state:          '',
    } );
    const [ currency, setCurrency ] = useState( { default_currency: 'USD' } );
    const [ brand, setBrand ] = useState( { preset_id: defaultId } );
    const [ cause, setCause ] = useState( {
        user_type:          '',
        cause:              '',
        telemetry_enabled:  false,
    } );
    const [ goal, setGoal ] = useState( {
        mode:   'target',   // 'target' | 'ongoing'
        amount: 25000,
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
                    address_line1: d.address_line1 || prev.address_line1,
                    address_line2: d.address_line2 || prev.address_line2,
                    city:          d.city          || prev.city,
                    postal_code:   d.postal_code   || prev.postal_code,
                    state:         d.state         || prev.state,
                } ) );
                setCause( ( prev ) => ( {
                    ...prev,
                    user_type: d.user_type || prev.user_type,
                    cause:     d.cause     || prev.cause,
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
        apiFetch( { path: '/dono/v1/admin/settings/telemetry' } )
            .then( ( d ) => {
                if ( ! d ) return;
                setCause( ( prev ) => ( { ...prev, telemetry_enabled: !! d.enabled } ) );
            } )
            .catch( () => {} );
        // Reload the saved brand preset id so a resumed wizard reflects the
        // latest org-brand option, not the page-load snapshot of window.dono.
        apiFetch( { path: '/dono/v1/admin/settings/org-brand' } )
            .then( ( d ) => {
                if ( d?.default_id ) setBrand( { preset_id: String( d.default_id ) } );
            } )
            .catch( () => {} );
        // Onboarding goal isn't persisted to its own option (no schema for it)
        // but we stash a draft on the onboarding-status option so a partial
        // resume picks up the previously-typed value instead of resetting.
        apiFetch( { path: '/dono/v1/admin/onboarding/draft' } )
            .then( ( d ) => {
                if ( d?.goal && typeof d.goal === 'object' ) {
                    setGoal( ( prev ) => ( {
                        mode:   d.goal.mode === 'ongoing' ? 'ongoing' : 'target',
                        amount: Number( d.goal.amount ) || prev.amount,
                    } ) );
                }
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
                if ( ! cause.user_type ) {
                    throw new Error( __( 'Pick who is fundraising to continue.', 'dono' ) );
                }
                if ( ! cause.cause ) {
                    throw new Error( __( 'Pick a cause area to continue.', 'dono' ) );
                }
                await persist( 'org-profile', {
                    user_type: cause.user_type,
                    cause:     cause.cause,
                } );
                await persist( 'telemetry', {
                    enabled:     cause.telemetry_enabled,
                    opted_in_at: cause.telemetry_enabled ? Math.floor( Date.now() / 1000 ) : null,
                } );
            } else if ( step === 1 ) {
                if ( ! org.country ) {
                    throw new Error( __( 'Pick a country to continue.', 'dono' ) );
                }
                if ( cause.user_type !== 'exploring' ) {
                    if ( ! org.address_line1.trim() || ! org.city.trim() || ! org.postal_code.trim() ) {
                        throw new Error( __( 'Add your address to continue.', 'dono' ) );
                    }
                    // Countries that subdivide require the state/province too,
                    // otherwise receipts read as half-an-address.
                    if ( STATES_BY_COUNTRY[ org.country ] && ! ( org.state || '' ).trim() ) {
                        throw new Error( __( 'Pick a state or province to continue.', 'dono' ) );
                    }
                }
                // Flat address_lines for receipt renderers; structured fields stored alongside.
                const addressLines = [
                    org.address_line1,
                    org.address_line2,
                    [ org.city, org.state, org.postal_code ].filter( Boolean ).join( ' ' ).trim(),
                ].filter( ( l ) => !! l && l.trim() !== '' );

                await persist( 'org-profile', {
                    name:           org.name,
                    email:          org.email,
                    country:        org.country,
                    address_line1:  org.address_line1,
                    address_line2:  org.address_line2,
                    city:           org.city,
                    postal_code:    org.postal_code,
                    state:          org.state,
                    address_lines:  addressLines,
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
                if ( goal.mode === 'target' && ( ! goal.amount || goal.amount < 1 ) ) {
                    throw new Error( __( 'Pick a goal amount to continue, or switch to ongoing.', 'dono' ) );
                }
                // Stash the goal in the onboarding-draft option so a partial
                // resume keeps the selection. Cleared on finalize.
                await apiFetch( {
                    path:   '/dono/v1/admin/onboarding/draft',
                    method: 'PUT',
                    data:   { goal: { mode: goal.mode, amount: goal.amount } },
                } ).catch( () => {} );
            } else if ( step === 3 ) {
                await persist( 'org-brand', { default_id: brand.preset_id } );
                const r = await apiFetch( {
                    path:   '/dono/v1/admin/onboarding/finalize',
                    method: 'POST',
                    data:   {
                        campaign_title: org.name
                            ? `${ org.name } - ${ __( 'General donations', 'dono' ) }`
                            : __( 'General donations', 'dono' ),
                        currency:       currency.default_currency,
                        goal_mode:      goal.mode,
                        goal_amount:    goal.mode === 'target' ? goal.amount : 0,
                        user_type:      cause.user_type,
                    },
                } );
                if ( ! r?.ok ) throw new Error( __( 'Could not finalize onboarding.', 'dono' ) );
                setFinalized( r );
            }
            setStep( ( s ) => Math.min( TOTAL - 1, s + 1 ) );
        } catch ( err ) {
            setError( err?.message || __( 'Could not save. Please try again.', 'dono' ) );
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
            setError( __( 'Could not skip setup. Please try again.', 'dono' ) );
            return;
        }
        window.location.assign( wp.settings_url || wp.dashboard_url || '' );
    };

    const isChecklist = step === TOTAL - 1;

    return (
        <div className="dono-onboarding">
            <div className={ `dono-onboarding__top${ step === 3 ? ' is-wide' : '' }` }>
                <span className="dono-onboarding__brand">
                    <DonoMark size={ 28 } />
                    <span className="dono-onboarding__brand-name">Dono</span>
                </span>
                { ! isChecklist && (
                    <button type="button" className="dono-onboarding__skip" onClick={ skip }>
                        { __( 'Skip for now', 'dono' ) }
                    </button>
                ) }
            </div>

            <section ref={ frameRef } className={ `dono-onboarding__frame${ step === 3 ? ' is-wide' : '' }` }>
                <div className="dono-onboarding__meta">
                    <span className="dono-onboarding__caption">
                        { sprintf( __( 'Step %1$d of %2$d', 'dono' ), step + 1, TOTAL ) }
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

                { step === 0 && <CauseStep value={ cause } onChange={ setCause } /> }
                { step === 1 && <LocationStep value={ org } onChange={ setOrg } currency={ currency } onCurrencyChange={ setCurrency } userType={ cause.user_type } /> }
                { step === 2 && <GoalStep value={ goal } onChange={ setGoal } cause={ cause.cause } currency={ currency.default_currency } format={ deriveNumberFormat( org.country ) } /> }
                { step === 3 && <BrandStep value={ brand } onChange={ setBrand } presets={ presets } currency={ currency.default_currency } /> }
                { step === 4 && (
                    <ChecklistStep
                        finalized={ finalized }
                        settingsUrl={ wp.settings_url }
                        dashboardUrl={ wp.dashboard_url }
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
                                ← { __( 'Back', 'dono' ) }
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
    if ( busy ) return __( 'Saving…', 'dono' );
    if ( step === 0 ) return __( 'Get started', 'dono' );
    if ( step === 3 ) return __( 'Finish setup', 'dono' ) + ' →';
    return __( 'Next', 'dono' ) + ' →';
}

// Step 1: About your cause
function CauseStep( { value, onChange } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    return (
        <div>
            <h2 className="dono-onboarding__headline">
                { __( 'What are you fundraising for?', 'dono' ) }
            </h2>
            <p className="dono-onboarding__subtitle">
                { __( 'A bit of context so Dono can suggest sensible defaults.', 'dono' ) }
            </p>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( "Who's fundraising?", 'dono' ) }</div>
                <div className="dono-onboarding__section-help">
                    { __( 'Pick the one that fits best. You can change this later.', 'dono' ) }
                </div>
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

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Cause area', 'dono' ) }</div>
                <div className="dono-onboarding__section-help">
                    { __( 'Shapes suggested amounts and default copy.', 'dono' ) }
                </div>
                <SearchableSelect
                    value={ value.cause }
                    onChange={ ( v ) => set( { cause: v } ) }
                    options={ CAUSE_OPTIONS }
                    placeholder={ __( 'Pick a cause area', 'dono' ) }
                />
            </div>

            <div className="dono-onboarding__telemetry">
                <label className="dono-onboarding__telemetry-row">
                    <input
                        type="checkbox"
                        className="dono-onboarding__telemetry-input"
                        checked={ !! value.telemetry_enabled }
                        onChange={ ( e ) => set( { telemetry_enabled: e.target.checked } ) }
                    />
                    <span className="dono-onboarding__telemetry-body">
                        <strong>{ __( 'Help improve Dono', 'dono' ) }</strong>
                        <span>
                            { __( 'Send anonymous usage events so we know which features get used. No donor data, no personal information, ever. You can change this any time in Settings.', 'dono' ) }
                        </span>
                    </span>
                </label>
            </div>
        </div>
    );
}

// Step 3: Location & money
function LocationStep( { value, onChange, currency, onCurrencyChange, userType } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    const isExploring = userType === 'exploring';

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
            <h2 className="dono-onboarding__headline">{ __( 'Where are you based?', 'dono' ) }</h2>
            <p className="dono-onboarding__subtitle">
                { __( "We use this for receipts and your default currency.", 'dono' ) }
            </p>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Organisation', 'dono' ) }</div>
                <div className="dono-onboarding__address">
                    <div className="span-2">
                        <label className="dono-onboarding__field-label">{ __( 'Organisation name', 'dono' ) }</label>
                        <input
                            type="text"
                            className="dono-onboarding__input"
                            value={ value.name }
                            onChange={ ( e ) => set( { name: e.target.value } ) }
                            placeholder={ __( 'Shown on receipts and your campaign', 'dono' ) }
                        />
                    </div>
                    <div className="span-2">
                        <label className="dono-onboarding__field-label">{ __( 'Contact email', 'dono' ) }</label>
                        <input
                            type="email"
                            className="dono-onboarding__input"
                            value={ value.email }
                            onChange={ ( e ) => set( { email: e.target.value } ) }
                            placeholder={ __( 'Where donors reply and receipts come from', 'dono' ) }
                        />
                    </div>
                </div>
            </div>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Country', 'dono' ) }</div>
                <div className="dono-onboarding__country-row">
                    <CountrySelect
                        value={ value.country }
                        onChange={ onCountryChange }
                    />
                    { states && (
                        <div>
                            <label className="dono-onboarding__field-label">{ __( 'State', 'dono' ) }</label>
                            <SearchableSelect
                                value={ value.state }
                                onChange={ ( v ) => set( { state: v } ) }
                                options={ states.map( ( s ) => ( { value: s, label: s } ) ) }
                                placeholder={ __( 'Select state', 'dono' ) }
                            />
                        </div>
                    ) }
                </div>
            </div>

            { ! isExploring && (
                <div className="dono-onboarding__section">
                    <div className="dono-onboarding__section-label">{ __( 'Address', 'dono' ) }</div>
                    <div className="dono-onboarding__address">
                        <div className="span-2">
                            <label className="dono-onboarding__field-label">{ __( 'Street address', 'dono' ) }</label>
                            <input
                                type="text"
                                className="dono-onboarding__input"
                                value={ value.address_line1 }
                                onChange={ ( e ) => set( { address_line1: e.target.value } ) }
                                placeholder={ __( 'Enter your street address', 'dono' ) }
                            />
                        </div>
                        <div className="span-2">
                            <label className="dono-onboarding__field-label">
                                { __( 'Apartment, suite, etc.', 'dono' ) }
                                <span className="dono-onboarding__field-optional"> ({ __( 'optional', 'dono' ) })</span>
                            </label>
                            <input
                                type="text"
                                className="dono-onboarding__input"
                                value={ value.address_line2 }
                                onChange={ ( e ) => set( { address_line2: e.target.value } ) }
                                placeholder={ __( 'Suite 200', 'dono' ) }
                            />
                        </div>
                        <div>
                            <label className="dono-onboarding__field-label">{ __( 'City', 'dono' ) }</label>
                            <input
                                type="text"
                                className="dono-onboarding__input"
                                value={ value.city }
                                onChange={ ( e ) => set( { city: e.target.value } ) }
                            />
                        </div>
                        <div>
                            <label className="dono-onboarding__field-label">{ __( 'Postal code', 'dono' ) }</label>
                            <input
                                type="text"
                                className="dono-onboarding__input"
                                value={ value.postal_code }
                                onChange={ ( e ) => set( { postal_code: e.target.value } ) }
                            />
                        </div>
                    </div>
                </div>
            ) }

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Currency', 'dono' ) }</div>
                <SearchableSelect
                    value={ currency.default_currency }
                    onChange={ ( code ) => onCurrencyChange( ( prev ) => ( { ...prev, default_currency: code } ) ) }
                    options={ currencyOptions }
                    placeholder={ __( 'Pick a currency', 'dono' ) }
                />
            </div>
        </div>
    );
}

// Step 4: Goal
function GoalStep( { value, onChange, cause, currency, format = 'us' } ) {
    const set = ( patch ) => onChange( { ...value, ...patch } );
    const presets = goalPresetsFor( cause );
    const symbol  = currencySymbol( currency || 'USD' );
    const isTarget = value.mode === 'target';

    return (
        <div>
            <h2 className="dono-onboarding__headline">{ __( 'Set your fundraising goal', 'dono' ) }</h2>
            <p className="dono-onboarding__subtitle">
                { __( 'Drives the progress bar on your campaign page. You can change it anytime.', 'dono' ) }
            </p>

            <div className="dono-onboarding__section">
                <div className="dono-onboarding__section-label">{ __( 'Campaign type', 'dono' ) }</div>
                <div className="dono-onboarding__segmented">
                    <button
                        type="button"
                        className={ isTarget ? 'is-on' : '' }
                        onClick={ () => set( { mode: 'target' } ) }
                    >
                        { __( 'Single target', 'dono' ) }
                    </button>
                    <button
                        type="button"
                        className={ ! isTarget ? 'is-on' : '' }
                        onClick={ () => set( { mode: 'ongoing' } ) }
                    >
                        { __( 'Ongoing collection', 'dono' ) }
                    </button>
                </div>
            </div>

            { isTarget && (
                <>
                    <div className="dono-onboarding__section">
                        <div className="dono-onboarding__section-label">{ __( 'Target amount', 'dono' ) }</div>
                        <div className="dono-onboarding__amounts">
                            { presets.map( ( amount ) => {
                                const isSel = Number( value.amount ) === amount;
                                return (
                                    <button
                                        key={ amount }
                                        type="button"
                                        className={ `dono-onboarding__amount${ isSel ? ' is-selected' : '' }` }
                                        onClick={ () => set( { amount } ) }
                                    >
                                        { format === 'eu'
                                            ? <>{ groupDigits( amount, format, 0 ) } { symbol }</>
                                            : <>{ symbol }{ groupDigits( amount, format, 0 ) }</> }
                                    </button>
                                );
                            } ) }
                        </div>
                        <div className="dono-onboarding__custom-amount">
                            <AmountInput
                                value={ value.amount }
                                onChange={ ( n ) => set( { amount: n } ) }
                                currency={ currency || 'USD' }
                                format={ format }
                                decimalPlaces={ 0 }
                                min={ 0 }
                                max={ 100000000 }
                                placeholder={ __( 'Or type a custom amount', 'dono' ) }
                            />
                        </div>
                    </div>
                </>
            ) }

            { ! isTarget && (
                <div className="dono-onboarding__section">
                    <div className="dono-onboarding__section-label">{ __( 'No target needed', 'dono' ) }</div>
                    <div className="dono-onboarding__section-help">
                        { __( 'Dono will count every donation toward your campaign total. You can add a target later if you change your mind.', 'dono' ) }
                    </div>
                </div>
            ) }
        </div>
    );
}

// Step 4: Brand preset
const PRESET_CARDS = [
    {
        id:     'classic',
        thumb:  'classic',
        name:   __( 'Classic', 'dono' ),
        desc:   __( 'Friendly, rounded, green. The safe choice.', 'dono' ),
    },
    {
        id:     'bold',
        thumb:  'bold',
        name:   __( 'Bold', 'dono' ),
        desc:   __( 'Deep navy, strong type, dramatic shadow.', 'dono' ),
    },
    {
        id:     'quiet',
        thumb:  'quiet',
        name:   __( 'Quiet', 'dono' ),
        desc:   __( 'Minimal lines, lots of white space.', 'dono' ),
    },
    {
        id:     'theme',
        thumb:  'theme',
        name:   __( 'Use my theme', 'dono' ),
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
        win.postMessage(
            { type: 'dono:apply-tokens', tokens: selectedPreset?.tokens || {} },
            window.location.origin
        );
    }, [ selectedPreset ] );

    useEffect( () => {
        const onMsg = ( e ) => {
            if ( e.origin !== window.location.origin ) return;
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
            <h2 className="dono-onboarding__headline">{ __( 'Pick a starting look', 'dono' ) }</h2>
            <p className="dono-onboarding__subtitle">
                { __( 'You can edit colors and typography anytime.', 'dono' ) }
            </p>
            <div className="dono-onboarding__presets">
                { PRESET_CARDS.map( ( card ) => {
                    const isTheme   = card.id === 'theme';
                    const isDisabled = isTheme && ! themePreset;
                    const isSel     = value.preset_id === card.id;
                    const desc      = isTheme
                        ? ( themePreset
                            ? __( 'Inherits styles from your site theme.', 'dono' )
                            : __( 'No theme palette detected.', 'dono' ) )
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
                                <span className="dono-onboarding__preset-btn">{ __( 'Donate', 'dono' ) }</span>
                            </div>
                            <strong className="dono-onboarding__preset-name">{ card.name }</strong>
                            <span className="dono-onboarding__preset-desc">{ desc }</span>
                        </button>
                    );
                } ) }
            </div>

            <div className="dono-onboarding__preview">
                <div className="dono-onboarding__preview-label">{ __( 'Live preview', 'dono' ) }</div>
                { loadState === 'error' ? (
                    <div className="dono-onboarding__preview-fallback">
                        <p>{ __( 'Preview unavailable. Your choice is still saved.', 'dono' ) }</p>
                        <button
                            type="button"
                            className="dono-btn dono-btn--ghost"
                            onClick={ () => setReloadKey( ( k ) => k + 1 ) }
                        >
                            { __( 'Retry', 'dono' ) }
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
                            title={ __( 'Donation form preview', 'dono' ) }
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
function ChecklistStep( { finalized, settingsUrl, dashboardUrl } ) {
    const formUrl =
        finalized?.form_edit_url ||
        finalized?.campaign_page ||
        dashboardUrl ||
        '#';
    const gatewayUrl = settingsUrl ? `${ settingsUrl }#gateways` : ( dashboardUrl || '#' );

    return (
        <div>
            <h1 className="dono-onboarding__headline">{ __( "You're set up", 'dono' ) }</h1>
            <p className="dono-onboarding__subtitle">
                { __( "We've created your first campaign and donation form. Two quick things and you're live.", 'dono' ) }
            </p>

            <ul className="dono-onboarding__checklist">
                <ChecklistItem
                    title={ __( 'Connect a payment gateway', 'dono' ) }
                    description={ __( 'Stripe Connect, or a manual bank-transfer flow. You can change this any time.', 'dono' ) }
                    href={ gatewayUrl }
                    cta={ __( 'Connect', 'dono' ) }
                />
                <ChecklistItem
                    title={ __( 'Build your first form', 'dono' ) }
                    description={ __( 'Pick a layout, set amounts, brand it. Donors can give as soon as a gateway is live.', 'dono' ) }
                    href={ formUrl }
                    cta={ __( 'Build', 'dono' ) }
                />
            </ul>

            <p className="dono-onboarding__checklist-foot">
                <a className="dono-onboarding__checklist-skip" href={ dashboardUrl || '#' }>
                    { __( 'Skip for now', 'dono' ) }
                </a>
            </p>
        </div>
    );
}

function ChecklistItem( { title, description, href, cta } ) {
    return (
        <li className="dono-onboarding__checklist-item">
            <span className="dono-onboarding__checklist-bullet" aria-hidden="true" />
            <div className="dono-onboarding__checklist-body">
                <strong className="dono-onboarding__checklist-title">{ title }</strong>
                <span className="dono-onboarding__checklist-desc">{ description }</span>
            </div>
            <a className="dono-btn dono-btn--primary" href={ href }>{ cta }</a>
        </li>
    );
}

function numberFormatPair( fmt ) {
    return fmt === 'eu'
        ? { decimal: ',', thousand: '.' }
        : { decimal: '.', thousand: ',' };
}

