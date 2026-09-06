/** @jsxImportSource preact */

/**
 * Searchable country picker for the donor form. Stores an ISO 3166-1 alpha-2
 * code on `value` while displaying the country name; donors filter by typing.
 * Mirrors the admin SearchableSelect pattern, ported to Preact.
 */

import { useEffect, useMemo, useRef, useState } from 'preact/hooks';

import { countryName, localizedCountries } from '../../_shared/countries';

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

    const listId = id ? `${ id }-listbox` : undefined;
    const optId  = ( c ) => ( id ? `${ id }-opt-${ c.code }` : undefined );

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

    const countries = useMemo( () => localizedCountries(), [] );

    const matches = useMemo( () => {
        const q = query.trim().toLowerCase();
        if ( q === '' ) return countries.slice( 0, 50 );

        // Their own language first, then English and the code, so a donor who
        // knows the form as a translation and one who knows the ISO list both
        // find their country.
        return countries.filter( ( c ) => (
            c.label.toLowerCase().includes( q )
            || c.name.toLowerCase().includes( q )
            || c.code.toLowerCase().includes( q )
        ) ).slice( 0, 50 );
    }, [ query, countries ] );

    useEffect( () => { setActive( 0 ); }, [ query ] );

    const pick = ( c ) => {
        onChange && onChange( c.code );
        setQuery( '' );
        setOpen( false );
    };

    const onKeyDown = ( e ) => {
        if ( ! open ) {
            // Tab lands here without asking for the list, so an arrow is the
            // keyboard donor's way in.
            if ( e.key === 'ArrowDown' || e.key === 'ArrowUp' ) {
                e.preventDefault();
                setOpen( true );
            }
            return;
        }
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
        // eslint-disable-next-line jsx-a11y/no-static-element-interactions -- presentational wrapper; the combobox is the input inside. onMouseDown only prevents focus theft.
        <div
            ref={ wrapRef }
            class={ `fundkit-form__country-select${ open ? ' is-open' : '' }` }
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
                class="fundkit-form__country-select-input"
                value={ open ? query : selectedName }
                placeholder={ selectedName || placeholder }
                required={ required }
                aria-invalid={ ariaInvalid || undefined }
                aria-autocomplete="list"
                aria-expanded={ open }
                aria-controls={ open ? listId : undefined }
                aria-activedescendant={ open && matches[ active ] ? optId( matches[ active ] ) : undefined }
                role="combobox"
                onFocus={ () => setQuery( '' ) }
                // The list opens on the donor's own action, never on focus
                // alone: the form focuses whichever field failed validation,
                // and a 240px panel opening there covers the very message that
                // says what went wrong, then eats the next click.
                onClick={ () => { inputRef.current?.focus(); setOpen( true ); } }
                // Leaving the field has to close the list. Without this it stays
                // over the next fields and swallows a click meant for them,
                // while the input keeps showing the half-typed search as though
                // a country had been chosen. Clicking an option does not blur:
                // the wrapper's onMouseDown prevents it.
                onBlur={ () => { setOpen( false ); setQuery( '' ); } }
                onInput={ ( e ) => { setQuery( e.target.value ); if ( ! open ) setOpen( true ); } }
                onKeyDown={ onKeyDown }
            />
            <span class="fundkit-form__country-select-chevron" aria-hidden="true">
                <svg width="10" height="10" viewBox="0 0 10 10" fill="none">
                    <path d="M2 4 L5 7 L8 4" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round" />
                </svg>
            </span>

            { open && matches.length > 0 && (
                <ul id={ listId } class="fundkit-form__country-select-list" role="listbox">
                    { matches.map( ( c, i ) => (
                        // eslint-disable-next-line jsx-a11y/click-events-have-key-events -- option selection is keyboard-driven through the combobox input's onKeyDown; the option is mouse-activated only.
                        <li
                            key={ c.code }
                            id={ optId( c ) }
                            role="option"
                            aria-selected={ i === active }
                            class={ `fundkit-form__country-select-option${ i === active ? ' is-active' : '' }${ c.code === code ? ' is-current' : '' }` }
                            onMouseEnter={ () => setActive( i ) }
                            // The picker sits inside a bare label, which forwards
                            // an uncancelled click on to the input, where it
                            // would reopen the list on top of the pick.
                            onClick={ ( e ) => { e.preventDefault(); pick( c ); } }
                        >
                            <span class="fundkit-form__country-select-label">{ c.label }</span>
                            <span class="fundkit-form__country-select-hint">{ c.code }</span>
                        </li>
                    ) ) }
                </ul>
            ) }
        </div>
    );
}
