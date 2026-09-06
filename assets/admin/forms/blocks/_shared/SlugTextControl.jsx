import { TextControl } from '@wordpress/components';
import { useState } from '@wordpress/element';

/**
 * A slug input that keeps the separator you just typed.
 *
 * Slugifying on every keystroke strips a trailing separator, and feeding that
 * straight back into a controlled input deletes it under the caret: "first_"
 * became "first", so a snake_case key could only ever be pasted. The committed
 * value stays strictly slugified, because the editor's key has to equal
 * DropdownBlock::deriveField or conditions built on it stop matching.
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
