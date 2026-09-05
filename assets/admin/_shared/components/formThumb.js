/**
 * What a form template's thumbnail draws, derived from the template's own block
 * markup rather than from a table keyed by id, so a template edited later, or
 * one an add-on registers, draws itself.
 *
 * Kept out of the picker component so the derivation can be tested as data.
 */

// Gutenberg omits any attribute whose value equals its registered default, so
// saved markup routinely carries no presets and no frequencies at all. Derived
// from a block's attributes alone, a four-preset grid reads as empty.
export const DEFAULTS = {
    'fundkit/heading':           { level: 2 },
    'fundkit/donation-amount':   { donationType: 'multi', presets: [ {}, {}, {}, {} ], allowCustom: true },
    'fundkit/recurring-toggle':  { frequencies: [ 'one-time', 'monthly' ], defaultFrequency: 'one-time', style: 'pills' },
    'fundkit/goal':              { showAmount: true, showDonors: true, showDeadline: false },
    'fundkit/fund-picker':       { allowEmpty: false, showDescriptions: true, emptyDescription: '' },
    'fundkit/anonymous-toggle':  { defaultOn: false },
    'fundkit/cover-fees':        { defaultOn: false },
    'fundkit/checkbox':          { defaultOn: false },
    'fundkit/steps':             { progressStyle: 'dots' },
    'fundkit/step':              { showTitle: true, title: '' },
    'fundkit/row':               { columns: 2 },
    'fundkit/columns':           { columns: 2 },
    'fundkit/payment-gateways':  { style: 'cards' },
    'fundkit/currency-switcher': { style: 'dropdown' },
    'fundkit/address':           { showLine1: true, showLine2: true, showCity: true, showRegion: true, showPostal: true, showCountry: true },
    'fundkit/consent':           { purposeKeys: [] },
    'fundkit/terms':             { terms: '', linkUrl: '' },
    'fundkit/section':           { background: '', border: { width: 0 } },
};

const DRAWS_NOTHING = [
    'fundkit/hidden',      // renders no markup at all
    'fundkit/html',        // author's own, and unknowable from here
    'fundkit/donation-summary',
    'fundkit/heading-page', // page blocks have no walker case
];

// What makes a following paragraph small print rather than a lead.
const ANCHORS = [ 'tiles', 'amount', 'goal', 'choices', 'fields' ];

const FIELD_ROW = [
    'fundkit/email', 'fundkit/country', 'fundkit/phone', 'fundkit/date',
    'fundkit/text-input', 'fundkit/number-input',
];

/**
 * A tokenizer rather than wp.blocks.parse: the picker renders on screens where
 * the form blocks are not registered, and parse() of an unregistered block
 * yields core/missing with no attributes.
 */
export function parseBlocks( markup ) {
    const source = String( markup || '' );
    const re = /<!--\s+(\/)?wp:([a-z0-9-]+\/[a-z0-9-]+)\s*(\{[\s\S]*?\})?\s*(\/)?-->/g;
    const root = { children: [] };
    const stack = [ root ];
    let m;

    while ( ( m = re.exec( source ) ) !== null ) {
        const [ , closing, name, json, selfClosing ] = m;

        if ( closing ) {
            if ( stack.length > 1 ) stack.pop();
            continue;
        }

        let attrs = {};
        if ( json ) {
            try { attrs = JSON.parse( json ); } catch ( e ) { attrs = {}; }
        }

        const node = { name, attrs: { ...( DEFAULTS[ name ] || {} ), ...attrs }, children: [] };
        stack[ stack.length - 1 ].children.push( node );
        if ( ! selfClosing ) stack.push( node );
    }

    return root.children;
}

function fieldsFor( node ) {
    const a = node.attrs;

    switch ( node.name ) {
        // views/name.php renders both inputs; requireLast only flags one.
        case 'fundkit/name':
            return [ 'pair' ];

        case 'fundkit/address': {
            const out = [];
            if ( a.showLine1 ) out.push( 'full' );
            if ( a.showLine2 ) out.push( 'full' );
            if ( a.showCity && a.showRegion ) out.push( 'pair' );
            else if ( a.showCity || a.showRegion ) out.push( 'full' );
            if ( a.showPostal && a.showCountry ) out.push( 'pair' );
            else if ( a.showPostal || a.showCountry ) out.push( 'full' );
            return out;
        }

        case 'fundkit/dropdown':
        case 'fundkit/multi-select':
        case 'fundkit/radio':
            return [ 'select' ];

        case 'fundkit/currency-switcher':
            return a.style === 'pills' ? null : [ 'select' ];

        default:
            return FIELD_ROW.includes( node.name ) ? [ 'full' ] : null;
    }
}

function tilesFor( attrs ) {
    // A fixed block renders one amount and no grid: DonationAmountBlock::render
    // empties presets for it. An empty presets array is not the same thing, it
    // falls back to the four defaults.
    if ( attrs.donationType === 'fixed' ) return { kind: 'amount' };

    const presets = Array.isArray( attrs.presets ) && attrs.presets.length
        ? attrs.presets
        : DEFAULTS[ 'fundkit/donation-amount' ].presets;

    const cols   = presets.length > 4 ? 3 : Math.max( 1, presets.length );
    const labels = presets.some( ( p ) => p && String( p.impact || '' ) !== '' );
    // The runtime always selects one: the first preset flagged preselected,
    // else the first preset. "No active tile" is not a state a donor can see.
    const flagged = presets.findIndex( ( p ) => p && p.preselected );

    return {
        kind:   'tiles',
        cols,
        // Two rows is all that reads; a longer preset list is simply not drawn.
        count:  Math.min( presets.length, cols * 2 ),
        labels,
        active: flagged >= 0 ? flagged : 0,
    };
}

function simplePart( node ) {
    const a = node.attrs;

    switch ( node.name ) {
        case 'fundkit/heading':
            return { kind: 'title', small: Number( a.level || 2 ) > 1 };

        // Position decides, not content: prose above the first thing a donor
        // fills in is a lead, prose below it is the small print.
        case 'fundkit/paragraph':
            return { kind: 'text' };

        case 'fundkit/privacy-notice':
            return { kind: 'fine-print' };

        case 'fundkit/divider':
            return { kind: 'rule' };

        case 'fundkit/goal':
            return {
                kind:    'goal',
                figures: ( a.showAmount ? 1 : 0 ) + ( a.showDonors ? 1 : 0 ),
                pip:     !! a.showDeadline,
            };

        case 'fundkit/recurring-toggle': {
            const list = Array.isArray( a.frequencies ) && a.frequencies.length
                ? a.frequencies
                : DEFAULTS[ 'fundkit/recurring-toggle' ].frequencies;
            // The walker prepends one-time when the block omits it, which is
            // what separates a sustainer form from an everyday one.
            const all = list.includes( 'one-time' ) ? list : [ 'one-time', ...list ];
            const on  = Math.max( 0, all.indexOf( a.defaultFrequency || 'one-time' ) );
            return { kind: 'pills', count: all.length, on, joined: a.style === 'tabs' };
        }

        case 'fundkit/currency-switcher':
            return a.style === 'pills' ? { kind: 'pills', count: 2, on: 0 } : null;

        case 'fundkit/fund-picker':
            return {
                kind: 'choices',
                count: 3,
                on:   a.allowEmpty ? 0 : -1,
                sub:  !! ( a.allowEmpty && a.showDescriptions && a.emptyDescription ),
            };

        case 'fundkit/comment':
            return { kind: 'textarea' };

        case 'fundkit/anonymous-toggle':
        case 'fundkit/cover-fees':
        case 'fundkit/checkbox':
            return { kind: 'check', count: 1, on: !! a.defaultOn };

        // Renders nothing for donors until it is configured.
        case 'fundkit/consent':
            return ( Array.isArray( a.purposeKeys ) ? a.purposeKeys.length : 0 )
                ? { kind: 'check', count: Math.min( a.purposeKeys.length, 2 ), on: false }
                : null;

        case 'fundkit/terms':
            return ( a.terms || a.linkUrl ) ? { kind: 'check', count: 1, on: false } : null;

        default:
            return undefined;
    }
}

function walk( nodes, ctx ) {
    const out = [];

    for ( const node of nodes ) {
        if ( DRAWS_NOTHING.includes( node.name ) ) continue;

        // Emitted where it is authored: the walker flushes it to sheet level,
        // which takes it out of any container, not out of document order.
        if ( node.name === 'fundkit/donation-amount' ) { out.push( tilesFor( node.attrs ) ); ctx.anchored = true; continue; }
        if ( node.name === 'fundkit/submit-button' )   { ctx.hasSubmit = true; continue; }
        if ( node.name === 'fundkit/payment-gateways' ) { ctx.chips = node.attrs.style === 'cards' ? 3 : 0; continue; }

        if ( node.name === 'fundkit/steps' ) { ctx.steps = node; continue; }

        if ( node.name === 'fundkit/section' ) {
            const inner = walk( node.children, ctx );
            if ( ! inner.length ) continue;
            const framed = String( node.attrs.background || '' ) !== ''
                || Number( node.attrs.border?.width || 0 ) > 0;
            if ( ! framed ) { out.push( ...inner ); continue; }
            // An amount block is flushed to sheet level, so a frame cannot hold one.
            const loose  = inner.filter( ( p ) => p.kind === 'tiles' || p.kind === 'amount' );
            const framedParts = inner.filter( ( p ) => p.kind !== 'tiles' && p.kind !== 'amount' );
            out.push( ...loose );
            if ( framedParts.length ) out.push( { kind: 'panel', children: framedParts } );
            continue;
        }

        if ( node.name === 'fundkit/row' || node.name === 'fundkit/columns' ) {
            const declared = Number( node.attrs.columns );
            const max = node.name === 'fundkit/row' ? 4 : 6;
            // Both blocks RESET an out-of-range count to 2 rather than clamping.
            const real = declared >= 1 && declared <= max ? declared : 2;
            const inner = walk( node.children, ctx );
            // Only field-derived children lay out in columns; the walker tags a
            // decoration with the row but still draws it full width.
            const cells = inner.filter( ( p ) => p.kind === 'fields' );
            const flat  = inner.filter( ( p ) => p.kind !== 'fields' );
            if ( cells.length > 1 && real > 1 ) {
                // A field that bubbles out of the grid comes out ahead of it.
                out.push( { kind: 'cols', cols: Math.min( real, 3 ), children: cells }, ...flat );
            } else {
                out.push( ...cells, ...flat );
            }
            continue;
        }

        const asFields = fieldsFor( node );
        if ( asFields ) { out.push( { kind: 'fields', rows: asFields } ); ctx.anchored = true; continue; }

        const part = simplePart( node );
        if ( part === null ) continue;
        if ( part ) {
            out.push( part.kind === 'text' && ctx.anchored ? { kind: 'fine-print' } : part );
            if ( ANCHORS.includes( part.kind ) ) ctx.anchored = true;
            continue;
        }

        // Anything else, including a block an add-on registered: one input row,
        // and a run of them collapses rather than drawing a wall of grey.
        const last = out[ out.length - 1 ];
        if ( last && last.kind === 'fields' && last.unknown ) last.rows.push( 'full' );
        else out.push( { kind: 'fields', rows: [ 'full' ], unknown: true } );
    }

    return out.filter( Boolean );
}

const NOMINAL = {
    title: ( p ) => ( p.small ? 5 : 6 ),
    text: () => 6,
    'fine-print': () => 6,
    rule: () => 1,
    pills: () => 8,
    goal: () => 11,
    tiles: ( p ) => {
        const r = Math.ceil( p.count / p.cols );
        return r * ( p.labels ? 15 : 11 ) + ( r - 1 ) * 3;
    },
    amount: () => 12,
    choices: ( p ) => p.count * 8 + ( p.on >= 0 ? 3 : 0 ) + ( p.count - 1 ) * 3,
    fields: ( p ) => p.rows.length * 6 + ( p.rows.length - 1 ) * 3,
    textarea: () => 14,
    check: ( p ) => p.count * 5 + ( p.count - 1 ) * 3,
    panel: ( p ) => height( p.children ) + 8,
    cols: ( p ) => Math.max( ...p.children.map( ( c ) => NOMINAL.fields( c ) ) ),
    checkout: () => 18,
    advance: () => 9,
    ghost: ( p ) => p.count * 4 + ( p.count - 1 ) * 2,
};

function height( parts ) {
    return parts.reduce( ( sum, p ) => sum + ( NOMINAL[ p.kind ] || ( () => 6 ) )( p ), 0 )
        + Math.max( 0, parts.length - 1 ) * 4;
}

// A 220px grid column, less the card border, gives a 218px tile; at 4/3 that is
// 163px of thumb, less its bottom border, its 28px of padding and the sheet's
// 16px, which leaves this for the bands.
const BUDGET = 118;
const LEGIBLE = 7;

const DROP_RANK = [ 'check', 'rule', 'fine-print', 'text', 'textarea', 'ghost', 'fields', 'title', 'panel', 'cols', 'pills', 'choices', 'goal' ];

function merge( parts ) {
    const out = [];
    let seenTitle = false;

    for ( let part of parts ) {
        // Two grey stacks in different places read as one stack drawn twice, so
        // they cost two bands and buy nothing.
        if ( part.kind === 'fields' ) {
            const first = out.find( ( p ) => p.kind === 'fields' );
            if ( first ) { first.rows = [ ...first.rows, ...part.rows ].slice( 0, 4 ); continue; }
        }
        if ( part.kind === 'text' || part.kind === 'fine-print' ) {
            if ( out.some( ( p ) => p.kind === part.kind ) ) continue;
        }
        if ( part.kind === 'check' ) {
            const first = out.find( ( p ) => p.kind === 'check' );
            if ( first ) { first.count = Math.min( first.count + part.count, 2 ); continue; }
        }
        if ( part.kind === 'title' ) {
            if ( seenTitle && out.some( ( p ) => p.kind === 'title' && p.small ) ) continue;
            if ( seenTitle ) part = { ...part, small: true };
            seenTitle = true;
        }
        out.push( part.kind === 'fields' || part.kind === 'check' ? { ...part, rows: part.rows ? [ ...part.rows ] : undefined } : part );
    }

    return out;
}

function truncate( parts ) {
    const list = [ ...parts ];

    while ( height( list ) > BUDGET || list.length > LEGIBLE ) {
        const droppable = list
            .map( ( p, i ) => ( { p, i } ) )
            .filter( ( { p, i } ) =>
                i !== 0
                && i !== list.length - 1
                && p.kind !== 'tiles'
                && p.kind !== 'amount'
                && DROP_RANK.includes( p.kind ) );

        // Nothing left to give: the sheet is what it is rather than looping.
        if ( ! droppable.length ) break;

        droppable.sort( ( a, b ) =>
            ( DROP_RANK.indexOf( a.p.kind ) - DROP_RANK.indexOf( b.p.kind ) ) || ( b.i - a.i ) );
        list.splice( droppable[ 0 ].i, 1 );
    }

    return list;
}

/**
 * The drawn shape of one template.
 *
 * @param {Object} template A form template, carrying its block markup.
 * @return {Object} { parts, chrome, steps, wizard }
 */
export function thumbFor( template ) {
    const markup = String( template?.blocks || '' );
    if ( ! markup.trim() ) return { parts: [ { kind: 'empty' } ], chrome: null, steps: 0, wizard: false };

    const ctx = { chips: 0, hasSubmit: false, steps: null, anchored: false };
    const tree = parseBlocks( markup );
    const preamble = walk( tree, ctx );

    let body = preamble;
    let stepCount = 0;
    let chrome = null;

    if ( ctx.steps ) {
        const steps = ctx.steps.children.filter( ( c ) => c.name === 'fundkit/step' );
        stepCount = steps.length;
        const style = ctx.steps.attrs.progressStyle || 'dots';
        chrome = style === 'none' ? null : style;

        const first = steps[ 0 ];
        if ( first ) {
            const inner = walk( first.children, ctx );
            const heading = first.attrs.showTitle && first.attrs.title
                ? [ { kind: 'title', small: true } ]
                : [];
            body = [ ...preamble, ...heading, ...inner ];
        }
        // The pages behind this one are the whole point of a wizard, and a dot
        // strip alone left the card the shortest in the grid.
        if ( stepCount > 1 ) body = [ ...body, { kind: 'ghost', count: Math.min( stepCount - 1, 2 ) } ];
    }

    // The walker appends a submit button when the markup has none, so every
    // form ends in something to press.
    body.push( ctx.steps ? { kind: 'advance' } : { kind: 'checkout', chips: ctx.chips } );

    return {
        parts:  truncate( merge( body ) ),
        chrome,
        steps:  stepCount,
        wizard: !! ctx.steps,
    };
}
