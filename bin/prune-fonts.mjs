/**
 * Drop the mpdf fonts this plugin can never ask for.
 *
 * mpdf ships 87 MB of ttfonts covering every script it supports. Receipts set
 * default_font to dejavusans and nothing turns on script detection, so the
 * other 78 MB is downloaded by every install and every auto-update and read by
 * nothing.
 *
 * The whole DejaVu family stays, not just the one face: mpdf resolves bold,
 * italic, serif and mono within a family on its own, and dejavusanscondensed is
 * the first entry in backupSubsFont, so the fallback path still lands on a font
 * that is present if substitution is ever switched on.
 *
 * Runs after Strauss, which copies dependencies whole and would otherwise put
 * all of it back on the next composer install.
 */
import { readdirSync, statSync, unlinkSync } from 'node:fs';
import path from 'node:path';

const dir = path.join( process.cwd(), 'vendor/vendor-prefixed/mpdf/mpdf/ttfonts' );

// Keep the family, and the licence/readme that has to travel with it.
const KEEP = /^(DejaVu|.*\.txt$)/i;

let removed = 0;
let freed = 0;

let entries;
try {
    entries = readdirSync( dir );
} catch {
    console.log( 'no mpdf ttfonts directory; nothing to prune' );
    process.exit( 0 );
}

for ( const name of entries ) {
    if ( KEEP.test( name ) ) continue;

    const full = path.join( dir, name );
    if ( ! statSync( full ).isFile() ) continue;

    freed += statSync( full ).size;
    unlinkSync( full );
    removed++;
}

console.log(
    removed === 0
        ? 'mpdf fonts already pruned'
        : `pruned ${ removed } mpdf fonts, freed ${ ( freed / 1048576 ).toFixed( 1 ) } MB`
);
