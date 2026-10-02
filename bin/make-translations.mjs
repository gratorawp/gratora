#!/usr/bin/env node
/**
 * Write what WordPress loads at runtime from each locale's PO file: the
 * compiled .mo for PHP, and one JSON file per built bundle, named after the
 * md5 of the bundle's path, which is the name WordPress asks for.
 * `.cache/scripts.pot` says which strings each bundle carries.
 */

import { createHash } from 'node:crypto';
import { existsSync, readFileSync, readdirSync, rmSync, writeFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const DOMAIN = 'gratora-donation-platform';

const UNESCAPES = { n: '\n', t: '\t', r: '\r', '"': '"', '\\': '\\' };

const unescape = ( text ) => text.replace( /\\(.)/g, ( _, c ) => UNESCAPES[ c ] ?? c );

/** The entries of a PO or POT file, the header first. */
function parsePo( source ) {
    return source
        .trim()
        .split( /\n\s*\n/ )
        .map( ( block ) => {
            const entry = { refs: [], ctxt: null, id: null, plural: null, str: [] };
            let field   = null;

            for ( const line of block.split( '\n' ) ) {
                if ( line.startsWith( '#:' ) ) {
                    entry.refs.push( ...line.slice( 2 ).trim().split( /\s+/ ) );
                    continue;
                }
                const start = line.match( /^(msgctxt|msgid_plural|msgid|msgstr)(?:\[(\d+)\])? "(.*)"$/ );
                if ( start ) {
                    field = start[ 1 ] === 'msgstr' ? [ 'str', Number( start[ 2 ] ?? 0 ) ] : [ { msgctxt: 'ctxt', msgid: 'id', msgid_plural: 'plural' }[ start[ 1 ] ] ];
                    assign( entry, field, unescape( start[ 3 ] ), false );
                } else if ( field && line.startsWith( '"' ) ) {
                    assign( entry, field, unescape( line.slice( 1, -1 ) ), true );
                }
            }

            return entry;
        } )
        .filter( ( entry ) => entry.id !== null );
}

function assign( entry, field, text, append ) {
    if ( field[ 0 ] === 'str' ) {
        entry.str[ field[ 1 ] ] = ( append ? entry.str[ field[ 1 ] ] ?? '' : '' ) + text;
    } else {
        entry[ field[ 0 ] ] = ( append ? entry[ field[ 0 ] ] ?? '' : '' ) + text;
    }
}

const keyOf = ( entry ) => ( entry.ctxt === null ? entry.id : entry.ctxt + '\u0004' + entry.id );

/**
 * One locale's script files: bundle path to the JSON WordPress reads for it.
 * A bundle none of whose strings are translated gets no file.
 */
function scriptTranslations( scripts, locale, lang ) {
    const header      = locale.find( ( entry ) => entry.id === '' )?.str[ 0 ] ?? '';
    const pluralForms = header.match( /Plural-Forms:\s*([^\n]+)/ )?.[ 1 ].trim() ?? 'nplurals=2; plural=(n != 1);';
    const revised     = header.match( /PO-Revision-Date:\s*([^\n]+)/ )?.[ 1 ].trim() ?? '';

    const translated = new Map();
    for ( const entry of locale ) {
        if ( entry.id !== '' && entry.str.length > 0 && entry.str.every( ( s ) => s !== '' ) ) {
            translated.set( keyOf( entry ), entry.str );
        }
    }

    const perBundle = {};
    for ( const entry of scripts ) {
        const strings = translated.get( keyOf( entry ) );
        if ( entry.id === '' || ! strings ) {
            continue;
        }
        for ( const ref of entry.refs ) {
            const bundle = ref.replace( /:\d+$/, '' );
            if ( bundle.endsWith( '.js' ) ) {
                ( perBundle[ bundle ] ??= {} )[ keyOf( entry ) ] = strings;
            }
        }
    }

    const files = {};
    for ( const bundle of Object.keys( perBundle ).sort() ) {
        const messages = { '': { domain: 'messages', lang, 'plural-forms': pluralForms } };
        for ( const key of Object.keys( perBundle[ bundle ] ).sort() ) {
            messages[ key ] = perBundle[ bundle ][ key ];
        }
        files[ bundle ] = {
            'translation-revision-date': revised,
            generator: 'gratora make-script-translations',
            source: bundle,
            domain: 'messages',
            locale_data: { messages },
        };
    }

    return files;
}

const fileNameFor = ( lang, bundle ) => `${ DOMAIN }-${ lang }-${ createHash( 'md5' ).update( bundle ).digest( 'hex' ) }.json`;

/**
 * A GNU .mo file holding the locale's translated entries, originals sorted
 * by bytes as gettext readers expect.
 */
function moFile( locale ) {
    const pairs = locale
        .filter( ( entry ) => entry.str.length > 0 && entry.str.every( ( s ) => s !== '' ) )
        .map( ( entry ) => [
            Buffer.from( keyOf( entry ) + ( entry.plural === null ? '' : '\u0000' + entry.plural ) ),
            Buffer.from( entry.str.join( '\u0000' ) ),
        ] )
        .sort( ( a, b ) => Buffer.compare( a[ 0 ], b[ 0 ] ) );

    const count  = pairs.length;
    const header = Buffer.alloc( 28 + count * 16 );
    header.writeUInt32LE( 0x950412de, 0 );
    header.writeUInt32LE( 0, 4 );
    header.writeUInt32LE( count, 8 );
    header.writeUInt32LE( 28, 12 );
    header.writeUInt32LE( 28 + count * 8, 16 );
    header.writeUInt32LE( 0, 20 );
    header.writeUInt32LE( 28 + count * 16, 24 );

    const strings = [];
    let offset    = header.length;
    pairs.forEach( ( [ original ], i ) => {
        header.writeUInt32LE( original.length, 28 + i * 8 );
        header.writeUInt32LE( offset, 28 + i * 8 + 4 );
        strings.push( original, Buffer.alloc( 1 ) );
        offset += original.length + 1;
    } );
    pairs.forEach( ( [ , translation ], i ) => {
        header.writeUInt32LE( translation.length, 28 + count * 8 + i * 8 );
        header.writeUInt32LE( offset, 28 + count * 8 + i * 8 + 4 );
        strings.push( translation, Buffer.alloc( 1 ) );
        offset += translation.length + 1;
    } );

    return Buffer.concat( [ header, ...strings ] );
}

function main() {
    const root      = path.resolve( path.dirname( fileURLToPath( import.meta.url ) ), '..' );
    const languages = path.join( root, 'languages' );
    const catalog   = path.join( root, '.cache', 'scripts.pot' );

    if ( ! existsSync( catalog ) ) {
        console.error( 'No .cache/scripts.pot. Run `npm run build` and then `npm run i18n:locales`.' );
        process.exit( 1 );
    }

    // The catalog is extracted from build/ alone, so its references are relative to it.
    const scripts = parsePo( readFileSync( catalog, 'utf8' ) )
        .map( ( entry ) => ( { ...entry, refs: entry.refs.map( ( ref ) => `build/${ ref }` ) } ) );
    const locales = readdirSync( languages )
        .map( ( name ) => name.match( new RegExp( `^${ DOMAIN }-(.+)\\.po$` ) )?.[ 1 ] )
        .filter( Boolean );

    for ( const lang of locales ) {
        for ( const stale of readdirSync( languages ).filter( ( name ) => name.startsWith( `${ DOMAIN }-${ lang }-` ) && name.endsWith( '.json' ) ) ) {
            rmSync( path.join( languages, stale ) );
        }

        const locale = parsePo( readFileSync( path.join( languages, `${ DOMAIN }-${ lang }.po` ), 'utf8' ) );
        writeFileSync( path.join( languages, `${ DOMAIN }-${ lang }.mo` ), moFile( locale ) );

        const files = scriptTranslations( scripts, locale, lang );
        for ( const [ bundle, json ] of Object.entries( files ) ) {
            writeFileSync( path.join( languages, fileNameFor( lang, bundle ) ), JSON.stringify( json ) + '\n' );
        }

        const wanted = scripts.filter( ( entry ) => entry.id !== '' && entry.refs.some( ( ref ) => /\.js(:\d+)?$/.test( ref ) ) ).length;
        const done   = new Set( Object.values( files ).flatMap( ( json ) => Object.keys( json.locale_data.messages ) ) ).size - 1;
        console.log( `${ lang }: ${ Object.keys( files ).length } bundles, ${ done } of ${ wanted } script strings translated` );
    }
}

main();
