( function () {
    const cfg = window.donoDeactivation || {};
    let dialog = null;
    let deactivateUrl = null;

    function rowLink() {
        const row = document.querySelector( 'tr[data-plugin="' + cfg.slug + '"]' );
        return row ? row.querySelector( 'a[href*="action=deactivate"]' ) : null;
    }

    function open( href ) {
        deactivateUrl = href;
        dialog.hidden = false;
        document.body.classList.add( 'dono-deact-open' );
        dialog.querySelector( '#dono-deact-wipe' ).focus();
    }

    function close() {
        dialog.hidden = true;
        document.body.classList.remove( 'dono-deact-open' );
    }

    function leave() {
        // The href carries WordPress's own nonce, so deactivation still goes
        // through core's handler rather than anything of ours.
        if ( deactivateUrl ) window.location.assign( deactivateUrl );
    }

    function send( done ) {
        const body = new URLSearchParams();
        body.set( 'action', cfg.action );
        body.set( '_wpnonce', cfg.nonce );
        if ( dialog.querySelector( '#dono-deact-wipe' ).checked ) body.set( 'wipe', '1' );

        fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        } ).then( done, done );
    }

    function init() {
        dialog = document.getElementById( 'dono-deact' );
        if ( ! dialog || ! cfg.slug ) return;

        const link = rowLink();
        if ( link ) {
            link.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                open( link.href );
            } );
        }

        dialog.addEventListener( 'click', function ( e ) {
            if ( e.target.closest( '[data-dono-deact-cancel]' ) ) {
                close();
                return;
            }
            if ( e.target.closest( '[data-dono-deact-submit]' ) ) {
                send( leave );
            }
        } );

        document.addEventListener( 'keydown', function ( e ) {
            if ( e.key === 'Escape' && ! dialog.hidden ) close();
        } );
    }

    // admin_print_footer_scripts fires before admin_footer-{hook}, so this
    // script runs while the dialog markup is still unparsed.
    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', init );
    } else {
        init();
    }
}() );
