/**
 * The amount box takes either separator, because donors type both by habit.
 * Three digits after the last one were read as a thousands group, so on a site
 * that writes 20.00 a donor who typed 20.000 was asked for twenty thousand. The
 * site's own decimal separator, written once, is the decimal point.
 */

import { render } from 'preact';
import { act } from 'preact/test-utils';

import AmountInput, { typedAmountToNumber } from '../../assets/donation-form/components/AmountInput';
import { setActiveNumberFormat } from '../../assets/donation-form/util/format';

const UK = { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '£' };
const DE = { decimalPlaces: 2, decimalSep: ',', thousandSep: '.', symbolPosition: 'after', symbol: '€' };

describe( 'on a site that writes 20.00', () => {
    it.each( [
        [ '20.000', 20 ],
        [ '20.005', 20 ],
        [ '20.00', 20 ],
        [ '20.5', 20.5 ],
        [ '20,000', 20000 ],
        [ '1,000.50', 1000.5 ],
        [ '1.000.000', 1000000 ],
        [ '20,50', 20.5 ],
        [ '1.000,50', 1000.5 ],
    ] )( 'reads %s as %d', ( typed, amount ) => {
        expect( typedAmountToNumber( typed, 2, '.' ) ).toBe( amount );
    } );
} );

describe( 'on a site that writes 20,00', () => {
    it.each( [
        [ '20,000', 20 ],
        [ '20.000', 20000 ],
        [ '20.50', 20.5 ],
    ] )( 'reads %s as %d', ( typed, amount ) => {
        expect( typedAmountToNumber( typed, 2, ',' ) ).toBe( amount );
    } );
} );

it( 'still groups in a currency without decimals', () => {
    expect( typedAmountToNumber( '20.000', 0, '.' ) ).toBe( 20000 );
} );

describe( 'the box', () => {
    let changes;

    function box( fmt, currency ) {
        setActiveNumberFormat( fmt, currency );
        changes = [];
        document.body.innerHTML = '<div id="root"></div>';
        act( () => {
            render( <AmountInput value={ 0 } currency={ currency } onChange={ ( n ) => changes.push( n ) } />, document.getElementById( 'root' ) );
        } );
        return document.querySelector( '.gratora-amount__input' );
    }

    function type( input, text ) {
        for ( let i = 1; i <= text.length; i++ ) {
            act( () => {
                input.value = input.value + text[ i - 1 ];
                input.dispatchEvent( new Event( 'input', { bubbles: true } ) );
            } );
        }
    }

    afterEach( () => setActiveNumberFormat( null ) );

    it( 'does not take a third digit after the point', () => {
        const input = box( UK, 'GBP' );

        type( input, '20.000' );

        expect( input.value ).toBe( '20.00' );
        expect( changes[ changes.length - 1 ] ).toBe( 20 );
    } );

    it( 'still takes a thousands comma', () => {
        const input = box( UK, 'GBP' );

        type( input, '20,000' );

        expect( input.value ).toBe( '20,000' );
        expect( changes[ changes.length - 1 ] ).toBe( 20000 );
    } );

    it( 'holds a site that writes 20,00 to two places after its comma', () => {
        const input = box( DE, 'EUR' );

        type( input, '20,000' );

        expect( input.value ).toBe( '20,00' );
        expect( changes[ changes.length - 1 ] ).toBe( 20 );
    } );
} );
