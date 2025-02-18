( function () {
    'use strict';

    const OPEN_CLASS = 'is-open';
    const FOCUSABLE  = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';
    let lastFocused  = null;

    function open( modal ) {
        if ( ! modal ) return;
        lastFocused = document.activeElement;
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
            const active = document.activeElement;
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
