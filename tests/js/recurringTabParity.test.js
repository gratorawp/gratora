/**
 * The subscriptions list and the donor profile's recurring tab are the same
 * table with different columns showing. Anything defined in one and copied to
 * the other drifts, which is how the tab came to have no way of reading a
 * plan's problems while the list did.
 */

import fs from 'fs';
import path from 'path';

const read = ( p ) => fs.readFileSync( path.join( __dirname, '../../assets/admin', p ), 'utf8' );

const list = read( 'subscriptions/List.jsx' );
const tab  = read( 'donors/profile/tabs/RecurringTab.jsx' );

test( 'both tables take their health cell from one place', () => {
	expect( list ).toContain( 'renderHealth( item )' );
	expect( tab ).toContain( 'renderHealth( item )' );
	// And neither draws its own health pill, which is the part that drifted.
	// The status column's failure subtitle is a different cell and stays.
	expect( list ).not.toContain( 'gratora-pill--amber' );
	expect( tab ).not.toContain( 'gratora-pill--amber' );
	expect( tab ).not.toContain( 'gratora-pill is-warn' );
} );

test( 'both offer View details and Copy subscription id from one place', () => {
	for ( const source of [ list, tab ] ) {
		expect( source ).toContain( 'viewDetailsAction( setDetail )' );
		expect( source ).toContain( 'copySubscriptionIdAction()' );
	}
} );

test( 'the tab can actually open the dialog it now offers', () => {
	expect( tab ).toContain( '<PlanDetailDialog' );
	expect( tab ).toContain( 'plan={ detail }' );
} );

test( 'the tab carries the columns the list has, hidden by default', () => {
	for ( const id of [ "id:    'gateway'", "id:    'started_at'" ] ) {
		expect( tab ).toContain( id );
	}

	// Hidden, not absent: the default view lists fewer than it defines.
	const defaults = tab.slice( tab.indexOf( 'fields:  [' ), tab.indexOf( ']', tab.indexOf( 'fields:  [' ) ) );
	expect( defaults ).not.toContain( 'gateway' );
	expect( defaults ).not.toContain( 'started_at' );
} );

test( 'neither table repeats the interval in its own column', () => {
	// The amount cell already reads "25.00 / month".
	for ( const source of [ list, tab ] ) {
		expect( source ).toContain( "/ { intervalLabel( item.interval_unit, item.interval_count ) }" );
	}

	const listDefaults = list.slice( list.indexOf( 'fields:  [' ), list.indexOf( ']', list.indexOf( 'fields:  [' ) ) );
	expect( listDefaults ).not.toContain( 'interval' );
	expect( tab ).not.toContain( "id:    'interval'" );
} );

test( 'interval wording is defined once, not per screen', () => {
	const shared = read( '_shared/recurring/planColumns.jsx' );

	expect( shared ).toContain( 'export function intervalLabel' );
	expect( tab ).not.toContain( 'function intervalLabel' );
} );
