/** @jsxImportSource preact */

/**
 * Searchable country picker for the donor form. Stores an ISO 3166-1 alpha-2
 * code on `value` while displaying the country name; donors filter by typing.
 * Mirrors the admin SearchableSelect pattern, ported to Preact.
 */

import { useEffect, useMemo, useRef, useState } from 'preact/hooks';

import { COUNTRIES, countryName } from '../../_shared/countries';

export default function CountrySelect( {
    value,
    onChange,
    placeholder = 'Search country...',
    required = false,
    ariaInvalid = false,
    id,
} ) {
    const code = String( value || '' ).toUpperCase();
    const selectedName = countryName( code );

    const [ query, setQuery ]   = useState( '' );
    const [ open,  setOpen ]    = useState( false );
    const [ active, setActive ] = useState( 0 );

    const wrapRef  = useRef( null );
    const inputRef = useRef( null );

    useEffect( () => {
        if ( ! open ) return undefined;
        const onDoc = ( e ) => {
            if ( wrapRef.current && ! wrapRef.current.contains( e.target ) ) {
                setOpen( false );
            }
        };
        document.addEventListener( 'mousedown', onDoc );
        return () => document.removeEventListener( 'mousedown', onDoc );
    }, [ open ] );

    const matches = useMemo( () => {
        const q = query.trim().toLowerCase();
        if ( q === '' ) return COUNTRIES.slice( 0, 50 );
        return COUNTRIES.filter( ( c ) => (
            c.name.toLowerCase().includes( q ) || c.code.toLowerCase().includes( q )
        ) ).slice( 0, 50 );
    }, [ query ] );

    useEffect( () => { setActive( 0 ); }, [ query ] );

    const pick = ( c ) => {
        onChange && onChange( c.code );
        setQuery( '' );
        setOpen( false );
    };

    const onKeyDown = ( e ) => {
        if ( ! open ) return;
        if ( e.key === 'ArrowDown' ) {
            e.preventDefault();
            setActive( ( i ) => Math.min( matches.length - 1, i + 1 ) );
        } else if ( e.key === 'ArrowUp' ) {
            e.preventDefault();
            setActive( ( i ) => Math.max( 0, i - 1 ) );
        } else if ( e.key === 'Enter' ) {
            e.preventDefault();
            if ( matches[ active ] ) pick( matches[ active ] );
        } else if ( e.key === 'Escape' ) {
            setOpen( false );
            setQuery( '' );
        }
    };

    return (
        <div
            ref={ wrapRef }
            class={ `dono-form__country-select${ open ? ' is-open' : '' }` }
            onMouseDown={ ( e ) => {
                if ( inputRef.current && ! inputRef.current.contains( e.target ) ) {
                    e.preventDefault();
                }
            } }
        >
            <input
                ref={ inputRef }
                id={ id }
                type="text"
                class="dono-form__country-select-input"
                value={ open ? query : selectedName }
                placeholder={ selectedName || placeholder }
                required={ required }
                aria-invalid={ ariaInvalid || undefined }
                aria-autocomplete="list"
                aria-expanded={ open }
                role="combobox"
                onFocus={ () => { setOpen( true ); setQuery( '' ); } }
                onInput={ ( e ) => { setQuery( e.target.value ); if ( ! open ) setOpen( true ); } }
                onKeyDown={ onKeyDown }
            />
            <span class="dono-form__country-select-chevron" aria-hidden="true">
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                    <path d="M2 4 L5 7 L8 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            </span>

            { open && matches.length > 0 && (
                <ul class="dono-form__country-select-list" role="listbox">
                    { matches.map( ( c, i ) => (
                        <li
                            key={ c.code }
                            role="option"
                            aria-selected={ i === active }
                            class={ `dono-form__country-select-option${ i === active ? ' is-active' : '' }${ c.code === code ? ' is-current' : '' }` }
                            onMouseEnter={ () => setActive( i ) }
                            onClick={ () => pick( c ) }
                        >
                            <span class="dono-form__country-select-label">{ c.name }</span>
                            <span class="dono-form__country-select-hint">{ c.code }</span>
                        </li>
                    ) ) }
                </ul>
            ) }
        </div>
    );
}
