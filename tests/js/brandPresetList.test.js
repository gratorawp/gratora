/**
 * The server stores only the presets the admin touched: a built-in still
 * identical to what ships is dropped on save. Rendering the option alone
 * therefore collapses the list to one row the moment Classic is edited, and
 * Bold, Quiet and Site theme vanish from the one screen that owns presets while
 * every other screen still offers them.
 */

import { mergePresets, presetsForPanel, presetLabel } from '../../assets/admin/settings/panels/brandPresets';

const BUILTINS = [
    { id: 'classic', name: 'Classic',    tokens: {}, builtin: true },
    { id: 'bold',    name: 'Bold',       tokens: {}, builtin: true },
    { id: 'quiet',   name: 'Quiet',      tokens: {}, builtin: true },
    { id: 'theme',   name: 'Site theme', tokens: {}, builtin: true },
];

const ids = ( list ) => list.map( ( p ) => p.id );

it( 'keeps every built-in after one of them is edited', () => {
    const stored = [ { id: 'classic', name: 'Classic', tokens: { 'gratora-accent': '#f00' } } ];

    const out = mergePresets( stored, BUILTINS );

    expect( ids( out ) ).toEqual( [ 'classic', 'bold', 'quiet', 'theme' ] );
    expect( out[ 0 ].tokens[ 'gratora-accent' ] ).toBe( '#f00' );
} );

it( 'shows a custom beside them', () => {
    const stored = [
        { id: 'classic', name: 'Classic', tokens: { 'gratora-accent': '#f00' } },
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
    window.gratora = { styling: {
        builtins: BUILTINS,
        presets:  [ ...BUILTINS, { id: 'house', name: 'House', tokens: {} } ],
    } };

    const afterDelete = [ { id: 'classic', name: 'Classic', tokens: {} } ];

    try {
        expect( ids( presetsForPanel( afterDelete ) ) ).not.toContain( 'house' );
    } finally {
        delete window.gratora;
    }
} );

it( 'shows the shipped list on a site that has saved nothing', () => {
    expect( ids( mergePresets( [], BUILTINS ) ) ).toEqual( [ 'classic', 'bold', 'quiet', 'theme' ] );
} );

it( 'survives a page that lost its globals', () => {
    expect( mergePresets( [ { id: 'house' } ], undefined ) ).toEqual( [ { id: 'house' } ] );
} );

/**
 * The server drops a built-in's name from the option when the admin has not
 * renamed it, so it is not pinned to the locale of whoever saved. It restores
 * it on read; a panel that does not restore it shows the row with no label at
 * all, hands the editor a blank name field, and clones it as " (copy)".
 */
it( 'keeps an edited built-in labelled', () => {
    const stored = [ { id: 'classic', tokens: { 'gratora-accent': '#f00' } } ];

    const classic = mergePresets( stored, BUILTINS )[ 0 ];

    expect( classic.name ).toBe( 'Classic' );
} );

it( 'still prefers a name the admin typed', () => {
    const stored = [ { id: 'classic', name: 'House', tokens: {} } ];

    expect( mergePresets( stored, BUILTINS )[ 0 ].name ).toBe( 'House' );
} );

/**
 * The shipped tokens are the baseline the admin's edit sits on, the way
 * StylePresets::all() merges them. Replacing the record wholesale drops the
 * built-in's own values for every key the admin did not touch.
 */
it( 'keeps the shipped tokens an edit did not touch', () => {
    const ships = [ { id: 'quiet', name: 'Quiet', tokens: { 'gratora-accent': '#000', 'gratora-button-border': '1px' }, builtin: true } ];
    const stored = [ { id: 'quiet', tokens: { 'gratora-accent': '#f00' } } ];

    const quiet = mergePresets( stored, ships )[ 0 ];

    expect( quiet.tokens[ 'gratora-accent' ] ).toBe( '#f00' );
    expect( quiet.tokens[ 'gratora-button-border' ] ).toBe( '1px' );
} );

/**
 * StylePresets::all() substitutes the id for a custom whose name is blank, so
 * every picker outside this panel already calls it 'house'. Falling back only
 * on screen keeps the field clearable and keeps a locale's label out of the
 * record the panel saves.
 */
it( 'calls a nameless custom what every other picker calls it', () => {
    expect( presetLabel( { id: 'house', name: '   ' } ) ).toBe( 'house' );
    expect( presetLabel( { id: 'house' } ) ).toBe( 'house' );
} );

it( 'keeps a built-in renamed to spaces under its shipped label', () => {
    expect( presetLabel( { id: 'classic', name: '  ' }, 'Classic' ) ).toBe( 'Classic' );
    expect( mergePresets( [ { id: 'classic', name: '  ' } ], BUILTINS )[ 0 ].name ).toBe( 'Classic' );
} );

it( 'still prefers what the admin typed', () => {
    expect( presetLabel( { id: 'house', name: 'House' } ) ).toBe( 'House' );
} );
