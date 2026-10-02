/**
 * The WordPress packages webpack bundles, rather than loads from wp.*, look
 * their strings up in WordPress's own text domain, and no script on a Gratora
 * screen carries those translations: a list's filters, paging and column menu
 * stayed English in every language. A domain on each call makes them the
 * plugin's strings, which its language files translate, and which
 * WordPress.org already lists because it extracts from the built files.
 */
const babel = require( '@babel/core' );

const DOMAIN = 'gratora-donation-platform';

// How many arguments each function takes ahead of its text domain.
const BEFORE_DOMAIN = { __: 1, _x: 2, _n: 3, _nx: 4 };

function addDomain( { types: t } ) {
    return {
        visitor: {
            CallExpression( path ) {
                const callee = path.get( 'callee' );
                if ( ! callee.isIdentifier() ) {
                    return;
                }

                const binding = path.scope.getBinding( callee.node.name );
                if ( ! binding?.path.isImportSpecifier() || binding.path.parent.source.value !== '@wordpress/i18n' ) {
                    return;
                }

                const imported = binding.path.node.imported;
                if ( path.node.arguments.length === BEFORE_DOMAIN[ imported.name ?? imported.value ] ) {
                    path.node.arguments.push( t.stringLiteral( DOMAIN ) );
                }
            },
        },
    };
}

function rewrite( source ) {
    if ( ! source.includes( '@wordpress/i18n' ) ) {
        return source;
    }

    return babel.transformSync( source, {
        babelrc:    false,
        configFile: false,
        sourceType: 'module',
        plugins:    [ addDomain ],
    } ).code;
}

module.exports = function ( source ) {
    return rewrite( source );
};

module.exports.rewrite = rewrite;
