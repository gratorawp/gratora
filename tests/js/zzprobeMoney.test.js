import { formatAmount, groupDigits } from '@dono/ui/utils/format';

describe( 'probe: admin money rendering vs org decimal_places', () => {
    beforeEach( () => {
        global.window.dono = {
            default_currency: 'USD',
            number_format: {
                decimalPlaces: 0,
                decimalSep: '.',
                thousandSep: ',',
                symbolPosition: 'before',
                symbol: '$',
            },
        };
    } );

    it( 'prints what it prints', () => {
        // eslint-disable-next-line no-console
        console.log( 'USD 2654 ->', formatAmount( 2654, 'USD' ) );
        console.log( 'USD 2699 ->', formatAmount( 2699, 'USD' ) );
        console.log( 'USD 1023487 ->', formatAmount( 1023487, 'USD' ) );
        console.log( 'EUR 2654 ->', formatAmount( 2654, 'EUR' ) );
        console.log( 'JPY 100000 ->', formatAmount( 100000, 'JPY' ) );
        console.log( 'groupDigits(26.99,dp0) ->', groupDigits( 26.99, ',', '.', 0 ) );
        expect( true ).toBe( true );
    } );

    it( 'with decimalPlaces 2 (default) and a JPY default currency', () => {
        global.window.dono = {
            default_currency: 'JPY',
            number_format: {
                decimalPlaces: 2,
                decimalSep: '.',
                thousandSep: ',',
                symbolPosition: 'before',
                symbol: '¥',
            },
        };
        console.log( 'JPY-default: JPY 100000 ->', formatAmount( 100000, 'JPY' ) );
        console.log( 'JPY-default: JPY 123456 ->', formatAmount( 123456, 'JPY' ) );
        expect( true ).toBe( true );
    } );
} );
