// The live list and the bin, as one control.
//
// Shared because both screens render it: a copy in each drifts, and the two
// halves of a switch disagreeing about which one you are on is worse than no
// switch. Only the Trash segment carries a count; the live list already states
// its own total in the page head beside this.

import { __ } from '@wordpress/i18n';
import { addQueryArgs } from '@wordpress/url';

import { tablistKeyDown } from '../_shared/tablistKeys';

function IconList() {
    return (
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <path d="M2 3h12v2H2zM2 7h12v2H2zM2 11h12v2H2z" fill="currentColor" />
        </svg>
    );
}

function IconTrash() {
    return (
        <svg viewBox="0 0 16 16" width="14" height="14" aria-hidden="true">
            <path d="M6 2h4l1 1h3v2H2V3h3zM3 6h10l-1 8H4z" fill="currentColor" />
        </svg>
    );
}

function href( view ) {
    return addQueryArgs( window.location.pathname, {
        page: 'gratora-donations',
        ...( view === 'trash' ? { view: 'trash' } : {} ),
    } );
}

export default function ViewSwitch( { active, trashedCount = 0 } ) {
    const go = ( id ) => { window.location.href = href( id ); };

    return (
        <div
            className="gratora-view-toggle"
            role="tablist"
            tabIndex={ -1 }
            aria-label={ __( 'Donation views', 'gratora-donation-platform' ) }
            onKeyDown={ ( e ) => tablistKeyDown( e, [ 'list', 'trash' ], active, go ) }
        >
            <a
                href={ href( 'list' ) }
                role="tab"
                aria-selected={ active === 'list' }
                tabIndex={ active === 'list' ? 0 : -1 }
                className={ `gratora-cmp-toggle${ active === 'list' ? ' is-active' : '' }` }
            >
                <IconList />
                { __( 'Donations', 'gratora-donation-platform' ) }
            </a>
            <a
                href={ href( 'trash' ) }
                role="tab"
                aria-selected={ active === 'trash' }
                tabIndex={ active === 'trash' ? 0 : -1 }
                className={ `gratora-cmp-toggle${ active === 'trash' ? ' is-active' : '' }` }
            >
                <IconTrash />
                { __( 'Trash', 'gratora-donation-platform' ) }
                { trashedCount > 0 && (
                    <span className="gratora-cmp-toggle__badge">{ trashedCount }</span>
                ) }
            </a>
        </div>
    );
}
