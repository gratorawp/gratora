/**
 * A manual exchange rate is units of a currency per 1 unit of the base, and the
 * Currency panel re-sends every override it is holding on every save, whether
 * or not anyone touched the rate column. The shared Save bar can carry a base
 * currency change in the same click, which restates the whole stored table, so
 * the numbers this hook is holding are in the base the site just left. Posted
 * back as they were rendered, they pin the old figure into the new frame and
 * every foreign donation is stamped against it for good.
 */

jest.mock( '@wordpress/api-fetch', () => ( { __esModule: true, default: jest.fn() } ) );
jest.mock( '../../assets/admin/_shared/notify', () => ( {
    notify: { success: jest.fn(), error: jest.fn() },
} ) );

import { createElement, createRoot } from '@wordpress/element';
// The only piece not re-exported by @wordpress/element, and it is what makes
// the hook's effects and state settle before a test looks at them.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';
import apiFetch from '@wordpress/api-fetch';

import { useFxRates } from '../../assets/admin/_shared/useFxRates';

global.IS_REACT_ACT_ENVIRONMENT = true;

// The org reports in USD; the snapshot is still denominated in EUR, and the
// GBP override was typed under a header reading "1 EUR =".
const IN_EUR = {
    base: 'USD',
    frame: 'EUR',
    auto: true,
    rows: [
        { code: 'USD', is_base: true,  rate: 1.0,  auto_rate: 1.0,      is_manual: false },
        { code: 'GBP', is_base: false, rate: 0.90, auto_rate: 0.8521,   is_manual: true },
    ],
};

// After the base change: rebase() divided the table through by 1.0843.
const IN_USD = {
    base: 'USD',
    frame: 'USD',
    auto: true,
    rows: [
        { code: 'USD', is_base: true,  rate: 1.0,      auto_rate: 1.0,      is_manual: false },
        { code: 'GBP', is_base: false, rate: 0.830029, auto_rate: 0.785853, is_manual: true },
    ],
};

/** @param states GET responses in order; the last one repeats. */
function serve( states ) {
    const puts = [];
    let i = 0;
    apiFetch.mockImplementation( ( opts ) => {
        const at = states[ Math.min( i, states.length - 1 ) ];
        if ( opts.method === 'PUT' ) {
            puts.push( opts.data );
            return Promise.resolve( at );
        }
        i += 1;
        return Promise.resolve( at );
    } );
    return puts;
}

async function mount() {
    const fx = {};
    const Probe = () => {
        Object.assign( fx, useFxRates() );
        return null;
    };
    const container = document.createElement( 'div' );
    document.body.appendChild( container );
    await act( async () => {
        createRoot( container ).render( createElement( Probe ) );
    } );
    return fx;
}

beforeEach( () => {
    apiFetch.mockReset();
} );

test( 'a save that follows a base change posts the restated rate, not the one on screen', async () => {
    const puts = serve( [ IN_EUR, IN_USD ] );
    const fx = await mount();

    // The admin only flips auto-refresh. They never go near the rate column,
    // and that is enough to re-send every override.
    await act( async () => { fx.setAuto( false ); } );
    await act( async () => { await fx.save(); } );

    expect( puts ).toHaveLength( 1 );
    expect( puts[ 0 ].auto ).toBe( false );
    expect( puts[ 0 ].frame ).toBe( 'USD' );
    expect( puts[ 0 ].manual ).toEqual( { GBP: 0.830029 } );
} );

test( 'a rate the admin typed is not carried into a base they did not type it against', async () => {
    const puts = serve( [ IN_EUR, IN_USD ] );
    const fx = await mount();

    await act( async () => { fx.setManual( 'GBP', 0.95 ); } );

    let err = null;
    await act( async () => { err = await fx.save().catch( ( e ) => e ); } );

    expect( err ).toBeInstanceOf( Error );
    expect( puts ).toHaveLength( 0 );
} );

test( 'with the base where it was, a typed rate goes through as typed', async () => {
    const puts = serve( [ IN_USD ] );
    const fx = await mount();

    await act( async () => { fx.setManual( 'GBP', 0.95 ); } );
    await act( async () => { await fx.save(); } );

    expect( puts ).toEqual( [ { auto: true, manual: { GBP: 0.95 }, frame: 'USD' } ] );
} );

test( 'clearing an override needs no frame: it carries no number', async () => {
    const puts = serve( [ IN_EUR, IN_USD ] );
    const fx = await mount();

    await act( async () => { fx.resetManual( 'GBP' ); } );
    await act( async () => { await fx.save(); } );

    expect( puts ).toEqual( [ { auto: true, manual: {}, frame: 'USD' } ] );
} );
