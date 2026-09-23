/**
 * A goal bar cannot overflow its track, so the fill is capped. The number
 * beside it was capped with it, and that is a different question: five of one
 * site's seven campaigns read "100%" while standing at 1,957%, 7,025%,
 * 10,859%, 13,079% and 24,841%.
 *
 * The campaign detail was the plainer case. Its card printed 100% directly
 * above "$3,161,295.00 / $45,000.00", so the contradiction needed no
 * arithmetic to see.
 *
 * Three copies computed the cap, one per screen. There is one now, and only
 * the bar uses it.
 */

import { render } from 'preact';

import GoalBar, { GoalCell, goalPercent } from '../../assets/admin/_shared/components/GoalBar';
import ActiveCampaigns from '../../assets/admin/dashboard/widgets/ActiveCampaigns';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

function draw( node ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( node, document.getElementById( 'root' ) );

    return document.getElementById( 'root' );
}

const AMOUNT_GOAL = { goal_type: 'amount', goal_cents: 4500000, raised_cents: 316129500 };

test( 'a campaign far past its target says how far', () => {
    expect( goalPercent( 316129500, 4500000 ) ).toBe( 7025 );
    expect( goalPercent( 322927500, 1300000 ) ).toBe( 24841 );
} );

test( 'and one short of it is unchanged', () => {
    expect( goalPercent( 446750, 500000 ) ).toBe( 89 );
    expect( goalPercent( 22500, 800000 ) ).toBe( 3 );
} );

test( 'no target is no progress rather than a division', () => {
    expect( goalPercent( 5000, 0 ) ).toBe( 0 );
    expect( goalPercent( 5000, null ) ).toBe( 0 );
} );

test( 'the list cell prints the real figure', () => {
    const root = draw( <GoalCell item={ AMOUNT_GOAL } /> );

    expect( root.textContent ).toContain( '7,025%' );
    expect( root.textContent ).not.toContain( '100%' );
} );

/** The one thing that must stay capped, because a track has a width. */
test( 'the bar fill stops at the end of its track', () => {
    const root = draw( <GoalBar left="x" right="7,025%" pct={ 7025 } /> );
    const fill = root.querySelector( '.gratora-goalbar__fill' );

    expect( fill.style.width ).toBe( '100%' );
} );

test( 'a bar short of its goal fills proportionally', () => {
    const root = draw( <GoalBar left="x" right="89%" pct={ 89 } /> );

    expect( root.querySelector( '.gratora-goalbar__fill' ).style.width ).toBe( '89%' );
} );

/** A goal counted in donors reads the same way. */
test( 'a donor-count goal past its target also says how far', () => {
    const root = draw( <GoalCell item={ { goal_type: 'donors', goal_count: 250, donors_count: 4892 } } /> );

    expect( root.textContent ).toContain( '250 donors' );
    expect( root.textContent ).toContain( '1,957%' );
} );

test( 'a campaign with no goal still says so', () => {
    const root = draw( <GoalCell item={ { goal_type: 'amount', goal_cents: 0, raised_cents: 5000 } } /> );

    expect( root.textContent ).toContain( 'No goal' );
    expect( root.textContent ).not.toContain( '%' );
} );

/** The dashboard widget carried the third copy, beside its own bar. */
test( 'the dashboard widget prints the real figure and still caps its bar', () => {
	const root = draw( <ActiveCampaigns rows={ [ {
		id: 6,
		title: 'Winter Emergency Appeal',
		status: 'ended',
		goal_type: 'amount',
		goal_cents: 800000,
		raised_cents: 894000,
		currency: 'USD',
	} ] } /> );

	expect( root.textContent ).toContain( '112%' );
	expect( root.textContent ).not.toContain( '100%' );
	expect( root.querySelector( '.gratora-active-campaigns__bar-fill' ).style.width ).toBe( '100%' );
} );

/**
 * The campaign detail carried the second copy of the cap and now shares this
 * one. Its card is covered where it already was, in campaignGoalKpiCard.
 */
