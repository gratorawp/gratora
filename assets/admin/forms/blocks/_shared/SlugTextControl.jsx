import { TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * Preserve trailing separators while typing; commit the strict slug matching PHP field
 * derivation.
 */
export function SlugTextControl( { value, onChange, separator = '_', fallback = '', ...rest } ) {
    const [ draft, setDraft ] = useState( null );

    const leading  = separator === '_' ? /^_+/ : /^-+/;
    const trailing = separator === '_' ? /_+$/ : /-+$/;

    const loose  = ( v ) => String( v || '' ).toLowerCase().replace( /[^a-z0-9]+/g, separator ).replace( leading, '' );
    const strict = ( v ) => loose( v ).replace( trailing, '' );

    return (
        <TextControl
            { ...rest }
            value={ draft ?? value }
            onChange={ ( v ) => {
                setDraft( loose( v ) );
                onChange( strict( v ) || fallback );
            } }
            onBlur={ () => setDraft( null ) }
        />
    );
}
