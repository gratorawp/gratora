/** Use the generated FormTemplates.php fixture; FormTemplateThumbFixtureTest checks for drift. */

import fs from 'fs';
import path from 'path';

import { thumbFor, parseBlocks, DEFAULTS } from '../../assets/admin/_shared/components/formThumb';

const TEMPLATES = JSON.parse(
	fs.readFileSync( path.join( __dirname, 'fixtures/form-templates.json' ), 'utf8' )
);

const kinds = ( t ) => thumbFor( t ).parts.map( ( p ) => p.kind );

// Everything a viewer can actually tell apart, so two shapes that differ only
// in a field nobody draws are not counted as different.
const signature = ( t ) => {
	const shape = thumbFor( t );
	return JSON.stringify( [
		shape.chrome,
		shape.parts.map( ( p ) => {
			switch ( p.kind ) {
				case 'title':    return `title${ p.small ? '.sm' : '' }`;
				case 'tiles':    return `tiles.${ p.cols }.${ Math.ceil( p.count / p.cols ) }.${ p.labels ? 'lab' : 'plain' }.${ p.active }`;
				case 'pills':    return `pills.${ p.count }.${ p.on }`;
				case 'goal':     return `goal.${ p.figures }.${ p.pip }`;
				case 'choices':  return `choices.${ p.count }.${ p.on }.${ p.sub }`;
				case 'fields':   return `fields.${ p.rows.join( '' ) }`;
				case 'check':    return `check.${ p.count }.${ p.on }`;
				case 'checkout': return `checkout.${ p.chips }`;
				default:         return p.kind;
			}
		} ),
	] );
};

const template = ( id ) => ( { id, blocks: TEMPLATES[ id ], settings: {} } );

describe( 'every template draws itself', () => {
	test( 'the fixture holds the templates this suite is about', () => {
		expect( Object.keys( TEMPLATES ) ).toEqual( expect.arrayContaining( [
			'blank', 'quick-give', 'everyday', 'guided', 'monthly-sustainer',
			'emergency-appeal', 'designated', 'campaign-page', 'impact-tiers',
		] ) );
	} );

	test( 'no two of them draw the same picture', () => {
		const seen = new Map();

		for ( const id of Object.keys( TEMPLATES ) ) {
			const sig = signature( template( id ) );
			expect( seen.has( sig ) ? [ seen.get( sig ), id ] : [] ).toEqual( [] );
			seen.set( sig, id );
		}

		expect( seen.size ).toBe( Object.keys( TEMPLATES ).length );
	} );

	test( 'a template with no blocks is drawn as unbuilt, not as an empty form', () => {
		expect( kinds( template( 'blank' ) ) ).toEqual( [ 'empty' ] );
	} );

	test( 'the parts follow the order the blocks are authored in', () => {
		// designated offers the fund before the amount; everyday the reverse.
		const d = kinds( template( 'designated' ) );
		expect( d.indexOf( 'choices' ) ).toBeLessThan( d.indexOf( 'tiles' ) );

		const e = kinds( template( 'everyday' ) );
		expect( e.indexOf( 'tiles' ) ).toBeLessThan( e.indexOf( 'pills' ) );
	} );

	test( 'every sheet ends in something to press', () => {
		for ( const id of Object.keys( TEMPLATES ) ) {
			if ( id === 'blank' ) continue;
			const parts = kinds( template( id ) );
			expect( [ 'checkout', 'advance' ] ).toContain( parts[ parts.length - 1 ] );
		}
	} );
} );

describe( 'the derivation reads the blocks the way the runtime does', () => {
	const shapeOf = ( blocks ) => thumbFor( { blocks } );

	test( 'an amount block saved with no attributes still draws its four presets', () => {
		// Gutenberg omits any attribute equal to its registered default, so the
		// saved markup of a stock amount block carries no presets at all.
		const tiles = shapeOf( '<!-- wp:fundkit/donation-amount /-->' ).parts
			.find( ( p ) => p.kind === 'tiles' );

		expect( tiles ).toBeTruthy();
		expect( tiles.count ).toBe( 4 );
	} );

	test( 'a fixed-amount block draws one amount, not a grid', () => {
		const parts = shapeOf( '<!-- wp:fundkit/donation-amount {"donationType":"fixed"} /-->' ).parts;

		expect( parts.map( ( p ) => p.kind ) ).toContain( 'amount' );
		expect( parts.map( ( p ) => p.kind ) ).not.toContain( 'tiles' );
	} );

	test( 'an explicitly empty presets array is not a fixed amount', () => {
		// DonationAmountBlock substitutes its four defaults for an empty array.
		const parts = shapeOf( '<!-- wp:fundkit/donation-amount {"presets":[]} /-->' ).parts;

		expect( parts.map( ( p ) => p.kind ) ).toContain( 'tiles' );
	} );

	test( 'the runtime always highlights a tile, so the thumbnail does too', () => {
		const tiles = shapeOf( '<!-- wp:fundkit/donation-amount /-->' ).parts
			.find( ( p ) => p.kind === 'tiles' );

		expect( tiles.active ).toBe( 0 );
	} );

	test( 'blocks that render nothing draw nothing', () => {
		const parts = shapeOf(
			'<!-- wp:fundkit/hidden {"field":"utm"} /-->'
			+ '<!-- wp:fundkit/hidden {"field":"src"} /-->'
			+ '<!-- wp:fundkit/consent /-->'
			+ '<!-- wp:fundkit/terms /-->'
		).parts;

		expect( parts.map( ( p ) => p.kind ) ).toEqual( [ 'checkout' ] );
	} );

	test( 'a divider is a rule, never an input row', () => {
		const parts = shapeOf( '<!-- wp:fundkit/divider /-->' ).parts;

		expect( parts.map( ( p ) => p.kind ) ).toContain( 'rule' );
		expect( parts.map( ( p ) => p.kind ) ).not.toContain( 'fields' );
	} );

	test( 'a section is walked through rather than drawn as one grey row', () => {
		const wrapped = shapeOf(
			'<!-- wp:fundkit/section --><!-- wp:fundkit/email /--><!-- wp:fundkit/phone /--><!-- /wp:fundkit/section -->'
		).parts;

		const fields = wrapped.find( ( p ) => p.kind === 'fields' );
		expect( fields ).toBeTruthy();
		expect( fields.rows ).toHaveLength( 2 );
	} );

	test( 'a row lays its fields out side by side', () => {
		const cols = shapeOf(
			'<!-- wp:fundkit/row {"columns":2} --><!-- wp:fundkit/email /--><!-- wp:fundkit/phone /--><!-- /wp:fundkit/row -->'
		).parts.find( ( p ) => p.kind === 'cols' );

		expect( cols ).toBeTruthy();
		expect( cols.cols ).toBe( 2 );
		expect( cols.children ).toHaveLength( 2 );
	} );

	test( 'an out-of-range column count resets to two, matching the block', () => {
		const cols = shapeOf(
			'<!-- wp:fundkit/row {"columns":9} --><!-- wp:fundkit/email /--><!-- wp:fundkit/phone /--><!-- /wp:fundkit/row -->'
		).parts.find( ( p ) => p.kind === 'cols' );

		expect( cols.cols ).toBe( 2 );
	} );

	test( 'a wizard ends in an advance bar even though its submit sits in a later step', () => {
		// FormTemplates::guided() puts the submit button in the third step, and
		// only the first step is drawn.
		const shape = thumbFor( template( 'guided' ) );

		expect( shape.wizard ).toBe( true );
		expect( shape.chrome ).toBe( 'dots' );
		expect( shape.steps ).toBe( 3 );
		expect( shape.parts[ shape.parts.length - 1 ].kind ).toBe( 'advance' );
	} );

	test( 'a wizard that shows no progress indicator is not given one', () => {
		const shape = shapeOf(
			'<!-- wp:fundkit/steps {"progressStyle":"none"} --><!-- wp:fundkit/step --><!-- wp:fundkit/email /--><!-- /wp:fundkit/step --><!-- /wp:fundkit/steps -->'
		);

		expect( shape.chrome ).toBeNull();
		expect( shape.wizard ).toBe( true );
	} );

	test( 'trailing prose is small print, leading prose is not', () => {
		const parts = shapeOf(
			'<!-- wp:fundkit/paragraph /--><!-- wp:fundkit/email /--><!-- wp:fundkit/paragraph /-->'
		).parts.map( ( p ) => p.kind );

		expect( parts ).toContain( 'text' );
		expect( parts ).toContain( 'fine-print' );
		expect( parts.indexOf( 'text' ) ).toBeLessThan( parts.indexOf( 'fine-print' ) );
	} );

	test( 'a block nobody has heard of gets a row, and a run of them does not become a wall', () => {
		const parts = shapeOf(
			'<!-- wp:acme/tribute /--><!-- wp:acme/memorial /--><!-- wp:acme/dedication /-->'
		).parts;

		const fields = parts.filter( ( p ) => p.kind === 'fields' );
		expect( fields ).toHaveLength( 1 );
		expect( fields[ 0 ].rows.length ).toBeLessThanOrEqual( 4 );
	} );

	test( 'a very long form is cut down rather than overflowing the sheet', () => {
		// Kinds that do not merge into one another, so only the drop pass can
		// bring this back inside the sheet.
		const long = Array.from( { length: 8 }, () =>
			'<!-- wp:fundkit/goal /--><!-- wp:fundkit/divider /-->'
			+ '<!-- wp:fundkit/comment /--><!-- wp:fundkit/fund-picker /-->'
		).join( '' );

		const parts = shapeOf( '<!-- wp:fundkit/donation-amount /-->' + long ).parts;

		expect( parts.length ).toBeLessThanOrEqual( 7 );
		// What the sheet is about survives the cut.
		expect( parts.map( ( p ) => p.kind ) ).toContain( 'tiles' );
		expect( parts[ parts.length - 1 ].kind ).toBe( 'checkout' );
	} );

	test( 'a form of nothing but undroppable parts still terminates', () => {
		const tiles = Array.from( { length: 30 }, () => '<!-- wp:fundkit/donation-amount /-->' ).join( '' );

		expect( () => shapeOf( tiles ) ).not.toThrow();
		expect( shapeOf( tiles ).parts.length ).toBeGreaterThan( 0 );
	} );
} );

describe( 'the markup tokenizer', () => {
	test( 'it merges parsed attributes over the registered defaults', () => {
		const [ block ] = parseBlocks( '<!-- wp:fundkit/goal {"showDeadline":true} /-->' );

		expect( block.attrs.showDeadline ).toBe( true );
		expect( block.attrs.showAmount ).toBe( DEFAULTS[ 'fundkit/goal' ].showAmount );
	} );

	test( 'it nests paired blocks and closes them', () => {
		const [ steps ] = parseBlocks(
			'<!-- wp:fundkit/steps --><!-- wp:fundkit/step --><!-- wp:fundkit/email /--><!-- /wp:fundkit/step --><!-- /wp:fundkit/steps -->'
		);

		expect( steps.name ).toBe( 'fundkit/steps' );
		expect( steps.children ).toHaveLength( 1 );
		expect( steps.children[ 0 ].children[ 0 ].name ).toBe( 'fundkit/email' );
	} );

	test( 'malformed attributes do not take the whole picker down', () => {
		expect( () => parseBlocks( '<!-- wp:fundkit/goal {not json} /-->' ) ).not.toThrow();
	} );
} );
