/**
 * The banner across the campaign screen is the only place an admin is told why
 * their campaign is not taking money. It looked up the reason in a map and fell
 * back to the draft sentence, so a published campaign that had reached its goal
 * was told it was a draft, and any reason added later would inherit that lie.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { notAcceptingMessage } from '../../assets/admin/campaigns/Detail';

// Every reason Campaign::notAcceptingReason() can return.
const REASONS = [ 'draft', 'archived', 'scheduled', 'ended', 'goal_met' ];

describe( 'notAcceptingMessage', () => {
    it( 'gives every reason its own sentence', () => {
        const seen = REASONS.map( notAcceptingMessage );

        expect( new Set( seen ).size ).toBe( REASONS.length );
    } );

    it( 'does not call a campaign that met its goal a draft', () => {
        const message = notAcceptingMessage( 'goal_met' );

        expect( message ).toContain( 'reached its goal' );
        expect( message ).not.toContain( 'draft' );
    } );

    it( 'tells the admin how to reopen a campaign closed on its goal', () => {
        expect( notAcceptingMessage( 'goal_met' ) ).toContain( 'Raise the target' );
    } );

    it( 'falls back to the plain truth, not to another reason', () => {
        const draft = notAcceptingMessage( 'draft' );

        // A reason this build has never heard of, which is what goal_met was
        // to the version before it was added.
        const unknown = notAcceptingMessage( 'suspended_by_an_add_on' );

        expect( unknown ).not.toBe( draft );
        expect( unknown ).not.toContain( 'draft' );
        expect( unknown ).toContain( 'not taking donations' );
    } );

    it( 'says something for a reason it does not recognise', () => {
        for ( const reason of [ '', null, undefined, 'nonsense' ] ) {
            expect( notAcceptingMessage( reason ) ).toBeTruthy();
        }
    } );
} );
