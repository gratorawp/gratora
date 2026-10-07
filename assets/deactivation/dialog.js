( function () {
    const cfg = window.gratoraDeactivation || {};
    let dialog = null;
    let deactivateUrl = null;
    let opener = null;
    let sending = false;

    function rowLink() {
        const row = document.querySelector( 'tr[data-plugin="' + cfg.slug + '"]' );
        return row ? row.querySelector( 'a[href*="action=deactivate"]' ) : null;
    }

    function picked() {
        return dialog.querySelector( 'input[name="gratora-deact-reason"]:checked' );
    }

    /**
     * The box sits under the reason it belongs to, and only a reason with a
     * prompt has one.
     */
    function ask() {
        const reason = picked();
        const box = dialog.querySelector( '#gratora-deact-comment' );
        const prompt = reason ? reason.dataset.prompt : '';

        box.hidden = ! prompt;
        if ( prompt ) {
            box.placeholder = prompt;
            reason.closest( 'label' ).after( box );
        }
        dialog.querySelector( '[data-gratora-deact-clear]' ).hidden = ! reason;
    }

    function clear() {
        const reason = picked();
        if ( reason ) reason.checked = false;
        dialog.querySelector( '#gratora-deact-comment' ).value = '';
        ask();
        sync();
    }

    function open( href ) {
        deactivateUrl = href;
        opener = dialog.ownerDocument.activeElement;
        // An answer left from an earlier look is not one given now.
        clear();
        dialog.hidden = false;
        document.body.classList.add( 'gratora-deact-open' );
        sync();
        // Cancel, not the checkbox: opening on a destructive control means a
        // stray space bar arms the wipe before anyone has read the dialog.
        dialog.querySelector( 'button[data-gratora-deact-cancel]' ).focus();
    }

    function close() {
        dialog.hidden = true;
        document.body.classList.remove( 'gratora-deact-open' );
        if ( opener && opener.focus ) opener.focus();
    }

    /**
     * The dialog only looks dangerous once someone asks for the dangerous
     * thing, and the button says which of the two it is about to do.
     */
    function sync() {
        const wipe = dialog.querySelector( '#gratora-deact-wipe' ).checked;
        const submit = dialog.querySelector( '[data-gratora-deact-submit]' );

        dialog.classList.toggle( 'is-danger', wipe );
        dialog.querySelector( '#gratora-deact-consequence' ).hidden = ! wipe;
        if ( wipe ) {
            submit.textContent = submit.dataset.labelWipe;
        } else {
            submit.textContent = picked() ? submit.dataset.labelSend : submit.dataset.labelKeep;
        }
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
        if ( dialog.querySelector( '#gratora-deact-wipe' ).checked ) body.set( 'wipe', '1' );

        const reason = picked();
        if ( reason ) {
            body.set( 'reason', reason.value );
            if ( reason.dataset.prompt ) body.set( 'comment', dialog.querySelector( '#gratora-deact-comment' ).value );
        }

        fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
        } ).then( done, done );
    }

    function init() {
        dialog = document.getElementById( 'gratora-deact' );
        if ( ! dialog || ! cfg.slug ) return;

        const link = rowLink();
        if ( link ) {
            link.addEventListener( 'click', function ( e ) {
                e.preventDefault();
                open( link.href );
            } );
        }

        dialog.addEventListener( 'click', function ( e ) {
            if ( e.target.closest( '[data-gratora-deact-cancel]' ) ) {
                close();
                return;
            }
            if ( e.target.closest( '[data-gratora-deact-clear]' ) ) {
                clear();
                dialog.querySelector( 'input[name="gratora-deact-reason"]' ).focus();
                return;
            }
            const submit = e.target.closest( '[data-gratora-deact-submit]' );
            if ( submit && ! sending ) {
                // The site tells gratora.net before it answers, which can take a moment.
                sending = true;
                submit.disabled = true;
                send( leave );
            }
        } );

        dialog.addEventListener( 'change', function ( e ) {
            if ( e.target.name === 'gratora-deact-reason' ) {
                ask();
                sync();
            }
        } );

        dialog.querySelector( '#gratora-deact-wipe' ).addEventListener( 'change', sync );

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
