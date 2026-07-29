import { PanelBody, SelectControl, TextControl } from '@wordpress/components';
import { useSelect } from '@wordpress/data';
import { applyFilters } from '@wordpress/hooks';
import { __, sprintf } from '@wordpress/i18n';

export const OP_OPTIONS = [
    { value: '=', label: __( 'equals', 'dono' ) },
    { value: '!=', label: __( 'does not equal', 'dono' ) },
    { value: '>', label: __( 'greater than', 'dono' ) },
    { value: '>=', label: __( 'greater than or equal', 'dono' ) },
    { value: '<', label: __( 'less than', 'dono' ) },
    { value: '<=', label: __( 'less than or equal', 'dono' ) },
    { value: 'contains', label: __( 'contains', 'dono' ) },
];

export const DEFAULT_CONDITION = { field: '', op: '=', value: '' };

// Built-in donor inputs whose value the runtime exposes at a fixed key.
// Offered as a condition source only when that block is in the form.
const BUILTIN_SOURCES = {
    'dono/donation-amount':  { value: 'amount_cents', label: __( 'Amount (cents)', 'dono' ) },
    'dono/recurring-toggle': { value: 'frequency',    label: __( 'Frequency', 'dono' ) },
    'dono/anonymous-toggle': { value: 'is_anonymous', label: __( 'Is anonymous', 'dono' ) },
    'dono/cover-fees':       { value: 'cover_fees',   label: __( 'Cover fees', 'dono' ) },
};

// Custom-input blocks: the donor runtime stores their value at
// values.custom[field], so the condition path is `custom.<field>`.
const CUSTOM_FIELD_BLOCKS = new Set( [
    'dono/text-input',
    'dono/number-input',
    'dono/date',
    'dono/dropdown',
    'dono/radio',
    'dono/checkbox',
    'dono/multi-select',
    'dono/hidden',
] );

// Kept for backwards-compatible imports; the live list is computed per-render
// in ConditionPanel from the blocks actually in the editor.
export const FIELD_OPTIONS = [ { value: '', label: __( '(Always show)', 'dono' ) } ];

function flatten( blocks, out ) {
    for ( const b of blocks || [] ) {
        if ( ! b ) continue;
        out.push( b );
        if ( b.innerBlocks && b.innerBlocks.length ) flatten( b.innerBlocks, out );
    }
    return out;
}

export function ConditionPanel( { condition, onChange, title } ) {
    const c = { ...DEFAULT_CONDITION, ...( condition || {} ) };
    const set = ( patch ) => onChange( { ...c, ...patch } );

    const options = useSelect( ( select ) => {
        const be     = select( 'core/block-editor' );
        const selfId = be.getSelectedBlockClientId();
        const all    = flatten( be.getBlocks(), [] );

        // A donor field an add-on contributes exposes its value at a fixed
        // key too, so it can be a condition source like any built-in.
        const sources = applyFilters( 'dono.editor.conditionSources', BUILTIN_SOURCES );

        const opts = [ { value: '', label: __( '(Always show)', 'dono' ) } ];
        const seen = new Set( [ '' ] );

        for ( const b of all ) {
            if ( b.clientId === selfId ) continue;

            const builtin = sources[ b.name ];
            if ( builtin ) {
                if ( ! seen.has( builtin.value ) ) {
                    opts.push( builtin );
                    seen.add( builtin.value );
                }
                continue;
            }

            if ( ! CUSTOM_FIELD_BLOCKS.has( b.name ) ) continue;
            const slug = String( b.attributes?.field || '' ).trim();
            if ( ! slug ) continue;
            const value = `custom.${ slug }`;
            if ( seen.has( value ) ) continue;
            const lbl = String( b.attributes?.label || '' ).trim();
            opts.push( { value, label: lbl ? `${ lbl } (${ slug })` : slug } );
            seen.add( value );
        }

        // A stored field that no longer exists in the form: surface it so it
        // can be seen and fixed rather than silently blanking the select.
        if ( c.field && ! seen.has( c.field ) ) {
            opts.push( {
                value: c.field,
                /* translators: %s: stored condition field key that is no longer in the form. */
                label: sprintf( __( '%s (not in form)', 'dono' ), c.field ),
            } );
        }
        return opts;
    }, [ c.field ] );

    return (
        <PanelBody title={ title || __( 'Conditional logic', 'dono' ) } initialOpen={ false }>
            <SelectControl
                label={ __( 'Show this when', 'dono' ) }
                value={ c.field }
                options={ options }
                onChange={ ( v ) => set( { field: v } ) }
                help={ __( 'Only fields already added to this form can be used.', 'dono' ) }
                __nextHasNoMarginBottom
            />
            { c.field && (
                <>
                    <SelectControl
                        label={ __( 'Operator', 'dono' ) }
                        value={ c.op }
                        options={ OP_OPTIONS }
                        onChange={ ( v ) => set( { op: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Value', 'dono' ) }
                        value={ c.value }
                        onChange={ ( v ) => set( { value: v } ) }
                        help={ __( 'For amount, use cents (e.g. 5000 = $50).', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                </>
            ) }
        </PanelBody>
    );
}
