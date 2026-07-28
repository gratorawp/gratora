// Fails when a @dono/ui component is passed a prop it does not have. React
// drops unknown props silently, so the component just renders wrong.
import { readFileSync, readdirSync, statSync } from 'node:fs';
import { join, dirname, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join( dirname( fileURLToPath( import.meta.url ) ), '..' );
const uiSrc = join( root, 'node_modules/@dono/ui/src/components' );

/** Component name -> Set of accepted props, or null when it spreads. */
function readComponentApis() {
	const apis = new Map();

	for ( const file of readdirSync( uiSrc ) ) {
		if ( ! file.endsWith( '.jsx' ) || file.endsWith( '.stories.jsx' ) ) {
			continue;
		}
		const name = file.replace( /\.jsx$/, '' );
		const src = readFileSync( join( uiSrc, file ), 'utf8' );

		const declared = [
			[ name, src.match( /export default function\s+\w*\s*\(\s*\{([\s\S]*?)\}\s*\)/m ) ],
			...[ ...src.matchAll( /export function\s+(\w+)\s*\(\s*\{([\s\S]*?)\}\s*\)/gm ) ].map( ( m ) => [
				m[ 1 ],
				[ m[ 0 ], m[ 2 ] ],
			] ),
		];

		for ( const [ component, match ] of declared ) {
			if ( ! match ) {
				apis.set( component, null ); // Not a destructuring component; do not guess.
				continue;
			}

			const body = match[ 1 ];
			if ( body.includes( '...' ) ) {
				apis.set( component, null );
				continue;
			}

			apis.set(
				component,
				new Set(
					body
						.split( ',' )
						.map( ( part ) => part.split( '=' )[ 0 ].split( ':' )[ 0 ].trim() )
						.filter( Boolean )
				)
			);
		}
	}

	return apis;
}

function jsxFiles( dir ) {
	const out = [];
	for ( const entry of readdirSync( dir ) ) {
		if ( entry === 'node_modules' || entry === 'build' || entry.startsWith( '.' ) ) {
			continue;
		}
		const full = join( dir, entry );
		if ( statSync( full ).isDirectory() ) {
			out.push( ...jsxFiles( full ) );
		} else if ( full.endsWith( '.jsx' ) ) {
			out.push( full );
		}
	}
	return out;
}

// Regex cannot do this: `foot={ <Btn variant="x"> }` puts a child's attributes
// inside the parent's text. Walk it, counting braces, read names at depth zero.
function attributesOf( src, from ) {
	const props = [];
	let depth = 0;
	let quote = null;

	for ( let i = from; i < src.length; i++ ) {
		const c = src[ i ];

		if ( quote ) {
			if ( c === quote && src[ i - 1 ] !== '\\' ) {
				quote = null;
			}
			continue;
		}
		if ( c === '"' || c === "'" || c === '`' ) {
			quote = c;
			continue;
		}
		if ( c === '{' ) {
			depth++;
			continue;
		}
		if ( c === '}' ) {
			depth--;
			continue;
		}
		if ( depth === 0 && c === '>' ) {
			break;
		}
		if ( depth > 0 ) {
			continue;
		}

		const name = /^([a-zA-Z][\w-]*)\s*=/.exec( src.slice( i ) );
		if ( name && /[\s]/.test( src[ i - 1 ] ) ) {
			props.push( name[ 1 ] );
			i += name[ 0 ].length - 1;
		}
	}

	return props;
}

const apis = readComponentApis();
const problems = [];

for ( const file of jsxFiles( join( root, 'assets' ) ) ) {
	const src = readFileSync( file, 'utf8' );

	// Only components imported straight from @dono/ui are checked. A local
	// wrapper of the same name is a different component with its own API.
	const imported = new Map();
	for ( const m of src.matchAll( /import\s+(\w+)\s+from\s+'@dono\/ui\/components\/(\w+)'/g ) ) {
		imported.set( m[ 1 ], m[ 2 ] );
	}
	// import Switch, { ToggleRow } from '@dono/ui/components/Switch'
	for ( const m of src.matchAll( /import\s+(?:(\w+),\s*)?\{([^}]+)\}\s+from\s+'@dono\/ui\/components\/(\w+)'/g ) ) {
		if ( m[ 1 ] ) {
			imported.set( m[ 1 ], m[ 3 ] );
		}
		for ( const named of m[ 2 ].split( ',' ) ) {
			const [ exported, local ] = named.split( /\s+as\s+/ ).map( ( x ) => x.trim() );
			if ( exported ) {
				imported.set( local || exported, exported );
			}
		}
	}
	if ( imported.size === 0 ) {
		continue;
	}

	for ( const [ local, component ] of imported ) {
		const accepted = apis.get( component );
		if ( ! accepted ) {
			continue; // Spreads props, or we could not read it: not our call to judge.
		}

		for ( const open of src.matchAll( new RegExp( `<${ local }(?=[\\s/>])`, 'g' ) ) ) {
			for ( const prop of attributesOf( src, open.index + open[ 0 ].length ) ) {
				if ( ! accepted.has( prop ) && prop !== 'key' && prop !== 'ref' ) {
					problems.push(
						`${ relative( root, file ) }: <${ local }> does not accept "${ prop }" ` +
							`(${ component } takes: ${ [ ...accepted ].join( ', ' ) })`
					);
				}
			}
		}
	}
}

if ( problems.length > 0 ) {
	console.error( `\n@dono/ui prop check failed (${ problems.length }):\n` );
	problems.forEach( ( p ) => console.error( `  ${ p }` ) );
	console.error( '' );
	process.exit( 1 );
}

console.log( `@dono/ui prop check passed (${ apis.size } components known).` );
