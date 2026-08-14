( function () {
    'use strict';

    const OPEN_CLASS = 'is-open';
    const FOCUSABLE  = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let lastFocused  = null;

    function open( modal ) {
        if ( ! modal ) return;
        lastFocused = modal.ownerDocument.activeElement;
        modal.hidden = false;
        // Forced reflow so the CSS transition picks up the class toggle.
        // eslint-disable-next-line no-unused-expressions
        modal.offsetHeight;
        modal.classList.add( OPEN_CLASS );
        document.body.classList.add( 'dono-modal-open' );

        // Prefer the first control in the form over the close button.
        const body = modal.querySelector( '.dono-donate-modal__body' );
        const focusTarget = ( body && body.querySelector( FOCUSABLE ) )
            || modal.querySelector( FOCUSABLE );
        if ( focusTarget ) focusTarget.focus();
    }

    function close( modal ) {
        if ( ! modal ) return;
        modal.classList.remove( OPEN_CLASS );
        modal.hidden = true;
        document.body.classList.remove( 'dono-modal-open' );
        if ( lastFocused && typeof lastFocused.focus === 'function' ) {
            lastFocused.focus();
            lastFocused = null;
        }
    }

    function findModal( button ) {
        const slug = button.dataset.formSlug;
        if ( ! slug ) return null;
        // Prefer a sibling modal in the same block; fall back to any matching modal.
        const block = button.closest( '.dono-block--donate-button' );
        return ( block && block.querySelector( `.dono-donate-modal[data-form-slug="${ slug }"]` ) )
            || document.querySelector( `.dono-donate-modal[data-form-slug="${ slug }"]` );
    }

    document.addEventListener( 'click', ( e ) => {
        const button = e.target.closest( '.dono-donate-button[data-form-slug]' );
        if ( button && ! button.classList.contains( 'is-disabled' ) ) {
            e.preventDefault();
            open( findModal( button ) );
            return;
        }

        const closer = e.target.closest( '[data-dono-modal-close]' );
        if ( closer ) {
            close( closer.closest( '.dono-donate-modal' ) );
        }
    } );

    // The form this browser last submitted, when the URL says the donor is
    // coming back from a redirect gateway.
    //
    // Returns null when this is not a return, '' when it is one whose form
    // cannot be named.
    function returningFormId() {
        const params = new URLSearchParams( window.location.search );
        if ( params.get( 'dono_return' ) !== '1' ) return null;
        if ( ! params.get( 'payment_intent_client_secret' ) ) return null;

        let pending;
        try {
            const raw = window.sessionStorage.getItem( 'dono:pending-donation' );
            pending = raw ? JSON.parse( raw ) : {};
        } catch ( e ) {
            // Storage refused or unreadable: nothing names a form, and a single
            // form on the page is still unambiguous.
            return '';
        }

        // The same rule detectStripeReturn() abstains on, because the two have
        // to agree: a reference this tab did not stash belongs to some other
        // submission, no form will claim it, and revealing it would open a
        // modal holding an empty form and no outcome.
        const own = String( pending.reference || '' );
        const ref = params.get( 'dono_ref' ) || '';
        if ( own && ref && own !== ref ) return null;

        return String( pending.formKey || '' );
    }

    // Read now rather than when the modal is revealed: this script evaluates in
    // the footer, before the form runtime boots on DOMContentLoaded and strips
    // the markers off the URL.
    const returningForm = returningFormId();

    // A donor coming back from their bank never pressed the trigger, and a shut
    // modal hides the only account of a payment they have already made.
    function revealReturn() {
        // The form runtime marks the form it claimed the return for. Trusted
        // above everything else: it names the element the outcome is rendering
        // into, and it is still there when this script runs late enough to have
        // missed the markers on the URL.
        const claimed = document.querySelector( '.dono-donation-form[data-dono-returning]' );
        if ( ! claimed && returningForm === null ) return;

        // No marker, so decide it the way the runtime does. It falls through to
        // every form when the stash names none that is on the page, and mounts
        // in document order, so the first form is the one that claims. Picking
        // any other leaves the outcome rendered inside a modal nobody opens.
        const host = claimed
            || ( returningForm && document.getElementById( returningForm ) )
            || document.querySelector( '.dono-donation-form' );

        const modal = host && host.closest( '.dono-donate-modal' );
        if ( modal ) open( modal );
    }

    // Deferred a task so the runtime has mounted and marked its claim; reading
    // the URL already happened above, where the markers still exist.
    function scheduleReveal() {
        window.setTimeout( revealReturn, 0 );
    }

    if ( document.readyState === 'loading' ) {
        document.addEventListener( 'DOMContentLoaded', scheduleReveal );
    } else {
        scheduleReveal();
    }

    document.addEventListener( 'keydown', ( e ) => {
        if ( e.key === 'Escape' ) {
            const openModal = document.querySelector( '.dono-donate-modal.' + OPEN_CLASS );
            if ( openModal ) close( openModal );
            return;
        }

        // Trap Tab inside the open modal so focus can't wander to the
        // scroll-locked page hidden behind the backdrop.
        if ( e.key === 'Tab' ) {
            const openModal = document.querySelector( '.dono-donate-modal.' + OPEN_CLASS );
            if ( ! openModal ) return;
            const panel = openModal.querySelector( '.dono-donate-modal__panel' ) || openModal;
            const nodes = Array.prototype.slice
                .call( panel.querySelectorAll( FOCUSABLE ) )
                .filter( ( n ) => n.offsetParent !== null );
            if ( ! nodes.length ) return;
            const first  = nodes[ 0 ];
            const last   = nodes[ nodes.length - 1 ];
            const active = panel.ownerDocument.activeElement;
            if ( e.shiftKey && ( active === first || ! panel.contains( active ) ) ) {
                e.preventDefault();
                last.focus();
            } else if ( ! e.shiftKey && active === last ) {
                e.preventDefault();
                first.focus();
            }
        }
    } );
}() );
