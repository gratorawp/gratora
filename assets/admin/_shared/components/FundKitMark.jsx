/**
 * Brand chip: gradient square with the white double-wave mark. Inline-styled
 * so any bundle can render it without a stylesheet. `size` in pixels; radius
 * and glyph scale with it.
 */

export default function FundKitMark( { size = 28 } ) {
    const px     = Number( size );
    const radius = Math.max( 4, Math.round( px * 0.22 ) );
    const glyph  = Math.round( px * 0.62 );

    return (
        <span
            className="fundkit-mark"
            aria-hidden="true"
            style={ {
                display:         'inline-flex',
                alignItems:      'center',
                justifyContent:  'center',
                width:           `${ px }px`,
                height:          `${ px }px`,
                background:      'linear-gradient(135deg, #f96a4c 0%, #c65ba8 55%, #8a7bff 100%)',
                color:           '#fff',
                borderRadius:    `${ radius }px`,
                boxShadow:       '0 1px 2px rgba(33, 29, 63, .25)',
                flexShrink:      0,
                userSelect:      'none',
            } }
        >
            <svg
                width={ glyph }
                height={ glyph }
                viewBox="0 0 24 24"
                fill="none"
                stroke="currentColor"
                strokeWidth="2.4"
                strokeLinecap="round"
            >
                <path d="M3 9.5c3-3 6-3 9 0s6 3 9 0" />
                <path d="M3 15.5c3-3 6-3 9 0s6 3 9 0" />
            </svg>
        </span>
    );
}
