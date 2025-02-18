/**
 * Green-chip-with-white-D brand mark. Inline-styled so any bundle can render
 * it without a stylesheet. `size` in pixels; radius + font-size scale with it.
 */

const FONT_FAMILY = '-apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif';

export default function DonoMark( { size = 28 } ) {
    const px       = Number( size );
    const radius   = Math.max( 4, Math.round( px * 0.22 ) );
    const fontSize = Math.round( px * 0.5 );

    return (
        <span
            className="dono-mark"
            aria-hidden="true"
            style={ {
                display:         'inline-flex',
                alignItems:      'center',
                justifyContent:  'center',
                width:           `${ px }px`,
                height:          `${ px }px`,
                background:      '#1e8a4e',
                color:           '#fff',
                borderRadius:    `${ radius }px`,
                fontFamily:      FONT_FAMILY,
                fontWeight:      700,
                fontSize:        `${ fontSize }px`,
                letterSpacing:   '-0.01em',
                lineHeight:      1,
                boxShadow:       '0 1px 2px rgba(20, 105, 58, .25)',
                flexShrink:      0,
                userSelect:      'none',
            } }
        >
            D
        </span>
    );
}
