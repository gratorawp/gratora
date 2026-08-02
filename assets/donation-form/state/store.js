/** @jsxImportSource preact */
import { evaluateCondition } from './conditions';
import { mergePayload, registeredErrors, registeredValues } from './fields';
import { convertCents, displayPreset, isZeroDecimal, roundToCurrency } from '../util/fx';
import { formatAmount } from '../util/format';
/**
 * Donation-form state: single useReducer source of truth
 * (step, steps, values, errors, status, submission, message).
 */

// Every field-bearing collection: fields authored above a wizard live in
// `preamble` (rendered once above the form, not inside a step), so fold them
// in as a synthetic first-page donor step. Validation, fees, suppression and
// payload building must all walk this, never `steps` alone, or preamble
// fields become orphaned, never-validated, never-suppressed inputs. Works on
// both the boot config and the reducer state (same preamble/steps keys).
export function fieldSteps( src ) {
    const pre = ( Array.isArray( src.preamble ) && src.preamble.length )
        ? [ { type: 'donor', page: 0, items: src.preamble } ]
        : [];
    return pre.concat( src.steps || [] );
}

export function initialState( config ) {
    const presets   = findStep( config.steps, 'amount' )?.presets || [];
    const first     = ( Array.isArray( presets ) && presets.find( ( p ) => p && p.preselected ) ) || presets[ 0 ];
    const fallback  = typeof first === 'number'
        ? Number( first )
        : Number( first?.cents || 0 );
    const firstCents = Number( config.__prefillAmount ) || fallback;
    const donorSteps = fieldSteps( config ).filter( ( s ) => s.type === 'donor' );
    const allDonorFields = donorSteps.flatMap( fieldsOf );
    const anonField = allDonorFields.find( ( f ) => f.kind === 'anonymous' );
    const fundField = allDonorFields.find( ( f ) => f.kind === 'fund' );
    const freqField = allDonorFields.find( ( f ) => f.kind === 'frequency' );
    const consents  = initialConsents( donorSteps );
    const custom    = initialCustom( donorSteps );

    return {
        step:        0,
        steps:       config.steps,
        preamble:    Array.isArray( config.preamble ) ? config.preamble : [],
        pages:       Array.isArray( config.pages )   ? config.pages   : [],
        pageNav:     ( config.pageNav && typeof config.pageNav === 'object' ) ? config.pageNav : {},
        currency:    config.currency,
        // Presets are authored in config.currency; fx converts to whatever
        // currency the donor switches to.
        presetCurrency: config.currency,
        fx:          ( config.fx && typeof config.fx === 'object' )
            ? config.fx
            : { base: config.currency, rates: {} },
        gateway:     ( config.gateways && config.gateways.default ) || config.gateway,
        i18n:        ( config.i18n && typeof config.i18n === 'object' ) ? config.i18n : {},
        minAmountCents: Number( config.spam?.minAmountCents ) || 0,
        slug:        config.slug,
        formId:      config.form_id || null,
        campaignId:  config.campaign_id || null,
        values: {
            amount_cents: firstCents,
            email:        '',
            profile:      {
                first_name: '',
                last_name:  '',
                country:    '',
                phone:      '',
                address:    { line1: '', line2: '', city: '', region: '', postal: '', country: '' },
            },
            note_to_org:  '',
            note_public:  false,
            is_anonymous: !! anonField?.defaultOn,
            cover_fees:   !! allDonorFields.find( ( f ) => f.kind === 'cover_fees' )?.defaultOn,
            fund_id:      fundField ? String( fundField.default_id || '' ) : '',
            consents,
            frequency:    initialFrequency( config, freqField ),
            custom,
            ...registeredValues( allDonorFields ),
        },
        errors:     {},
        status:     'idle',
        submission: null,
        payment:    null,
        message:    '',
    };
}

// Portal give-again links prefill ?dono_frequency= in the stored underscore
// vocabulary ('one_time'); form state uses hyphens. Apply the prefill only
// when the form's frequency field actually offers it; otherwise fall back to
// the authored default.
function initialFrequency( config, freqField ) {
    const fallback = freqField ? String( freqField.default || 'one-time' ) : 'one-time';
    const raw = String( config.__prefillFrequency || '' ).trim();
    if ( ! raw || ! freqField ) return fallback;
    const norm    = raw.replace( /_/g, '-' );
    const offered = Array.isArray( freqField.frequencies ) ? freqField.frequencies : [];
    return offered.includes( norm ) ? norm : fallback;
}

function initialConsents( donorSteps ) {
    const out = {};
    const list = Array.isArray( donorSteps ) ? donorSteps : [ donorSteps ];
    for ( const s of list ) {
        for ( const f of fieldsOf( s ) ) {
            if ( f.kind !== 'consent' ) continue;
            const defaultOn = f.defaultState === 'opt-out';
            for ( const p of ( f.purposes || [] ) ) {
                out[ p.id ] = !! p.requiredByLaw || defaultOn;
            }
        }
    }
    return out;
}

function initialCustom( donorSteps ) {
    // Multi-page wizards emit one donor step per page; aggregate all fields
    // so the initial custom values cover the whole form.
    const all = [];
    for ( const s of ( Array.isArray( donorSteps ) ? donorSteps : [ donorSteps ] ) ) {
        for ( const f of fieldsOf( s ) ) all.push( f );
    }
    const out = {};
    for ( const f of all ) {
        const key = String( f.field || '' );
        if ( ! key ) continue;
        switch ( f.kind ) {
            case 'date':
            case 'text':
            case 'number':
                out[ key ] = '';
                break;
            case 'dropdown':
            case 'radio': {
                const opts  = Array.isArray( f.options ) ? f.options : [];
                const found = opts.find( ( o ) => o && o.isDefault );
                out[ key ] = found ? String( found.value ) : String( f.default || '' );
                break;
            }
            case 'checkbox':
                out[ key ] = !! f.defaultOn;
                break;
            case 'multi-select': {
                if ( Array.isArray( f.defaults ) ) {
                    out[ key ] = f.defaults.map( String );
                } else {
                    const opts = Array.isArray( f.options ) ? f.options : [];
                    out[ key ] = opts.filter( ( o ) => o && o.isDefault ).map( ( o ) => String( o.value ) );
                }
                break;
            }
            case 'hidden':
                out[ key ] = resolveHiddenValue( f );
                break;
            default:
                break;
        }
    }
    return out;
}

function resolveHiddenValue( f ) {
    const fallback = String( f.defaultValue || '' );
    if ( typeof window === 'undefined' ) return fallback;

    const src    = String( f.source || 'fixed' );
    const params = new URLSearchParams( window.location.search );

    let value = '';
    switch ( src ) {
        case 'query':       value = params.get( String( f.queryParam || '' ) ) || ''; break;
        case 'utm_source':  value = params.get( 'utm_source' )   || ''; break;
        case 'utm_medium':  value = params.get( 'utm_medium' )   || ''; break;
        case 'utm_campaign':value = params.get( 'utm_campaign' ) || ''; break;
        case 'utm_term':    value = params.get( 'utm_term' )     || ''; break;
        case 'utm_content': value = params.get( 'utm_content' )  || ''; break;
        case 'referrer':    value = document.referrer            || ''; break;
        case 'landing':     value = window.location.href         || ''; break;
        case 'fixed':       /* fallthrough */
        default:            value = '';
    }
    return value || fallback;
}

export function reducer( state, action ) {
    switch ( action.type ) {
        case 'SET_FIELD':
            return {
                ...state,
                values: setIn( state.values, action.path, action.value ),
                errors: clearError( state.errors, action.path ),
            };

        case 'SET_AMOUNT':
            return {
                ...state,
                values: { ...state.values, amount_cents: action.cents },
                errors: clearError( state.errors, 'amount_cents' ),
            };

        case 'SET_CURRENCY': {
            const from = state.currency;
            const to   = action.currency;
            if ( ! to || from === to ) return { ...state, currency: to };

            // If a preset tile is selected, keep that tile selected and show
            // its converted, nice-rounded value in the new currency. A custom
            // amount converts precisely, then snaps to a whole major unit when
            // the target currency has no sub-unit: EUR 12.50 into JPY is
            // 2004.62 yen, which the server refuses.
            const presets = presetCentsOf( state );
            const cur     = state.values.amount_cents;
            const idx = presets.findIndex(
                ( c ) => displayPreset( state.fx, c, state.presetCurrency, from ) === cur
            );
            const nextCents = idx >= 0
                ? displayPreset( state.fx, presets[ idx ], state.presetCurrency, to )
                : roundToCurrency( convertCents( state.fx, cur, from, to ), to );

            return {
                ...state,
                currency: to,
                values:   { ...state.values, amount_cents: nextCents },
            };
        }

        case 'SET_FREQUENCY':
            return {
                ...state,
                values: { ...state.values, frequency: action.frequency },
            };

        case 'SET_GATEWAY':
            return { ...state, gateway: action.gateway };

        case 'NEXT': {
            const errors = action.errors || {};
            if ( Object.keys( errors ).length > 0 ) {
                return { ...state, errors };
            }
            // state.step is the current wizard page index, bounded by pages,
            // not by the flat semantic-step list.
            const total = Array.isArray( state.pages ) ? state.pages.length : 0;
            const max   = Math.max( 0, total - 1 );
            return { ...state, step: Math.min( state.step + 1, max ), errors: {} };
        }

        case 'SET_ERRORS':
            // An optional `step` lets a full-form re-validation jump to the page
            // owning the first errored field so the error is actually visible.
            return {
                ...state,
                errors: action.errors || {},
                ...( typeof action.step === 'number' ? { step: action.step } : {} ),
            };

        case 'PREV':
            return { ...state, step: Math.max( state.step - 1, 0 ) };

        case 'SUBMIT_START':
            return { ...state, status: 'submitting', message: '' };

        case 'AWAIT_PAYMENT':
            return {
                ...state,
                status:  'payment',
                payment: action.payment || null,
                message: '',
            };

        case 'CONFIRMING':
            return { ...state, status: 'confirming', message: '' };

        case 'SUBMIT_SUCCESS':
            return {
                ...state,
                status:     'success',
                submission: action.data,
                message:    '',
            };

        case 'SUBMIT_PENDING':
            return {
                ...state,
                // Two different things are not paid yet. `pending` means the
                // donor still has something to do; `processing` means a bank
                // debit is on its way and they are finished. Showing the wrong
                // one asks someone who has already paid to pay again.
                status:     action.data?.status === 'processing' ? 'processing' : 'pending',
                submission: action.data,
                message:    '',
            };

        case 'SUBMIT_ERROR':
            return {
                ...state,
                status:  'error',
                message: action.message || '',
            };

        case 'CANCEL_PAYMENT':
            // Back out of the card step without wiping the form: keep every
            // entered value and the current step, just drop the payment intent.
            return {
                ...state,
                status:  'idle',
                payment: null,
                message: '',
            };

        case 'RESET':
            return initialState( {
                steps:       state.steps,
                preamble:    state.preamble,
                pages:       state.pages,
                pageNav:     state.pageNav,
                currency:    state.presetCurrency,
                gateway:     state.gateway,
                slug:        state.slug,
                form_id:     state.formId,
                campaign_id: state.campaignId,
                fx:          state.fx,
                i18n:        state.i18n,
                spam:        { minAmountCents: state.minAmountCents },
            } );

        default:
            return state;
    }
}

function findStep( steps, type ) {
    return ( steps || [] ).find( ( s ) => s.type === type );
}

// Donor steps carry fields as items tagged t:'field' (interleaved with t:'deco'
// content); fall back to a flat fields array for any step not shaped that way.
function fieldsOf( step ) {
    if ( Array.isArray( step?.items ) ) {
        return step.items.filter( ( it ) => it && it.t === 'field' );
    }
    return Array.isArray( step?.fields ) ? step.fields : [];
}

function findField( step, kind ) {
    return fieldsOf( step ).find( ( f ) => f.kind === kind );
}

/** Raw preset amounts (in presetCurrency cents) from the amount step. */
function presetCentsOf( state ) {
    const list = findStep( state.steps, 'amount' )?.presets || [];
    return list.map( ( p ) => ( typeof p === 'number' ? Number( p ) : Number( p?.cents ) || 0 ) );
}

function setIn( obj, pathStr, value ) {
    const parts = pathStr.split( '.' );
    if ( parts.length === 1 ) return { ...obj, [ parts[ 0 ] ]: value };
    const [ head, ...rest ] = parts;
    return {
        ...obj,
        [ head ]: setIn( obj[ head ] || {}, rest.join( '.' ), value ),
    };
}

function clearError( errors, path ) {
    if ( ! errors[ path ] ) return errors;
    const next = { ...errors };
    delete next[ path ];
    return next;
}

// Returns a `{ fieldName: 'message' }` map; empty means valid.
export function validateStep( step, state ) {
    const e = {};
    // Localized validation messages come from config.i18n.validation (threaded
    // into state at init); the English literals are the fallback.
    const vi = ( state.i18n && state.i18n.validation ) || {};
    const msg = ( key, fallback, arg ) => {
        const s = vi[ key ] || fallback;
        return arg === undefined ? s : String( s ).replace( '%s', arg );
    };
    switch ( step.type ) {
        case 'amount': {
            const amt = state.values.amount_cents;
            const min = Number( state.minAmountCents ) || 0;
            if ( ! amt || amt <= 0 ) {
                e[ 'amount_cents' ] = msg( 'pickAmount', 'Pick or enter an amount.' );
            } else if ( min > 0 && amt < min ) {
                // Server enforces the same minimum; surface it at the field so a
                // paged form jumps back here instead of failing on submit.
                const fmt = formatAmount( min, state.currency );
                e[ 'amount_cents' ] = msg( 'minAmount', `Minimum donation is ${ fmt }.`, fmt );
            }
            break;
        }

        case 'donor': {
            const v = state.values;
            const fields = fieldsOf( step ).filter( ( f ) => evaluateCondition( f.condition, v ) );
            for ( const f of fields ) {
                if ( f.kind === 'email' ) {
                    // Always required: the server hard-requires a valid email,
                    // so never let a form config make it optional (-> 400).
                    if ( ! v.email || ! /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test( v.email ) ) {
                        e[ 'email' ] = msg( 'invalidEmail', 'Enter a valid email.' );
                    }
                }
                if ( f.kind === 'name' ) {
                    if ( f.requireFirst && ! v.profile.first_name?.trim() ) {
                        e[ 'profile.first_name' ] = msg( 'required', 'Required.' );
                    }
                    if ( f.requireLast && ! v.profile.last_name?.trim() ) {
                        e[ 'profile.last_name' ] = msg( 'required', 'Required.' );
                    }
                }
                if ( f.kind === 'comment' && f.required && ! v.note_to_org?.trim() ) {
                    e[ 'note_to_org' ] = msg( 'required', 'Required.' );
                }
                if ( f.kind === 'phone' && f.required && ! v.profile.phone?.trim() ) {
                    e[ 'profile.phone' ] = msg( 'required', 'Required.' );
                }
                if ( f.kind === 'country' && f.required && ! v.profile.country?.trim() ) {
                    e[ 'profile.country' ] = msg( 'required', 'Required.' );
                }
                if ( f.kind === 'address' ) {
                    const a = v.profile.address || {};
                    if ( f.showLine1   && f.requireLine1   && ! a.line1?.trim()   ) e[ 'profile.address.line1' ]   = msg( 'required', 'Required.' );
                    if ( f.showCity    && f.requireCity    && ! a.city?.trim()    ) e[ 'profile.address.city' ]    = msg( 'required', 'Required.' );
                    if ( f.showRegion  && f.requireRegion  && ! a.region?.trim()  ) e[ 'profile.address.region' ]  = msg( 'required', 'Required.' );
                    if ( f.showPostal  && f.requirePostal  && ! a.postal?.trim()  ) e[ 'profile.address.postal' ]  = msg( 'required', 'Required.' );
                    if ( f.showCountry && f.requireCountry && ! a.country?.trim() ) e[ 'profile.address.country' ] = msg( 'required', 'Required.' );
                }
                if ( f.kind === 'consent' ) {
                    for ( const p of ( f.purposes || [] ) ) {
                        if ( p.requiredByLaw && ! v.consents?.[ p.id ] ) {
                            e[ `consents.${ p.id }` ] = msg( 'required', 'Required.' );
                        }
                    }
                }
                if ( ( f.kind === 'date' || f.kind === 'text' || f.kind === 'number' ) && f.required ) {
                    const name = String( f.field || '' );
                    if ( name && ! String( v.custom?.[ name ] ?? '' ).trim() ) {
                        e[ `custom.${ name }` ] = msg( 'required', 'Required.' );
                    }
                }
                if ( f.kind === 'number' && f.field ) {
                    const raw = v.custom?.[ f.field ];
                    if ( raw !== undefined && raw !== null && String( raw ).trim() !== '' ) {
                        const n = Number( raw );
                        if ( Number.isNaN( n ) ) e[ `custom.${ f.field }` ] = msg( 'invalidNumber', 'Enter a number.' );
                        else if ( f.min != null && n < Number( f.min ) ) e[ `custom.${ f.field }` ] = msg( 'minNumber', `Must be at least ${ f.min }.`, f.min );
                        else if ( f.max != null && n > Number( f.max ) ) e[ `custom.${ f.field }` ] = msg( 'maxNumber', `Must be at most ${ f.max }.`, f.max );
                    }
                }
                if ( f.kind === 'date' && f.field ) {
                    const d = String( v.custom?.[ f.field ] ?? '' ).trim();
                    if ( d && f.minDate && d < f.minDate ) e[ `custom.${ f.field }` ] = msg( 'minDate', `On or after ${ f.minDate }.`, f.minDate );
                    else if ( d && f.maxDate && d > f.maxDate ) e[ `custom.${ f.field }` ] = msg( 'maxDate', `On or before ${ f.maxDate }.`, f.maxDate );
                }
                if ( f.kind === 'text' && f.field ) {
                    const val = String( v.custom?.[ f.field ] ?? '' );
                    if ( val.trim() && f.maxLength > 0 && val.length > f.maxLength ) {
                        e[ `custom.${ f.field }` ] = msg( 'tooLong', `Too long (max ${ f.maxLength }).`, f.maxLength );
                    } else if ( val.trim() && f.pattern ) {
                        let re = null;
                        try { re = new RegExp( `^(?:${ f.pattern })$` ); } catch ( err ) { re = null; }
                        if ( re && ! re.test( val ) ) e[ `custom.${ f.field }` ] = msg( 'invalidFormat', 'Invalid format.' );
                    }
                }
                if ( ( f.kind === 'dropdown' || f.kind === 'radio' ) && f.required ) {
                    const name = String( f.field || '' );
                    if ( name && ! String( v.custom?.[ name ] ?? '' ).trim() ) {
                        e[ `custom.${ name }` ] = msg( 'required', 'Required.' );
                    }
                }
                if ( f.kind === 'checkbox' && f.required ) {
                    const name = String( f.field || '' );
                    if ( name && ! v.custom?.[ name ] ) {
                        e[ `custom.${ name }` ] = msg( 'required', 'Required.' );
                    }
                }
                if ( f.kind === 'multi-select' ) {
                    const name = String( f.field || '' );
                    if ( name ) {
                        const sel  = Array.isArray( v.custom?.[ name ] ) ? v.custom[ name ] : [];
                        const min  = Math.max( 0, Number( f.minSelections || 0 ) );
                        const max  = Math.max( 0, Number( f.maxSelections || 0 ) );
                        if ( f.required && sel.length === 0 ) {
                            e[ `custom.${ name }` ] = msg( 'pickAtLeastOne', 'Pick at least one.' );
                        } else if ( min > 0 && sel.length < min ) {
                            e[ `custom.${ name }` ] = msg( 'pickAtLeast', `Pick at least ${ min }.`, min );
                        } else if ( max > 0 && sel.length > max ) {
                            e[ `custom.${ name }` ] = msg( 'pickNoMoreThan', `Pick no more than ${ max }.`, max );
                        }
                    }
                }
                Object.assign( e, registeredErrors( f, v, msg ) || {} );
            }
            break;
        }

        default:
            // Confirm / Submit steps have nothing to validate locally.
            break;
    }
    return e;
}

// Form UI uses 'one-time' (hyphen); the API + DB use 'one_time' (underscore).
function normalizeFrequency( raw ) {
    const f = ( raw || '' ).trim();
    if ( ! f ) return undefined;
    return f === 'one-time' ? 'one_time' : f;
}

// Fields hidden by a false condition must not submit their seeded default
// (a hidden recurring toggle would otherwise enroll the donor in a subscription
// they never saw). Collect what to suppress from the currently-hidden fields.
function suppressedFields( state ) {
    const v = state.values;
    const CUSTOM = [ 'text', 'number', 'date', 'dropdown', 'radio', 'checkbox', 'multi-select', 'hidden' ];
    const out = { custom: new Set(), frequency: false, fund: false, anon: false, fees: false };
    for ( const step of fieldSteps( state ) ) {
        for ( const f of fieldsOf( step ) ) {
            if ( evaluateCondition( f.condition, v ) ) continue;
            if ( f.kind === 'frequency' ) out.frequency = true;
            else if ( f.kind === 'fund' ) out.fund = true;
            else if ( f.kind === 'anonymous' ) out.anon = true;
            else if ( f.kind === 'cover_fees' ) out.fees = true;
            else if ( CUSTOM.includes( f.kind ) && f.field ) out.custom.add( String( f.field ) );
        }
    }
    return out;
}

export function buildPayload( state ) {
    const v = state.values;
    const sup = suppressedFields( state );
    const base = Number( v.amount_cents ) || 0;
    const fee  = coveredFeeCents( state );
    return mergePayload( {
        email:             ( v.email || '' ).trim(),
        amount_cents:      base + fee,
        fee_covered_cents: fee,
        currency:          state.currency,
        gateway:           state.gateway,
        form_id:           state.formId || undefined,
        campaign_id:       state.campaignId || undefined,
        note_to_org:       ( v.note_to_org || '' ).trim() || undefined,
        note_public:       v.note_public ? true : undefined,
        is_anonymous:      sup.anon ? false : !! v.is_anonymous,
        fund_id:           sup.fund ? undefined : ( ( v.fund_id || '' ).trim() || undefined ),
        consents:          buildConsents( v.consents ),
        frequency:         sup.frequency ? 'one_time' : normalizeFrequency( v.frequency ),
        custom:            buildCustom( v.custom, sup.custom ),
        source_attribution: buildSourceAttribution(),
        profile: {
            first_name: ( v.profile.first_name || '' ).trim() || undefined,
            last_name:  ( v.profile.last_name  || '' ).trim() || undefined,
            country:    ( v.profile.country    || '' ).trim() || undefined,
            phone:      ( v.profile.phone      || '' ).trim() || undefined,
            address:    buildAddress( v.profile.address ),
        },
    }, v, state );
}

function buildAddress( a ) {
    if ( ! a ) return undefined;
    const out = {
        line1:   ( a.line1   || '' ).trim() || undefined,
        line2:   ( a.line2   || '' ).trim() || undefined,
        city:    ( a.city    || '' ).trim() || undefined,
        region:  ( a.region  || '' ).trim() || undefined,
        postal:  ( a.postal  || '' ).trim() || undefined,
        country: ( a.country || '' ).trim() || undefined,
    };
    const empty = Object.values( out ).every( ( v ) => v === undefined );
    return empty ? undefined : out;
}

function buildConsents( c ) {
    if ( ! c || typeof c !== 'object' ) return undefined;
    const out = {};
    for ( const k of Object.keys( c ) ) out[ k ] = !! c[ k ];
    return Object.keys( out ).length === 0 ? undefined : out;
}

// Capture traffic attribution from the landing URL + referrer so the server's
// ChannelClassifier can bucket the donation (utm_* -> paid/social, referrer ->
// referral, neither -> direct) instead of everything reading as 'direct'.
function buildSourceAttribution() {
    if ( typeof window === 'undefined' ) return undefined;
    const out = {};
    const params = new URLSearchParams( window.location.search );
    for ( const k of [ 'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content' ] ) {
        const val = params.get( k );
        if ( val ) out[ k ] = val;
    }
    if ( document.referrer ) out.referrer = document.referrer;
    out.landing = window.location.href;
    return Object.keys( out ).length ? out : undefined;
}

function buildCustom( c, suppress ) {
    if ( ! c || typeof c !== 'object' ) return undefined;
    const out = {};
    for ( const k of Object.keys( c ) ) {
        if ( suppress && suppress.has( k ) ) continue;
        const raw = c[ k ];
        if ( raw === '' || raw === null || raw === undefined ) continue;
        out[ k ] = raw;
    }
    return Object.keys( out ).length === 0 ? undefined : out;
}

export function computeFees( state, base ) {
    // Multi-page wizards emit one donor step per page; scan all for cover-fees.
    const donorSteps = fieldSteps( state ).filter( ( s ) => s.type === 'donor' );
    const f = donorSteps.map( ( s ) => findField( s, 'cover_fees' ) ).find( Boolean );
    if ( ! f ) return 0;
    // The fixed component is authored in the org base currency; convert it
    // when the donor switched, so a 30-cent fixed never rides as 0.30 EUR.
    const fixed = convertCents( state.fx, Number( f.fixed || 0 ), state.presetCurrency, state.currency );
    let fee = Math.round( base * ( ( f.percent || 0 ) / 100 ) ) + fixed;
    if ( isZeroDecimal( state.currency ) ) {
        // Storage is major x 100: round to a whole major unit (nearest, ties
        // away from zero) so base + fee passes the server's no-fractional-
        // amounts check for this currency.
        fee = Math.round( fee / 100 ) * 100;
    }
    return Math.max( 0, fee );
}

// The fee actually charged: zero when the donor left cover-fees unchecked or
// a condition hides the field. Display sites (summary, submit label) must use
// this, not computeFees directly, so shown totals always match the payload.
export function coveredFeeCents( state ) {
    if ( ! state.values.cover_fees ) return 0;
    if ( suppressedFields( state ).fees ) return 0;
    return computeFees( state, Number( state.values.amount_cents ) || 0 );
}
