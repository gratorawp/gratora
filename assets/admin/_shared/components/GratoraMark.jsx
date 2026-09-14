/** Inline-styled brand mark with size-scaled geometry; no stylesheet required. */

/**
 * The brand's G, cropped to the glyph so it centres in the tile at any size.
 * Drawn rather than loaded, for the same reason the tile is: the mark has to
 * render before any stylesheet or asset request has landed.
 */
const GLYPH_BOX  = '103.4 103.4 811 811';
const GLYPH_PATH = 'M491.1 852.7Q406.8 852.7 345 810.7Q283.3 768.7 249.9 692.4Q216.5 616 216.5 513.9Q216.5 438.7 235.3 375.1Q254.1 311.5 292.9 264.4Q331.6 217.2 390.5 191.1Q449.3 165 529.5 165Q595.1 165 644 182.9Q692.8 200.7 725 233.2Q757.3 265.7 771.7 309.6Q786.1 353.5 783 405.9L647.3 422.7Q648.5 376 633.7 346Q619 315.9 591 300.9Q563.1 285.9 524.1 285.9Q478.3 285.9 443 310.3Q407.6 334.7 387.7 383.8Q367.8 432.9 367.8 507.2Q367.8 562.7 379.4 605.5Q391 648.2 413.1 676.7Q435.3 705.2 467 719.8Q498.6 734.4 537.8 734.4Q580 734.4 609.3 718.9Q638.6 703.4 654.5 675.5Q670.5 647.7 671.5 610H561.5V505.7H801V624.9L801.3 838.9H699.1L702.1 658.2H693.1Q687.1 721 661.8 764.5Q636.5 808 593.5 830.4Q550.5 852.7 491.1 852.7Z';

export default function GratoraMark( { size = 28 } ) {
    const px     = Number( size );
    const radius = Math.max( 4, Math.round( px * 0.22 ) );
    const glyph  = Math.round( px * 0.58 );

    return (
        <span
            className="gratora-mark"
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
                viewBox={ GLYPH_BOX }
                fill="currentColor"
                focusable="false"
            >
                <path d={ GLYPH_PATH } />
            </svg>
        </span>
    );
}
