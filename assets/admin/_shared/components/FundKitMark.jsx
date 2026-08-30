/**
 * Brand chip: gradient square carrying the same F as the admin menu, so the
 * small square contexts all wear one glyph and the directory tile carries the
 * wordmark. Inline-styled
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
                fill="currentColor"
                stroke="none"
            >
                <rect x="4.7" y="2.7" width="3.4" height="19.5" rx="1.7" />
                <rect x="4.7" y="2.7" width="14.6" height="3.4" rx="1.7" />
                <rect x="4.7" y="10.3" width="11.6" height="3.4" rx="1.7" />
            </svg>
        </span>
    );
}
