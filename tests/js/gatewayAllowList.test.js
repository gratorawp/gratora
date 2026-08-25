/**
 * "Allowed gateways" is an allow-list where EMPTY means "offer every gateway".
 * Two screens edit it: the form Editor's sidebar and the Payment gateways
 * block's own inspector. They rendered the same stored value differently, so a
 * form built from any shipped template (all of which store []) showed every
 * gateway OFF in one and ON in the other, and ticking one box in the sidebar
 * turned the rest off.
 */

const { gatewayIsOn, toggleGatewayAllowed } = require( '../../assets/admin/_shared/gatewayAllowList' );

const ALL = [ 'stripe', 'paypal', 'offline' ];

describe( 'an empty allow-list means every gateway', () => {
    test( 'nothing named is everything on', () => {
        expect( ALL.every( ( id ) => gatewayIsOn( [], id ) ) ).toBe( true );
        expect( ALL.every( ( id ) => gatewayIsOn( undefined, id ) ) ).toBe( true );
    } );

    test( 'a named subset is only those', () => {
        expect( gatewayIsOn( [ 'stripe' ], 'stripe' ) ).toBe( true );
        expect( gatewayIsOn( [ 'stripe' ], 'paypal' ) ).toBe( false );
    } );
} );

describe( 'toggling from the default turns one off, not all the others on', () => {
    test( 'unticking a gateway on a fresh form leaves the rest offered', () => {
        // The defect: from [] the sidebar wrote [ 'stripe' ], silently
        // dropping PayPal and offline from a form that offered all three.
        expect( toggleGatewayAllowed( [], 'stripe', ALL ) ).toEqual( [ 'paypal', 'offline' ] );
    } );

    test( 'ticking the last one back collapses to the same "offer all"', () => {
        expect( toggleGatewayAllowed( [ 'paypal', 'offline' ], 'stripe', ALL ) ).toEqual( [] );
    } );

    test( 'the order follows the registry, not the click order', () => {
        expect( toggleGatewayAllowed( [ 'offline' ], 'stripe', ALL ) ).toEqual( [ 'stripe', 'offline' ] );
    } );

    test( 'turning one off from a subset keeps the rest', () => {
        expect( toggleGatewayAllowed( [ 'stripe', 'paypal' ], 'paypal', ALL ) ).toEqual( [ 'stripe' ] );
    } );
} );
