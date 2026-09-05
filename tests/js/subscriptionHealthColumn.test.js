/**
 * The column was the only health signal in the list and read only
 * failed_renewals_count, so a plan carrying unresolved operational errors
 * reported OK. A declined renewal and a failed operation are different facts
 * and it names whichever it has.
 */

import fs from 'fs';
import path from 'path';

const source = fs.readFileSync(
	path.join( __dirname, '../../assets/admin/subscriptions/List.jsx' ),
	'utf8'
);

const column = source.slice(
	source.indexOf( "id:       'failing'" ),
	source.indexOf( "id:       'interval'" )
);

test( 'OK is said only when there is neither a failure nor a problem', () => {
	const okAt = column.indexOf( "__( 'OK'" );
	const failuresAt = column.indexOf( 'failed_renewals_count > 0' );
	const problemsAt = column.indexOf( 'problems > 0' );

	expect( failuresAt ).toBeGreaterThan( -1 );
	expect( problemsAt ).toBeGreaterThan( -1 );
	expect( okAt ).toBeGreaterThan( failuresAt );
	expect( okAt ).toBeGreaterThan( problemsAt );
} );

test( 'it counts the errors the row already carries', () => {
	expect( column ).toContain( 'item.errors?.length' );
} );

test( 'the two states are worded apart, not folded into one number', () => {
	expect( column ).toContain( "'%d failure'" );
	expect( column ).toContain( "'%d problem'" );
} );

test( 'the label no longer claims to be about renewals alone', () => {
	expect( column ).toContain( "__( 'Health', 'fundraising-toolkit' )" );
	// The filter is still renewal-specific, so it keeps its own wording.
	expect( column ).toContain( "__( 'Has failed renewals', 'fundraising-toolkit' )" );
} );
