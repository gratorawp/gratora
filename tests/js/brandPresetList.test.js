/**
 * The server stores only the presets the admin touched: a built-in still
 * identical to what ships is dropped on save. Rendering the option alone
 * therefore collapses the list to one row the moment Classic is edited, and
 * Bold, Quiet and Site theme vanish from the one screen that owns presets while
 * every other screen still offers them.
 */

import { mergePresets, presetsForPanel } from '../../assets/admin/settings/panels/brandPresets';

const BUILTINS = [
    { id: 'classic', name: 'Classic',    tokens: {}, builtin: true },
    { id: 'bold',    name: 'Bold',       tokens: {}, builtin: true },
    { id: 'quiet',   name: 'Quiet',      tokens: {}, builtin: true },
    { id: 'theme',   name: 'Site theme', tokens: {}, builtin: true },
];

const ids = ( list ) => list.map( ( p ) => p.id );

it( 'keeps every built-in after one of them is edited', () => {
    const stored = [ { id: 'classic', name: 'Classic', tokens: { 'fundkit-accent': '#f00' } } ];

    const out = mergePresets( stored, BUILTINS );

    expect( ids( out ) ).toEqual( [ 'classic', 'bold', 'quiet', 'theme' ] );
    expect( out[ 0 ].tokens[ 'fundkit-accent' ] ).toBe( '#f00' );
} );

it( 'shows a custom beside them', () => {
    const stored = [
        { id: 'classic', name: 'Classic', tokens: { 'fundkit-accent': '#f00' } },
        { id: 'house',   name: 'House',   tokens: {} },
    ];

    expect( ids( mergePresets( stored, BUILTINS ) ) ).toEqual( [ 'classic', 'bold', 'quiet', 'theme', 'house' ] );
} );

/**
 * Delete writes the filtered array to the record. Seeding from the full
 * published list rather than the built-ins would put the deleted custom back
 * from the page-load snapshot, in the same tab, before any save.
 */
it( 'does not resurrect a custom the admin just deleted', () => {
    // The page-load snapshot still carries the custom, because the delete has
    // not been saved yet. Seeding from it would put the row straight back.
    window.fundkit = { styling: {
        builtins: BUILTINS,
        presets:  [ ...BUILTINS, { id: 'house', name: 'House', tokens: {} } ],
    } };

    const afterDelete = [ { id: 'classic', name: 'Classic', tokens: {} } ];

    try {
        expect( ids( presetsForPanel( afterDelete ) ) ).not.toContain( 'house' );
    } finally {
        delete window.fundkit;
    }
} );

it( 'shows the shipped list on a site that has saved nothing', () => {
    expect( ids( mergePresets( [], BUILTINS ) ) ).toEqual( [ 'classic', 'bold', 'quiet', 'theme' ] );
} );

it( 'survives a page that lost its globals', () => {
    expect( mergePresets( [ { id: 'house' } ], undefined ) ).toEqual( [ { id: 'house' } ] );
} );
