/**
 * navigator.clipboard is undefined on an insecure origin, which is any staging
 * or intranet wp-admin served over plain http. `await navigator.clipboard?.…`
 * resolves to undefined and reports a copy nobody made: the admin pastes what
 * was in their clipboard before, into an embed or a support ticket.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const notified = { success: [], error: [] };

jest.mock( '../../assets/admin/_shared/notify', () => ( {
    __esModule: true,
    notify:  { success: ( m ) => notified.success.push( m ), error: ( m ) => notified.error.push( m ), info: () => {} },
    default: { success: ( m ) => notified.success.push( m ), error: ( m ) => notified.error.push( m ), info: () => {} },
} ) );

import { copySubscriptionIdAction } from '../../assets/admin/_shared/recurring/planColumns';

const PLAN = { id: 1, gateway_subscription_id: 'sub_ABC123' };

let realClipboard;

beforeEach( () => {
    notified.success = [];
    notified.error   = [];
    realClipboard = window.navigator.clipboard;
} );

afterEach( () => {
    Object.defineProperty( window.navigator, 'clipboard', { value: realClipboard, configurable: true } );
} );

const withClipboard = ( value ) =>
    Object.defineProperty( window.navigator, 'clipboard', { value, configurable: true } );

it( 'does not claim a copy on an origin with no clipboard', async () => {
    withClipboard( undefined );

    await copySubscriptionIdAction().callback( [ PLAN ] );

    expect( notified.success ).toEqual( [] );
    expect( notified.error ).toContain( 'sub_ABC123' );
} );

it( 'and says so when the write itself is refused', async () => {
    withClipboard( { writeText: () => Promise.reject( new Error( 'denied' ) ) } );

    await copySubscriptionIdAction().callback( [ PLAN ] );

    expect( notified.success ).toEqual( [] );
    expect( notified.error ).toContain( 'sub_ABC123' );
} );

it( 'reports the copy it really made', async () => {
    const written = [];
    withClipboard( { writeText: ( v ) => { written.push( v ); return Promise.resolve(); } } );

    await copySubscriptionIdAction().callback( [ PLAN ] );

    expect( written ).toEqual( [ 'sub_ABC123' ] );
    expect( notified.success ).toHaveLength( 1 );
    expect( notified.error ).toEqual( [] );
} );
