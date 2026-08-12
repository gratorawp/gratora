import { useState } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { Button } from '@wordpress/components';
import { __ } from '@wordpress/i18n';

import { detailHref } from '../../_shared/format';
import { notify } from '../../_shared/notify';

const ICON = {
    plus: (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 5v14M5 12h14" />
        </svg>
    ),
    donations: (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="6" width="18" height="14" rx="2" />
            <path d="M3 10h18M8 14h2M8 17h6" />
        </svg>
    ),
    donors: (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
            <path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2" />
            <circle cx="9" cy="7" r="4" />
        </svg>
    ),
    settings: (
        <svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true" fill="none" stroke="currentColor" strokeWidth="1.75" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="3" />
            <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
        </svg>
    ),
};

const adminUrl = ( params ) =>
    `${ window.location.pathname }?${ new URLSearchParams( params ).toString() }`;

export default function QuickActions() {
    const [ creating, setCreating ] = useState( false );

    const onNewCampaign = async () => {
        setCreating( true );
        try {
            const c = await apiFetch( {
                path:   '/dono/v1/admin/campaigns',
                method: 'POST',
                data:   { title: __( 'Untitled campaign', 'dono-fundraising-platform' ) },
            } );
            window.location.href = detailHref( c.id, 'overview' );
        } catch ( err ) {
            setCreating( false );
            notify.error( err?.message || __( 'Could not create the campaign. Please try again.', 'dono-fundraising-platform' ) );
        }
    };

    return (
        <div className="dono-quick-actions">
            <Button variant="primary" onClick={ onNewCampaign } isBusy={ creating } disabled={ creating } className="dono-quick-actions__primary">
                { ICON.plus } { __( 'New campaign', 'dono-fundraising-platform' ) }
            </Button>

            <a className="dono-quick-actions__item" href={ adminUrl( { page: 'dono-donations' } ) }>
                { ICON.donations } { __( 'Donations', 'dono-fundraising-platform' ) }
            </a>
            <a className="dono-quick-actions__item" href={ adminUrl( { page: 'dono-donors' } ) }>
                { ICON.donors } { __( 'Donors', 'dono-fundraising-platform' ) }
            </a>
            <a className="dono-quick-actions__item" href={ adminUrl( { page: 'dono-settings' } ) }>
                { ICON.settings } { __( 'Settings', 'dono-fundraising-platform' ) }
            </a>
        </div>
    );
}
