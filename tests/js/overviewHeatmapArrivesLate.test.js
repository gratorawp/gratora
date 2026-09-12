/**
 * Overview widgets render before their metrics land, so every one of them is
 * mounted once with nothing and re-rendered with data. Under real React that
 * makes hook order across the two renders load-bearing, and a widget that
 * skipped a hook on the empty render takes the whole campaign screen down
 * with it the moment the aggregate answers.
 *
 * Real React here on purpose: preact/compat tolerates a growing hook list, so
 * the same defect mounted under preact renders happily.
 */

// The shared babel config compiles every .jsx to preact's runtime; pointing it
// back at React's is what puts this component on the renderer it ships on.
// eslint-disable-next-line import/no-extraneous-dependencies
jest.mock( 'preact/jsx-runtime', () => require( 'react/jsx-runtime' ) );

import { createRoot } from '@wordpress/element';
// @wordpress/element does not re-export act.
// eslint-disable-next-line import/no-extraneous-dependencies
import { act } from 'react';

import DowHourHeatmap from '../../assets/admin/campaigns/widgets/DowHourHeatmap';

const grid = () => Array.from( { length: 7 }, () => Array.from( { length: 24 }, () => 0 ) );

const DATA = ( () => {
    const g = grid();
    g[ 2 ][ 14 ] = 9;

    return { grid: g, max: 9, total: 9 };
} )();

let host;
let root;

beforeEach( () => {
    global.IS_REACT_ACT_ENVIRONMENT = true;
    document.body.innerHTML = '<div id="root"></div>';
    host = document.getElementById( 'root' );
    root = createRoot( host );
} );

afterEach( () => {
    act( () => root.unmount() );
} );

it( 'draws the grid when the metrics arrive after the first render', () => {
    act( () => root.render( <DowHourHeatmap data={ null } /> ) );
    expect( host.querySelector( '.gratora-heatmap' ) ).toBeNull();

    act( () => root.render( <DowHourHeatmap data={ DATA } /> ) );

    expect( host.querySelectorAll( '.gratora-heatmap__cell' ) ).toHaveLength( 7 * 24 );
    expect( host.querySelectorAll( '.gratora-heatmap__row-label' ) ).toHaveLength( 8 );
} );

it( 'goes back to the empty state when the range has no activity', () => {
    act( () => root.render( <DowHourHeatmap data={ DATA } /> ) );
    act( () => root.render( <DowHourHeatmap data={ { grid: grid(), max: 0, total: 0 } } /> ) );

    expect( host.querySelector( '.gratora-heatmap' ) ).toBeNull();
    expect( host.querySelector( '.gratora-panel__empty' ) ).not.toBeNull();
} );
