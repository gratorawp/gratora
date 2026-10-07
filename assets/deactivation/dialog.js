( function () {
    const cfg = window.gratoraDeactivation || {};
    let dialog = null;
    let panel = null;
    let deactivateUrl = null;
    let opener = null;
    let sending = false;

    // WordPress redraws the rows when the list is searched, so the link is
    // looked for at each click and not once.
    function rowLink( target ) {
        const link = target.closest( '.deactivate a' );
        const row = link ? link.closest( 'tr[data-plugin]' ) : null;
        return row && row.dataset.plugin === cfg.slug ? link : null;
    }

    function picked() {
        return dialog.querySelector( 'input[name="gratora-deact-reason"]:checked' );
    }

    function box() {
        return dialog.querySelector( '#gratora-deact-comment' );
    }

    function ask() {
        const comment = box();
        // Not every screen asks: the network's does not, and a site can switch the question off.
        if ( ! comment ) return;

        const reason = picked();
        const prompt = reason ? reason.dataset.prompt : '';

        comment.hidden = ! prompt;
        if ( prompt ) {
            // A placeholder is gone once someone types, so the box is named by it as well.
            comment.placeholder = prompt;
            comment.setAttribute( 'aria-label', prompt );
            reason.closest( 'label' ).after( comment );
        }
        dialog.querySelector( '[data-gratora-deact-clear]' ).hidden = ! reason;
    }

    // Said aloud, because the button changes its wording where a screen reader is not looking.
    function say( which ) {
        const status = dialog.querySelector( '#gratora-deact-why-status' );
        if ( status ) status.textContent = which ? status.dataset[ which ] : '';
    }

    function clear() {
        const reason = picked();
        if ( reason ) reason.checked = false;
        if ( box() ) box().value = '';
        ask();
        sync();
    }

    function open( href ) {
        deactivateUrl = href;
        opener = dialog.ownerDocument.activeElement;
        // An answer left from an earlier look is not one given now.
        clear();
        say( '' );
        dialog.hidden = false;
        document.body.classList.add( 'gratora-deact-open' );
        // The dialog itself, not a control in it: a stray space bar on a
        // control would pick a reason or arm the wipe before anyone has read it.
        panel.scrollTop = 0;
        panel.focus( { preventScroll: true } );
    }

    function close() {
        dialog.hidden = true;
        document.body.classList.remove( 'gratora-deact-open' );
        if ( opener && opener.isConnected && opener.focus ) opener.focus();
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

    function post( fields, keepalive ) {
        return fetch( cfg.ajaxUrl, {
            method: 'POST',
            credentials: 'same-origin',
            keepalive,
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams( fields ).toString(),
        } );
    }

    function deactivate() {
        sending = true;
        panel.setAttribute( 'aria-busy', 'true' );
        dialog.querySelector( '[data-gratora-deact-submit]' ).setAttribute( 'aria-disabled', 'true' );

        const reason = picked();
        if ( reason ) {
            const answer = { action: cfg.reasonAction, _wpnonce: cfg.reasonNonce, reason: reason.value };
            if ( reason.dataset.prompt ) answer.comment = box().value;
            // Sent on its way and not waited for: the site passes it to
            // gratora.net, and deactivation must not hang on how long that takes.
            post( answer, true ).catch( function () {} );
        }

        const choice = { action: cfg.action, _wpnonce: cfg.nonce };
        if ( dialog.querySelector( '#gratora-deact-wipe' ).checked ) choice.wipe = '1';
        post( choice, false ).then( leave, leave );
    }

    // The page behind is out of reach while the dialog is open.
    function keepInside( e ) {
        const stops = Array.from( panel.querySelectorAll( 'button, input, textarea, a[href]' ) ).filter( function ( el ) {
            return ! el.closest( '[hidden]' );
        } );
        const at = dialog.ownerDocument.activeElement;

        if ( e.shiftKey && ( at === stops[ 0 ] || at === panel ) ) {
            e.preventDefault();
            stops[ stops.length - 1 ].focus();
        } else if ( ! e.shiftKey && at === stops[ stops.length - 1 ] ) {
            e.preventDefault();
            stops[ 0 ].focus();
        }
    }

    function init() {
        dialog = document.getElementById( 'gratora-deact' );
        if ( ! dialog || ! cfg.slug ) return;
        panel = dialog.querySelector( '.gratora-deact__panel' );

        document.addEventListener( 'click', function ( e ) {
            const link = rowLink( e.target );
            if ( link ) {
                e.preventDefault();
                open( link.href );
            }
        } );

        dialog.addEventListener( 'click', function ( e ) {
            // Once deactivation is under way the choice has gone to the site,
            // and a dialog that closed then would only look like it was called off.
            if ( sending ) return;

            if ( e.target.closest( '[data-gratora-deact-cancel]' ) ) {
                close();
                return;
            }
            if ( e.target.closest( '[data-gratora-deact-clear]' ) ) {
                clear();
                say( 'unsent' );
                dialog.querySelector( 'input[name="gratora-deact-reason"]' ).focus();
                return;
            }
            if ( e.target.closest( '[data-gratora-deact-submit]' ) ) deactivate();
        } );

        dialog.addEventListener( 'change', function ( e ) {
            if ( e.target.name === 'gratora-deact-reason' ) {
                ask();
                sync();
                say( 'sent' );
            }
        } );

        dialog.querySelector( '#gratora-deact-wipe' ).addEventListener( 'change', sync );

        document.addEventListener( 'keydown', function ( e ) {
            if ( dialog.hidden ) return;
            if ( e.key === 'Escape' && ! sending ) close();
            if ( e.key === 'Tab' ) keepInside( e );
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
