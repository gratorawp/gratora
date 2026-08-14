// What a redirect gateway needs to pick a donation back up when the donor
// returns on a fresh page load. Session-scoped, so it dies with the tab rather
// than following the donor around.
export const PENDING_KEY = 'dono:pending-donation';

export function rememberPending( data, values, hostId = '' ) {
    try {
        window.sessionStorage.setItem( PENDING_KEY, JSON.stringify( {
            reference:   data.reference,
            statusToken: data.status_token,
            gateway:     data.gateway || '',
            // The id of the form element the donor submitted from. One key
            // serves the whole page, so the reference proves that a submission
            // happened, never which form made it.
            formKey:     hostId || '',
            // Enough to tell the donor what they gave when they land back here
            // from their bank on a fresh page, where nothing else survives.
            amountCents: data.amount_cents,
            currency:    data.currency,
            frequency:   values?.frequency || '',
            email:       values?.email || '',
        } ) );
    } catch ( e ) {
        // Private browsing can refuse storage. The donation is still made and
        // the webhook still settles it; only the return screen is lost.
    }
}

export function readPending() {
    try {
        const raw = window.sessionStorage.getItem( PENDING_KEY );
        return raw ? JSON.parse( raw ) : {};
    } catch ( e ) {
        return {};
    }
}

// Which form on the page may claim the return sitting on the URL. Claiming one
// strips the markers for everybody else, so a page carrying two forms would
// otherwise thank the donor on whichever mounted first.
//
// A stash naming a form that is no longer on the page falls through to any
// caller: showing the outcome somewhere unexpected still beats showing it
// nowhere.
export function ownsPendingReturn( hostId ) {
    const owner = String( readPending().formKey || '' );
    if ( owner === '' ) return true;
    if ( owner === String( hostId || '' ) ) return true;

    return document.getElementById( owner ) === null;
}
