const fs = require( 'fs' );
const path = require( 'path' );

/**
 * A settings group has to be declared in four places, and missing one of them
 * fails quietly.
 *
 * Loading a group gets you a panel that edits values. What makes those values
 * savable is separate: anyDirty raises the Save button and the leave-the-page
 * guard, dirtyByTab marks the tab and counts the changed sections, and the save
 * list actually writes it. A group wired into some of those and not the others
 * looks like a working screen whose Save button never appears, which is how the
 * spam group shipped.
 *
 * Read from the source rather than a render, because what is being checked is
 * that somebody remembered to add four lines, and every one of them is a
 * different shape.
 */
describe( 'every settings group is wired into saving', () => {
    const source = fs.readFileSync(
        path.join( __dirname, '../../assets/admin/settings/Settings.jsx' ),
        'utf8'
    );

    // The variable each group is held in, since the two names differ:
    // 'org-profile' lives in `org`, 'exchange-rates' in `fx`.
    const loaded = [ ...source.matchAll( /const\s+(\w+)\s*=\s*useFundKitSettings\(\s*'([a-z-]+)'\s*\)/g ) ]
        .map( ( m ) => ( { variable: m[ 1 ], group: m[ 2 ] } ) );

    const anyDirty = ( source.match( /const anyDirty = ([^;]+);/ ) || [ '', '' ] )[ 1 ];
    const byTab    = ( source.match( /const dirtyByTab = useMemo\(\s*\(\s*\)\s*=>\s*\(\s*\{([\s\S]*?)\}\s*\)/ ) || [ '', '' ] )[ 1 ];
    // The guard in front of each push, rather than a slice of the file: the
    // first guard sits before the first push, so slicing from one drops it.
    const saveJobs = [ ...source.matchAll( /if\s*\(\s*(\w+)\.isDirty\s*\)\s*jobs\.push/g ) ]
        .map( ( m ) => `${ m[ 1 ] }.isDirty` )
        .join( ' ' );

    it( 'loads at least the groups this test is worth running for', () => {
        expect( loaded.length ).toBeGreaterThan( 5 );
        expect( loaded.map( ( g ) => g.group ) ).toContain( 'privacy' );
    } );

    it.each( [ 'anyDirty', 'dirtyByTab', 'the save list' ] )(
        'no group is missing from %s',
        ( where ) => {
            const haystack = { anyDirty, dirtyByTab: byTab, 'the save list': saveJobs }[ where ];

            const missing = loaded
                // fx is the exchange-rate record rather than a tab of its own:
                // it rides along with currency, so it has no dirtyByTab key.
                .filter( ( g ) => ! ( where === 'dirtyByTab' && g.variable === 'fx' ) )
                .filter( ( g ) => ! haystack.includes( `${ g.variable }.isDirty` ) )
                .map( ( g ) => g.group );

            expect( missing ).toEqual( [] );
        }
    );
} );
