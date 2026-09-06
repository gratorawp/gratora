/**
 * The schedule axis and the heatmap rows were tables of English words sitting
 * beside labels the same widget formats through Intl, so one campaign overview
 * read "5 sept." above an axis reading Jan Feb Mar, and a screen reader
 * announced "Monday at 14:00" in English on a French site.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '../../assets/admin/_shared/components/DateField', () => ( { __esModule: true, default: () => null } ) );

import { render } from 'preact';

import ScheduleTimeline from '../../assets/admin/campaigns/ScheduleTimeline';
import DowHourHeatmap from '../../assets/admin/campaigns/widgets/DowHourHeatmap';

// Node's Intl locale is fixed at startup, so the formatter is what gets driven.
beforeEach( () => {
    jest.spyOn( Date.prototype, 'toLocaleDateString' ).mockImplementation( function ( _l, opts ) {
        if ( opts?.weekday === 'short' ) return 'DIA' + this.getUTCDay();
        if ( opts?.weekday === 'long' )  return 'JOUR' + this.getUTCDay();
        if ( opts?.month === 'short' && ! opts.day ) return 'MES' + ( this.getUTCMonth() + 1 );

        return 'X';
    } );
    document.body.innerHTML = '<div id="root"></div>';
} );

afterEach( () => jest.restoreAllMocks() );

const mount = ( node ) => render( node, document.getElementById( 'root' ) );

it( 'names the schedule months the way the rest of the widget names dates', () => {
    mount( <ScheduleTimeline startsAt="2026-03-01T00:00:00Z" endsAt="2026-09-01T00:00:00Z" onChange={ () => {} } /> );

    const axis = [ ...document.querySelectorAll( '.fundkit-schedule__axis span' ) ].map( ( s ) => s.textContent );

    expect( axis ).toHaveLength( 12 );
    expect( axis[ 0 ] ).toBe( 'MES1' );
    expect( axis[ 11 ] ).toBe( 'MES12' );
    expect( axis ).not.toContain( 'Jan' );
} );

describe( 'the donation heatmap', () => {
    const grid = Array.from( { length: 7 }, () => Array( 24 ).fill( 0 ) );
    grid[ 0 ][ 14 ] = 3;

    beforeEach( () => mount( <DowHourHeatmap data={ { total: 3, max: 3, grid } } /> ) );

    it( 'labels its rows in the reader language', () => {
        // The grid is keyed 0 = Monday, which is UTC day 1.
        // The first row-label span is the empty spacer above the hour axis.
        const labels = [ ...document.querySelectorAll( '.fundkit-heatmap__row .fundkit-heatmap__row-label' ) ];

        expect( labels[ 0 ].textContent ).toBe( 'DIA1' );
        expect( labels ).toHaveLength( 7 );
    } );

    it( 'says the same in the accessible name of every cell', () => {
        const labels = [ ...document.querySelectorAll( '[aria-label]' ) ].map( ( el ) => el.getAttribute( 'aria-label' ) );

        expect( labels.some( ( l ) => l.includes( 'JOUR1' ) ) ).toBe( true );
        expect( labels.some( ( l ) => /Monday|Mon\b/.test( l ) ) ).toBe( false );
    } );

    it( 'leaves no English day name anywhere on the widget', () => {
        expect( document.body.textContent ).not.toMatch( /\b(Mon|Tue|Wed|Thu|Fri|Sat|Sun)\b/ );
    } );
} );
