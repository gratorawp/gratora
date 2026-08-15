( function () {
    'use strict';

    const OPEN_CLASS = 'is-open';
    const FOCUSABLE  = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let lastFocused  = null;

    function open( modal ) {
        // Already open: re-entering would record a focus target from inside the
        // modal as the element to restore on close.
        if ( ! modal || modal.classList.contains( OPEN_CLASS ) ) return;
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

    // A donor coming back from their bank never pressed the trigger, and a shut
    // modal hides the only account of a payment they have already made.
    //
    // Which form that is belongs to the form runtime alone: it holds the stash,
    // the reference and the gateway key, and only it knows which form is
    // rendering the outcome. Reaching the same verdict a second time from here
    // is what let the two disagree, in both directions: a modal opened over a
    // form that abstained shows a donor a blank form and no outcome, markers
    // still on the URL for a reload to do it again. So this reveals the claim
    // and never makes one. No claim means no outcome is being rendered
    // anywhere, and the page as it stands beats a modal that cannot say what
    // happened.
    function revealFor( host ) {
        const modal = host && host.closest( '.dono-donate-modal' );
        if ( modal ) open( modal );
    }

    // The claimant marks itself and announces it. Both are read, because the
    // claim can land either side of this script: the runtime boots on
    // DOMContentLoaded and the announcement reaches a listener already
    // registered here, while a script an optimizer held back to the first
    // interaction arrives to find the mark on the page and nothing to hear.
    // RETURN_CLAIMED_EVENT in assets/donation-form/runtime.jsx.
    window.addEventListener( 'dono:donation:return-claimed', ( e ) => {
        revealFor( e.detail && e.detail.host );
    } );

    function revealClaimed() {
        revealFor( document.querySelector( '.dono-donation-form[data-dono-returning]' ) );
    }

    // Deferred a task so a runtime booting on this same event has mounted and
    // marked its claim first.
    function scheduleReveal() {
        window.setTimeout( revealClaimed, 0 );
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
