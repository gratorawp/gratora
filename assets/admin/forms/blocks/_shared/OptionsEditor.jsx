import { Button, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

export function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '-' )
        .replace( /^-+|-+$/g, '' );
}

// Field keys are snake_case to match the server (DropdownBlock::slugifySnake)
// and the runtime custom-value keys, so conditions targeting a field resolve.
export function slugifyField( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '_' )
        .replace( /^_+|_+$/g, '' );
}

export function normalizeOptions( raw, fallback ) {
    const fb = Array.isArray( fallback ) && fallback.length > 0
        ? fallback
        : [ { label: 'Option one', value: 'option-one', isDefault: false } ];
    if ( ! Array.isArray( raw ) || raw.length === 0 ) {
        return fb.map( ( o ) => ( {
            label:     String( o?.label ?? '' ),
            value:     String( o?.value ?? slugify( o?.label ?? '' ) ),
            isDefault: !! o?.isDefault,
        } ) );
    }
    const seen = new Set();
    const out  = [];
    raw.forEach( ( o, i ) => {
        if ( ! o || typeof o !== 'object' ) return;
        const labelStr = String( o.label ?? '' );
        let value = String( o.value ?? '' ).trim();
        if ( value === '' ) value = slugify( labelStr ) || `option-${ i + 1 }`;
        if ( seen.has( value ) ) value = `${ value }-${ i + 1 }`;
        seen.add( value );
        out.push( {
            label:     labelStr,
            value,
            isDefault: !! o.isDefault,
        } );
    } );
    return out;
}

/**
 * Inspector-side option-list editor shared by dropdown/radio/checkbox/multi-select.
 * singleDefault enforces one default at a time (radio/dropdown).
 */
export function OptionsEditor( {
    options,
    onChange,
    allowDefault    = true,
    singleDefault   = false,
    addLabel,
} ) {
    const rows = Array.isArray( options ) ? options : [];

    const update = ( i, patch ) => {
        let next = rows.map( ( o, idx ) => idx === i ? { ...o, ...patch } : o );
        if ( singleDefault && patch.isDefault === true ) {
            next = next.map( ( o, idx ) => idx === i ? o : { ...o, isDefault: false } );
        }
        onChange( next );
    };

    const updateLabel = ( i, label ) => {
        const row     = rows[ i ];
        const prior   = String( row?.label ?? '' );
        const derived = slugify( prior );
        const isAuto  = row?.value === derived || row?.value === '' || ! row?.value;
        const patch   = { label };
        if ( isAuto ) {
            patch.value = slugify( label ) || `option-${ i + 1 }`;
        }
        update( i, patch );
    };

    const add = () => {
        const used = new Set( rows.map( ( r ) => r.value ) );
        let n      = rows.length + 1;
        let value  = `option-${ n }`;
        while ( used.has( value ) ) { n++; value = `option-${ n }`; }
        onChange( [ ...rows, { label: '', value, isDefault: false } ] );
    };

    const remove = ( i ) => onChange( rows.filter( ( _, idx ) => idx !== i ) );

    return (
        <div className="dono-options-editor">
            { rows.map( ( o, i ) => (
                <div
                    key={ i }
                    style={ {
                        borderBottom: '1px solid #f0f0f1',
                        paddingBottom: 12,
                        marginBottom:  12,
                    } }
                >
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ o.label }
                        onChange={ ( v ) => updateLabel( i, v ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Value', 'dono' ) }
                        value={ o.value }
                        onChange={ ( v ) => update( i, { value: slugify( v ) || `option-${ i + 1 }` } ) }
                        help={ __( 'Stored when this option is picked. Auto-derived from the label.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    { allowDefault && (
                        <ToggleControl
                            label={ __( 'Default', 'dono' ) }
                            checked={ !! o.isDefault }
                            onChange={ ( v ) => update( i, { isDefault: v } ) }
                            __nextHasNoMarginBottom
                        />
                    ) }
                    <Button
                        variant="tertiary"
                        isDestructive
                        onClick={ () => remove( i ) }
                        disabled={ rows.length <= 1 }
                        style={ { marginTop: 4 } }
                    >
                        { __( 'Remove option', 'dono' ) }
                    </Button>
                </div>
            ) ) }
            <Button variant="secondary" onClick={ add }>
                { addLabel || __( 'Add option', 'dono' ) }
            </Button>
        </div>
    );
}
