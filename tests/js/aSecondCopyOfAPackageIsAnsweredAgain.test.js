/**
 * Core and its add-ons each carry their own copy of WordPress's data views
 * package, and more than one of them can load on a screen. WordPress before
 * 7.0 throws when a package asks for its private APIs a second time, so the
 * second copy never runs; 7.0 answers again. On the older one, a small script
 * gives the first answer again.
 */

const fs = require( 'fs' );
const path = require( 'path' );

const source = fs.readFileSync( path.join( __dirname, '../../assets/compat/private-apis-before-7.js' ), 'utf8' );

const CONSENT = 'I acknowledge private features are not for use in themes or plugins and doing so will break in the next version of WordPress.';
const OPT_IN = '__dangerousOptInToUnstableAPIsOnlyForCoreModules';

// As WordPress 6.9 answers: only its own packages, and each of them once.
function wordpressBefore7() {
    const known = [ '@wordpress/dataviews', '@wordpress/components' ];
    const registered = [];
    const lock = () => {};
    const unlock = () => {};

    return {
        [ OPT_IN ]( consent, moduleName ) {
            if ( ! known.includes( moduleName ) ) {
                throw new Error( `You tried to opt-in to unstable APIs as module "${ moduleName }".` );
            }
            if ( registered.includes( moduleName ) ) {
                throw new Error( `You tried to opt-in to unstable APIs as module "${ moduleName }" which is already registered.` );
            }
            if ( consent !== CONSENT ) {
                throw new Error( 'You tried to opt-in to unstable APIs without confirming you know the consequences.' );
            }
            registered.push( moduleName );

            return { lock, unlock };
        },
    };
}

function loaded( wp ) {
    window.wp = wp;
    // eslint-disable-next-line no-new-func -- the file is a script WordPress prints, not a module
    new Function( source )();

    return window.wp;
}

test( 'the fixture throws at a second copy, as WordPress before 7.0 does', () => {
    const { privateApis } = { privateApis: wordpressBefore7() };

    privateApis[ OPT_IN ]( CONSENT, '@wordpress/dataviews' );

    expect( () => privateApis[ OPT_IN ]( CONSENT, '@wordpress/dataviews' ) ).toThrow( 'already registered' );
} );

test( 'a second copy of a package is given the first answer', () => {
    const { privateApis } = loaded( { privateApis: wordpressBefore7() } );

    const first = privateApis[ OPT_IN ]( CONSENT, '@wordpress/dataviews' );
    const second = privateApis[ OPT_IN ]( CONSENT, '@wordpress/dataviews' );

    expect( second ).toBe( first );
    expect( typeof second.unlock ).toBe( 'function' );
} );

test( 'each package has an answer of its own', () => {
    const { privateApis } = loaded( { privateApis: wordpressBefore7() } );

    const views = privateApis[ OPT_IN ]( CONSENT, '@wordpress/dataviews' );
    const components = privateApis[ OPT_IN ]( CONSENT, '@wordpress/components' );

    expect( components ).not.toBe( views );
    expect( privateApis[ OPT_IN ]( CONSENT, '@wordpress/components' ) ).toBe( components );
} );

test( 'a package WordPress does not know is refused as before, every time', () => {
    const { privateApis } = loaded( { privateApis: wordpressBefore7() } );

    expect( () => privateApis[ OPT_IN ]( CONSENT, 'somebody-elses-plugin' ) ).toThrow( 'somebody-elses-plugin' );
    expect( () => privateApis[ OPT_IN ]( CONSENT, 'somebody-elses-plugin' ) ).toThrow( 'somebody-elses-plugin' );
} );

test( 'asking without the consent is refused as before', () => {
    const { privateApis } = loaded( { privateApis: wordpressBefore7() } );

    expect( () => privateApis[ OPT_IN ]( 'yes', '@wordpress/dataviews' ) ).toThrow( 'consequences' );
} );

test( 'a page that has no private APIs is left as it is', () => {
    expect( loaded( {} ) ).toEqual( {} );
} );
