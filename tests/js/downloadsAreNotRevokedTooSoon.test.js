/**
 * A blob URL is a handle the browser has to fetch back out of after the click.
 * Revoking it on the very next line races that fetch: Safari and iOS have not
 * read it yet, so the file never arrives and nothing on screen says why. The
 * one that matters most is "Download my data", which is a right-of-access
 * request the donor has no other way to make.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

import { saveBlob } from '@gratora/ui/utils/download';

let clicked;

beforeEach( () => {
    clicked = [];
    window.URL.createObjectURL = jest.fn( () => 'blob:probe' );
    window.URL.revokeObjectURL = jest.fn();
    jest.spyOn( window.HTMLAnchorElement.prototype, 'click' ).mockImplementation( function () {
        clicked.push( { download: this.download, revokedByNow: window.URL.revokeObjectURL.mock.calls.length } );
    } );
} );

afterEach( () => jest.restoreAllMocks() );

describe( 'the saver every download goes through', () => {
    it( 'does not free the blob before the browser has fetched it', () => {
        saveBlob( new Blob( [ '{}' ] ), 'gratora-export.json' );

        expect( clicked ).toHaveLength( 1 );
        expect( clicked[ 0 ].download ).toBe( 'gratora-export.json' );
        expect( window.URL.revokeObjectURL ).not.toHaveBeenCalled();
    } );

    it( 'frees it once the download has had its chance', () => {
        jest.useFakeTimers();

        try {
            saveBlob( new Blob( [ '{}' ] ), 'gratora-export.json' );
            jest.advanceTimersByTime( 5000 );
        } finally {
            jest.useRealTimers();
        }

        expect( window.URL.revokeObjectURL ).toHaveBeenCalledWith( 'blob:probe' );
    } );
} );

