/** @jsxImportSource preact */

import { evaluateCondition } from '../state/conditions';
import { fieldEntry } from '../state/fields';
import { formatAmount } from '../util/format';
import { decodeEntities } from '../util/entities';
import { computeFees } from '../state/store';
import ErrorBoundary from '../components/ErrorBoundary';
import CountrySelect from '../components/CountrySelect';

export default function DonorStep( { fields: fieldsProp, step, state, dispatch, config } ) {
    // `fieldsProp` is a run of field-items from StepView's ordered walk;
    // `step.fields` is the flat alternative.
    const allFields = fieldsProp || step?.fields || [];
    const v      = state.values;
    const err    = state.errors;
    const fields = allFields.filter( ( f ) => evaluateCondition( f.condition, v ) );

    const onText = ( path ) => ( e ) =>
        dispatch( { type: 'SET_FIELD', path, value: e.target.value } );

    const onCheck = ( path ) => ( e ) =>
        dispatch( { type: 'SET_FIELD', path, value: e.target.checked } );

    const setField = ( path ) => ( value ) =>
        dispatch( { type: 'SET_FIELD', path, value } );

    const groups = groupByRow( fields );
    const ctx    = { v, err, onText, onCheck, setField, config, dispatch, state };

    // One broken field must not crash the whole form (which would drop the
    // donor back to the unstyled server fallback).
    const renderSafe = ( f, key ) => (
        <ErrorBoundary key={ key }>
            { renderField( f, key, ctx ) }
        </ErrorBoundary>
    );

    return (
        <div class="dono-form__donor">
            { groups.map( ( g, gi ) => g.row ? (
                <div
                    key={ `r${ g.row.id }-${ gi }` }
                    class="dono-form__grid"
                    style={ {
                        gridTemplateColumns: `repeat(${ g.row.columns }, minmax(0, 1fr))`,
                        gap: `${ g.row.gap ?? 12 }${ g.row.gapUnit || 'px' }`,
                    } }
                >
                    { g.fields.map( ( f, fi ) => renderSafe( f, `${ gi }-${ fi }` ) ) }
                </div>
            ) : (
                g.fields.map( ( f, fi ) => renderSafe( f, `${ gi }-${ fi }` ) )
            ) ) }
        </div>
    );
}

function groupByRow( fields ) {
    const groups = [];
    let current  = null;
    for ( const f of fields ) {
        const rowId = f.row?.id ?? null;
        if ( rowId === null ) {
            if ( current && current.row ) current = null;
            if ( ! current ) { current = { row: null, fields: [] }; groups.push( current ); }
            current.fields.push( f );
            continue;
        }
        if ( ! current || current.row?.id !== rowId ) {
            current = { row: f.row, fields: [] };
            groups.push( current );
        }
        current.fields.push( f );
    }
    return groups;
}

function renderField( f, key, { v, err, onText, onCheck, setField, config, dispatch, state } ) {
    switch ( f.kind ) {
        case 'name':
            return (
                <div key={ key } class="dono-form__row dono-form__row--two-up">
                    <Field
                        label={ f.firstLabel || config.i18n.firstName }
                        required={ f.requireFirst }
                        error={ err[ 'profile.first_name' ] }
                    >
                        <input
                            type="text"
                            autoComplete="given-name"
                            placeholder={ decodeEntities( f.firstPlaceholder || '' ) }
                            value={ v.profile.first_name }
                            onInput={ onText( 'profile.first_name' ) }
                            aria-invalid={ !! err[ 'profile.first_name' ] }
                            required={ f.requireFirst }
                        />
                    </Field>
                    <Field
                        label={ f.lastLabel || config.i18n.lastName }
                        required={ f.requireLast }
                        error={ err[ 'profile.last_name' ] }
                    >
                        <input
                            type="text"
                            autoComplete="family-name"
                            placeholder={ decodeEntities( f.lastPlaceholder || '' ) }
                            value={ v.profile.last_name }
                            onInput={ onText( 'profile.last_name' ) }
                            aria-invalid={ !! err[ 'profile.last_name' ] }
                            required={ f.requireLast }
                        />
                    </Field>
                </div>
            );

        case 'email':
            return (
                <Field
                    key={ key }
                    label={ f.label || config.i18n.email }
                    required={ f.required !== false }
                    error={ err[ 'email' ] }
                >
                    <input
                        type="email"
                        autoComplete="email"
                        placeholder={ decodeEntities( f.placeholder || '' ) }
                        value={ v.email }
                        onInput={ onText( 'email' ) }
                        aria-invalid={ !! err[ 'email' ] }
                        required={ f.required !== false }
                    />
                </Field>
            );

        case 'country':
            return (
                <Field
                    key={ key }
                    label={ f.label || config.i18n.country }
                    required={ !! f.required }
                    error={ err[ 'profile.country' ] }
                >
                    <CountrySelect
                        id={ `dono-country-${ key }` }
                        value={ v.profile.country }
                        onChange={ setField( 'profile.country' ) }
                        placeholder={ decodeEntities( f.placeholder || '' ) || config.i18n.searchCountry || 'Search country…' }
                        required={ !! f.required }
                        ariaInvalid={ !! err[ 'profile.country' ] }
                    />
                </Field>
            );

        case 'phone':
            return (
                <Field
                    key={ key }
                    label={ f.label || config.i18n.phone }
                    required={ !! f.required }
                    error={ err[ 'profile.phone' ] }
                >
                    <input
                        type="tel"
                        autoComplete="tel"
                        placeholder={ decodeEntities( f.placeholder || '' ) }
                        value={ v.profile.phone }
                        onInput={ onText( 'profile.phone' ) }
                        aria-invalid={ !! err[ 'profile.phone' ] }
                        required={ !! f.required }
                    />
                </Field>
            );

        case 'comment':
            return (
                <Field
                    key={ key }
                    label={ f.label || config.i18n.comment }
                    required={ f.required }
                    error={ err[ 'note_to_org' ] }
                >
                    <textarea
                        rows={ 3 }
                        maxLength={ 5000 }
                        placeholder={ decodeEntities( f.placeholder || '' ) }
                        value={ v.note_to_org }
                        onInput={ onText( 'note_to_org' ) }
                        aria-invalid={ !! err[ 'note_to_org' ] }
                        required={ !! f.required }
                    />
                    <label class="dono-form__check">
                        <input
                            type="checkbox"
                            checked={ !! v.note_public }
                            onChange={ onCheck( 'note_public' ) }
                        />
                        <span>{ config.i18n.notePublic }</span>
                    </label>
                </Field>
            );

        case 'anonymous':
            return (
                <label key={ key } class="dono-form__check">
                    <input
                        type="checkbox"
                        checked={ !! v.is_anonymous }
                        onChange={ onCheck( 'is_anonymous' ) }
                    />
                    <span>{ decodeEntities( f.label || '' ) || config.i18n.anonymous }</span>
                </label>
            );

        case 'cover_fees': {
            const fee = computeFees( state, v.amount_cents || 0 );
            return (
                <label key={ key } class="dono-form__check dono-form__cover-fees">
                    <input
                        type="checkbox"
                        checked={ !! v.cover_fees }
                        onChange={ onCheck( 'cover_fees' ) }
                    />
                    <span>
                        { decodeEntities( f.label || '' ) || config.i18n.coverFees }
                        { fee > 0 && (
                            <em class="dono-form__cover-fees-math">
                                { ` (${ formatAmount( fee, state.currency ) })` }
                            </em>
                        ) }
                    </span>
                </label>
            );
        }

        case 'fund': {
            const options    = Array.isArray( f.options ) ? f.options : [];
            const allowEmpty = !! f.allow_empty;
            const current    = v.fund_id || '';
            const setId      = ( id ) => onText( 'fund_id' )( { target: { value: id } } );
            return (
                <fieldset key={ key } class="dono-form__fund">
                    <legend>{ decodeEntities( f.label || '' ) }</legend>
                    <div class="dono-form__fund-options" role="radiogroup" aria-label={ decodeEntities( f.label || '' ) }>
                        { allowEmpty && (
                            <label class={ `dono-form__fund-option${ current === '' ? ' is-selected' : '' }` }>
                                <input
                                    type="radio"
                                    name={ `fund-${ key }` }
                                    checked={ current === '' }
                                    onChange={ () => setId( '' ) }
                                />
                                <span class="dono-form__fund-option-label">{ decodeEntities( f.empty_label || '' ) || config.i18n.noSpecificFund || 'No specific fund' }</span>
                                { f.empty_description && (
                                    <span class="dono-form__fund-option-desc">{ f.empty_description }</span>
                                ) }
                            </label>
                        ) }
                        { options.map( ( o ) => {
                            const id = String( o.id || '' );
                            // A parent with children is a group header, not a
                            // choice: donors pick a specific sub-fund.
                            if ( o.selectable === false ) {
                                return (
                                    <div key={ `g-${ id }` } class="dono-form__fund-group">
                                        { decodeEntities( o.label || '' ) || id }
                                    </div>
                                );
                            }
                            const checked = current === id;
                            return (
                                <label
                                    key={ id }
                                    class={ `dono-form__fund-option${ checked ? ' is-selected' : '' }${ o.depth ? ' is-child' : '' }` }
                                >
                                    <input
                                        type="radio"
                                        name={ `fund-${ key }` }
                                        checked={ checked }
                                        onChange={ () => setId( id ) }
                                    />
                                    <span class="dono-form__fund-option-label">{ decodeEntities( o.label || '' ) || id }</span>
                                    { o.description && (
                                        <span class="dono-form__fund-option-desc">{ o.description }</span>
                                    ) }
                                </label>
                            );
                        } ) }
                    </div>
                </fieldset>
            );
        }

        case 'address': {
            const a   = v.profile.address || {};
            const i18 = {
                line1:   f.line1Label   || config.i18n.addressLine1   || 'Address line 1',
                line2:   f.line2Label   || config.i18n.addressLine2   || 'Apartment, suite, etc.',
                city:    f.cityLabel    || config.i18n.addressCity    || 'City',
                region:  f.regionLabel  || config.i18n.addressRegion  || 'State / region',
                postal:  f.postalLabel  || config.i18n.addressPostal  || 'Postal code',
                country: f.countryLabel || config.i18n.addressCountry || 'Country',
            };
            return (
                <fieldset key={ key } class="dono-form__address">
                    { f.label && <legend>{ decodeEntities( f.label ) }</legend> }
                    { f.showLine1 && (
                        <Field
                            label={ i18.line1 }
                            required={ !! f.requireLine1 }
                            error={ err[ 'profile.address.line1' ] }
                        >
                            <input
                                type="text"
                                autoComplete="address-line1"
                                value={ a.line1 || '' }
                                onInput={ onText( 'profile.address.line1' ) }
                                required={ !! f.requireLine1 }
                                aria-invalid={ !! err[ 'profile.address.line1' ] }
                            />
                        </Field>
                    ) }
                    { f.showLine2 && (
                        <Field label={ i18.line2 } required={ false }>
                            <input
                                type="text"
                                autoComplete="address-line2"
                                value={ a.line2 || '' }
                                onInput={ onText( 'profile.address.line2' ) }
                            />
                        </Field>
                    ) }
                    { ( f.showCity || f.showRegion ) && (
                        <div class="dono-form__row dono-form__row--two-up">
                            { f.showCity && (
                                <Field
                                    label={ i18.city }
                                    required={ !! f.requireCity }
                                    error={ err[ 'profile.address.city' ] }
                                >
                                    <input
                                        type="text"
                                        autoComplete="address-level2"
                                        value={ a.city || '' }
                                        onInput={ onText( 'profile.address.city' ) }
                                        required={ !! f.requireCity }
                                        aria-invalid={ !! err[ 'profile.address.city' ] }
                                    />
                                </Field>
                            ) }
                            { f.showRegion && (
                                <Field
                                    label={ i18.region }
                                    required={ !! f.requireRegion }
                                    error={ err[ 'profile.address.region' ] }
                                >
                                    <input
                                        type="text"
                                        autoComplete="address-level1"
                                        value={ a.region || '' }
                                        onInput={ onText( 'profile.address.region' ) }
                                        required={ !! f.requireRegion }
                                        aria-invalid={ !! err[ 'profile.address.region' ] }
                                    />
                                </Field>
                            ) }
                        </div>
                    ) }
                    { ( f.showPostal || f.showCountry ) && (
                        <div class="dono-form__row dono-form__row--two-up">
                            { f.showPostal && (
                                <Field
                                    label={ i18.postal }
                                    required={ !! f.requirePostal }
                                    error={ err[ 'profile.address.postal' ] }
                                >
                                    <input
                                        type="text"
                                        autoComplete="postal-code"
                                        value={ a.postal || '' }
                                        onInput={ onText( 'profile.address.postal' ) }
                                        required={ !! f.requirePostal }
                                        aria-invalid={ !! err[ 'profile.address.postal' ] }
                                    />
                                </Field>
                            ) }
                            { f.showCountry && (
                                <Field
                                    label={ i18.country }
                                    required={ !! f.requireCountry }
                                    error={ err[ 'profile.address.country' ] }
                                >
                                    <CountrySelect
                                        id={ `dono-address-country-${ key }` }
                                        value={ a.country || '' }
                                        onChange={ setField( 'profile.address.country' ) }
                                        required={ !! f.requireCountry }
                                        ariaInvalid={ !! err[ 'profile.address.country' ] }
                                    />
                                </Field>
                            ) }
                        </div>
                    ) }
                </fieldset>
            );
        }

        case 'terms': {
            const id      = String( f.purpose || 'terms' );
            const errKey  = `consents.${ id }`;
            const agreed  = !! ( v.consents || {} )[ id ];
            const label   = decodeEntities( f.label || '' ) || ( config.i18n.agreeToTerms || 'I agree to the terms' );
            return (
                <div key={ key } class="dono-form__terms">
                    <label class="dono-form__terms-agree">
                        <input
                            type="checkbox"
                            checked={ agreed }
                            onChange={ onCheck( errKey ) }
                            aria-invalid={ !! err[ errKey ] }
                        />
                        <span class="dono-form__terms-label">{ label }</span>
                    </label>
                    { f.terms && (
                        // Scrolls rather than grows: long terms would push the
                        // submit button off the screen.
                        <div class="dono-form__terms-text" tabindex="0" role="region" aria-label={ label }>
                            { decodeEntities( f.terms ) }
                        </div>
                    ) }
                    { f.linkUrl && (
                        <p class="dono-form__terms-link">
                            <a href={ f.linkUrl } target="_blank" rel="noopener noreferrer">
                                { decodeEntities( f.linkText || '' ) || ( config.i18n.readTerms || 'Read the terms' ) }
                            </a>
                        </p>
                    ) }
                    { err[ errKey ] && <span class="dono-form__error">{ err[ errKey ] }</span> }
                </div>
            );
        }

        case 'consent': {
            const purposes = Array.isArray( f.purposes ) ? f.purposes : [];
            const consents = v.consents || {};
            return (
                <fieldset key={ key } class="dono-form__consent">
                    { f.label && <legend>{ decodeEntities( f.label ) }</legend> }
                    { f.helpText && <p class="dono-form__consent-help">{ f.helpText }</p> }
                    <div class="dono-form__consent-purposes">
                        { purposes.map( ( p ) => {
                            const id       = String( p.id || '' );
                            const required = !! p.required;
                            const checked  = required ? true : !! consents[ id ];
                            const errKey   = `consents.${ id }`;
                            return (
                                <label key={ id } class="dono-form__consent-purpose">
                                    <input
                                        type="checkbox"
                                        checked={ checked }
                                        disabled={ required }
                                        onChange={ onCheck( `consents.${ id }` ) }
                                        aria-invalid={ !! err[ errKey ] }
                                    />
                                    <span class="dono-form__consent-body">
                                        <span class="dono-form__consent-label">
                                            { decodeEntities( p.label || '' ) || id }
                                            { required && (
                                                <span class="dono-form__consent-required-pill">{ config.i18n.required || 'Required' }</span>
                                            ) }
                                        </span>
                                        { p.description && (
                                            <span class="dono-form__consent-desc">{ p.description }</span>
                                        ) }
                                        { err[ errKey ] && (
                                            <span class="dono-form__field-error" role="alert">{ err[ errKey ] }</span>
                                        ) }
                                    </span>
                                </label>
                            );
                        } ) }
                    </div>
                </fieldset>
            );
        }

        case 'date': {
            const fname  = String( f.field || '' );
            const cur    = ( v.custom && fname in v.custom ) ? v.custom[ fname ] : '';
            const errKey = `custom.${ fname }`;
            return (
                <Field
                    key={ key }
                    label={ f.label || 'Date' }
                    required={ !! f.required }
                    error={ err[ errKey ] }
                >
                    { f.helpText && <span class="dono-form__field-help">{ f.helpText }</span> }
                    <input
                        type="date"
                        value={ cur }
                        min={ f.minDate || undefined }
                        max={ f.maxDate || undefined }
                        onInput={ onText( `custom.${ fname }` ) }
                        required={ !! f.required }
                        aria-invalid={ !! err[ errKey ] }
                    />
                </Field>
            );
        }

        case 'text': {
            const fname  = String( f.field || '' );
            const cur    = ( v.custom && fname in v.custom ) ? v.custom[ fname ] : '';
            const errKey = `custom.${ fname }`;
            return (
                <Field
                    key={ key }
                    label={ f.label || 'Text' }
                    required={ !! f.required }
                    error={ err[ errKey ] }
                >
                    { f.helpText && <span class="dono-form__field-help">{ f.helpText }</span> }
                    <input
                        type="text"
                        placeholder={ decodeEntities( f.placeholder || '' ) }
                        value={ cur }
                        maxLength={ f.maxLength > 0 ? f.maxLength : undefined }
                        pattern={ f.pattern || undefined }
                        onInput={ onText( `custom.${ fname }` ) }
                        required={ !! f.required }
                        aria-invalid={ !! err[ errKey ] }
                    />
                </Field>
            );
        }

        case 'number': {
            const fname  = String( f.field || '' );
            const cur    = ( v.custom && fname in v.custom ) ? v.custom[ fname ] : '';
            const errKey = `custom.${ fname }`;
            return (
                <Field
                    key={ key }
                    label={ f.label || config.i18n.number || 'Number' }
                    required={ !! f.required }
                    error={ err[ errKey ] }
                >
                    { f.helpText && <span class="dono-form__field-help">{ f.helpText }</span> }
                    <input
                        type="number"
                        placeholder={ decodeEntities( f.placeholder || '' ) }
                        value={ cur }
                        min={ f.min === null || f.min === undefined ? undefined : f.min }
                        max={ f.max === null || f.max === undefined ? undefined : f.max }
                        step={ f.step || 1 }
                        onInput={ onText( `custom.${ fname }` ) }
                        required={ !! f.required }
                        aria-invalid={ !! err[ errKey ] }
                    />
                </Field>
            );
        }

        case 'frequency': {
            const freqs    = Array.isArray( f.frequencies ) ? f.frequencies : [];
            const current  = v.frequency || f.default || 'one-time';
            const i = config.i18n || {};
            const labelMap = {
                'one-time':  i.freqOneTime   || 'One-time',
                'weekly':    i.freqWeekly    || 'Weekly',
                'biweekly':  i.freqBiweekly  || 'Every 2 weeks',
                'monthly':   i.freqMonthly   || 'Monthly',
                'quarterly': i.freqQuarterly || 'Quarterly',
                'yearly':    i.freqYearly    || 'Yearly',
            };
            const style = f.style === 'tabs' ? 'tabs' : 'pills';
            return (
                <fieldset key={ key } class={ `dono-form__frequency dono-form__frequency--${ style }` }>
                    { f.label && <legend>{ decodeEntities( f.label ) }</legend> }
                    <div class="dono-form__frequency-options" role="radiogroup" aria-label={ decodeEntities( f.label || '' ) || config.i18n.frequency || 'Frequency' }>
                        { freqs.map( ( freq ) => {
                            const selected = current === freq;
                            return (
                                <button
                                    type="button"
                                    key={ freq }
                                    role="radio"
                                    class={ `dono-form__frequency-option${ selected ? ' is-selected' : '' }` }
                                    aria-checked={ selected }
                                    onClick={ () => dispatch( { type: 'SET_FREQUENCY', frequency: freq } ) }
                                >
                                    { labelMap[ freq ] || freq }
                                </button>
                            );
                        } ) }
                    </div>
                    { f.helpText && <p class="dono-form__frequency-help">{ f.helpText }</p> }
                </fieldset>
            );
        }

        case 'dropdown': {
            const fname    = String( f.field || '' );
            const options  = Array.isArray( f.options ) ? f.options : [];
            const cur      = ( v.custom && fname in v.custom ) ? String( v.custom[ fname ] ) : String( f.default || '' );
            const errKey   = `custom.${ fname }`;
            return (
                <Field
                    key={ key }
                    label={ f.label || '' }
                    required={ !! f.required }
                    error={ err[ errKey ] }
                >
                    <select
                        value={ cur }
                        onInput={ onText( `custom.${ fname }` ) }
                        aria-invalid={ !! err[ errKey ] }
                        required={ !! f.required }
                    >
                        { f.placeholder ? (
                            <option value="">{ decodeEntities( f.placeholder ) }</option>
                        ) : (
                            // No placeholder and nothing chosen: without an empty
                            // option the control shows the first one while state
                            // holds '', so the choice never submits.
                            cur === '' && <option value="" disabled></option>
                        ) }
                        { options.map( ( o ) => (
                            <option key={ o.value } value={ o.value }>
                                { decodeEntities( o.label || '' ) || o.value }
                            </option>
                        ) ) }
                    </select>
                </Field>
            );
        }

        case 'radio': {
            const fname   = String( f.field || '' );
            const options = Array.isArray( f.options ) ? f.options : [];
            const cur     = ( v.custom && fname in v.custom ) ? String( v.custom[ fname ] ) : String( f.default || '' );
            const errKey  = `custom.${ fname }`;
            const layout  = f.layout === 'horizontal' ? 'horizontal' : 'vertical';
            return (
                <fieldset key={ key } class={ `dono-form__radio dono-form__radio--${ layout }` }>
                    { f.label && (
                        <legend>
                            { decodeEntities( f.label ) }
                            { f.required && <span class="dono-form__required" aria-hidden="true">*</span> }
                        </legend>
                    ) }
                    <div class="dono-form__radio-options" role="radiogroup" aria-label={ decodeEntities( f.label || '' ) }>
                        { options.map( ( o ) => {
                            const checked = cur === String( o.value );
                            return (
                                <label
                                    key={ o.value }
                                    class={ `dono-form__radio-option${ checked ? ' is-selected' : '' }` }
                                >
                                    <input
                                        type="radio"
                                        name={ `custom-${ fname }-${ key }` }
                                        value={ o.value }
                                        checked={ checked }
                                        onChange={ () => onText( `custom.${ fname }` )( { target: { value: o.value } } ) }
                                        required={ !! f.required }
                                    />
                                    <span class="dono-form__radio-option-label">
                                        { decodeEntities( o.label || '' ) || o.value }
                                    </span>
                                </label>
                            );
                        } ) }
                    </div>
                    { err[ errKey ] && (
                        <span class="dono-form__field-error" role="alert">{ err[ errKey ] }</span>
                    ) }
                </fieldset>
            );
        }

        case 'checkbox': {
            const fname    = String( f.field || '' );
            const cur      = ( v.custom && fname in v.custom ) ? !! v.custom[ fname ] : !! f.defaultOn;
            const errKey   = `custom.${ fname }`;
            return (
                <label key={ key } class="dono-form__check dono-form__check--single">
                    <input
                        type="checkbox"
                        checked={ cur }
                        onChange={ onCheck( `custom.${ fname }` ) }
                        aria-invalid={ !! err[ errKey ] }
                        required={ !! f.required }
                    />
                    <span class="dono-form__check-body">
                        <span class="dono-form__check-label">
                            { decodeEntities( f.label || '' ) }
                            { f.required && <span class="dono-form__required" aria-hidden="true">*</span> }
                        </span>
                        { f.helpText && (
                            <span class="dono-form__check-help">{ f.helpText }</span>
                        ) }
                        { err[ errKey ] && (
                            <span class="dono-form__field-error" role="alert">{ err[ errKey ] }</span>
                        ) }
                    </span>
                </label>
            );
        }

        case 'multi-select': {
            const fname     = String( f.field || '' );
            const options   = Array.isArray( f.options ) ? f.options : [];
            const stored    = ( v.custom && Array.isArray( v.custom[ fname ] ) ) ? v.custom[ fname ] : null;
            const defaults  = Array.isArray( f.defaults ) ? f.defaults.map( String ) : [];
            const selection = stored !== null ? stored.map( String ) : defaults;
            const errKey    = `custom.${ fname }`;

            const toggle = ( val ) => {
                const has  = selection.includes( val );
                const next = has
                    ? selection.filter( ( x ) => x !== val )
                    : [ ...selection, val ];
                onText( `custom.${ fname }` )( { target: { value: next } } );
            };

            return (
                <fieldset key={ key } class="dono-form__multi-select">
                    { f.label && (
                        <legend>
                            { decodeEntities( f.label ) }
                            { ( f.required || f.minSelections > 0 ) && <span class="dono-form__required" aria-hidden="true">*</span> }
                        </legend>
                    ) }
                    <div class="dono-form__multi-select-options">
                        { options.map( ( o ) => {
                            const val     = String( o.value );
                            const checked = selection.includes( val );
                            return (
                                <label
                                    key={ val }
                                    class={ `dono-form__multi-select-option${ checked ? ' is-selected' : '' }` }
                                >
                                    <input
                                        type="checkbox"
                                        checked={ checked }
                                        onChange={ () => toggle( val ) }
                                    />
                                    <span class="dono-form__multi-select-option-label">
                                        { decodeEntities( o.label || '' ) || o.value }
                                    </span>
                                </label>
                            );
                        } ) }
                    </div>
                    { err[ errKey ] && (
                        <span class="dono-form__field-error" role="alert">{ err[ errKey ] }</span>
                    ) }
                </fieldset>
            );
        }

        case 'hidden':
            // No UI: the value rides the payload via buildPayload's custom
            // serializer.
            return null;

        default: {
            // A field kind contributed by an add-on, rendered with the same
            // context as the built-ins.
            const entry = fieldEntry( f.kind );
            if ( ! entry || typeof entry.component !== 'function' ) return null;
            const Component = entry.component;
            return <Component key={ key } field={ f } ctx={ { v, err, onText, onCheck, setField, config, dispatch, state } } />;
        }
    }
}

function Field( { label, required, error, children } ) {
    return (
        <label class="dono-form__field">
            <span class="dono-form__label">
                { decodeEntities( label ) }
                { required && <span class="dono-form__required" aria-hidden="true">*</span> }
            </span>
            { children }
            { error && <span class="dono-form__field-error" role="alert">{ error }</span> }
        </label>
    );
}
