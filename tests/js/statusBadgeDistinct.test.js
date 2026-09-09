/**
 * A status pill is scanned down a column, not read one at a time. Draft and
 * archived both rendered gray, so the only thing separating a campaign that has
 * never opened from one the org is finished with was the word inside the pill,
 * and a list of mostly-archived campaigns read as one undifferentiated block.
 */
import StatusBadge from '../../assets/admin/_shared/components/StatusBadge.jsx';

// The component is one element deep, so its own return value is the whole
// answer: rendering it would only add a renderer this assertion does not need.
const badge = ( status ) => {
	const el = StatusBadge( { status } );
	const cls = el.props.className;

	return {
		variant: ( cls.match( /gratora-pill--(\w+)/ ) || [] )[ 1 ],
		label:   el.props.children,
	};
};

test( 'the three campaign states are three different colours', () => {
	const variants = [ 'draft', 'published', 'archived' ].map( ( s ) => badge( s ).variant );

	expect( variants.every( Boolean ) ).toBe( true );
	expect( new Set( variants ) ).toHaveProperty( 'size', 3 );
} );

test( 'each of them still says which it is, so colour is never the only signal', () => {
	expect( badge( 'draft' ).label ).toBe( 'Draft' );
	expect( badge( 'published' ).label ).toBe( 'Active' );
	expect( badge( 'archived' ).label ).toBe( 'Archived' );
} );

test( 'an unknown status renders neutral rather than rendering nothing', () => {
	expect( badge( 'something_new' ) ).toEqual( { variant: 'gray', label: 'something new' } );
} );
