import Base, { resolveEffectiveTokens } from '@fundkit/ui/styling/StylePreview';

/**
 * The preview's stylesheet reads --fundkit-font-family, --fundkit-font-size and
 * --fundkit-border-width. The catalogue emits --fundkit-typeface,
 * --fundkit-type-size and --fundkit-stroke, and nothing defines the first three,
 * so without this the Typography and Border width controls move nothing in the
 * preview and an admin cannot tell whether their choice took.
 *
 * display:contents so the alias carries by inheritance without a box.
 */
const ALIASES = {
    '--fundkit-font-family':  'fundkit-typeface',
    '--fundkit-font-size':    'fundkit-type-size',
    '--fundkit-border-width': 'fundkit-stroke',
};

export function aliasStyle( props ) {
    const effective = resolveEffectiveTokens( props ) || {};
    const style = { display: 'contents' };

    for ( const cssVar in ALIASES ) {
        const value = effective[ ALIASES[ cssVar ] ];
        if ( typeof value === 'string' && value !== '' ) style[ cssVar ] = value;
    }

    return style;
}

export default function StylePreview( props ) {
    return (
        <div style={ aliasStyle( props ) }>
            <Base { ...props } />
        </div>
    );
}

export { resolveEffectiveTokens };
