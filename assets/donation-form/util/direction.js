// The arrow is content, not a physical property, so the RTL stylesheet cannot
// flip it: rtlcss rewrites direction selectors when it builds runtime-rtl.css,
// which would fire the rule in exactly the wrong direction. Read at call time,
// because a document's dir can be set after this module is parsed.
export const backGlyph = () => ( document.documentElement.dir === 'rtl' ? '→' : '←' );
