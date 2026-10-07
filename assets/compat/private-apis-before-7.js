/**
 * Core and its add-ons each carry their own copy of WordPress's data views
 * package, and more than one of them can load on a screen. WordPress before 7.0
 * throws when a package asks for its private APIs a second time, so the second
 * copy never runs. WordPress 7.0 answers again, and so does this.
 *
 * Printed as it is, right after the script it changes.
 */
( ( wp ) => {
    const name = '__dangerousOptInToUnstableAPIsOnlyForCoreModules';
    const optIn = wp?.privateApis?.[ name ];
    if ( typeof optIn !== 'function' ) {
        return;
    }

    const answered = new Map();

    wp.privateApis = {
        ...wp.privateApis,
        [ name ]: ( consent, moduleName ) => {
            if ( ! answered.has( moduleName ) ) {
                answered.set( moduleName, optIn( consent, moduleName ) );
            }

            return answered.get( moduleName );
        },
    };
} )( window.wp );
