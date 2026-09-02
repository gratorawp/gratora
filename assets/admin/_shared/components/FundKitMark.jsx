/**
 * Brand chip: gradient square carrying the same heart the admin menu shows, so
 * the small square contexts and the sidebar wear one glyph.
 *
 * Stroked rather than filled, which the sidebar icon cannot be: WordPress
 * recolours that one by rewriting every fill in its markup, so it has to be
 * solid shapes. This one is inline SVG in the page and keeps lucide's stroke.
 *
 * Inline-styled so any bundle can render it without a stylesheet. `size` is in
 * pixels; the radius and the glyph scale with it.
 */

import { Heart } from 'lucide-react';

export default function FundKitMark( { size = 28 } ) {
    const px     = Number( size );
    const radius = Math.max( 4, Math.round( px * 0.22 ) );
    const glyph  = Math.round( px * 0.58 );

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
            <Heart size={ glyph } strokeWidth={ 2 } />
        </span>
    );
}
