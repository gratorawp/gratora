// Nav arrows are content, so the RTL stylesheet cannot flip them: rtlcss
// rewrites direction selectors when it builds the RTL bundle, which would fire
// the rule in exactly the wrong direction. Read at call time, because a
// document's dir can be set after this module is parsed.
export const forwardGlyph = () => ( document.documentElement.dir === 'rtl' ? '←' : '→' );
export const backGlyph    = () => ( document.documentElement.dir === 'rtl' ? '→' : '←' );
