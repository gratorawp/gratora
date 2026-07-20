import { __, sprintf } from '@wordpress/i18n';

import { formatAmount, formatDate, initials } from '../helpers';

export default function DonorCard( { donor, donationName, isAnonymous, onOpenDonor } ) {
    if ( isAnonymous || ! donor ) {
        return (
            <div className="dd-card">
                <div className="dd-card__body">
                    <p className="dd-empty">{ __( 'Anonymous donor: name and contact information were not collected.', 'dono' ) }</p>
                </div>
            </div>
        );
    }

    const lifetime = donor.lifetime || { count: 0, total_cents: 0 };

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                <div className="dd-donor-row">
                    <span className="dd-avatar">{ initials( donor.name ) }</span>
                    <div className="dd-donor-row__main">
                        <div className="dd-donor-row__name">{ donor.name }</div>
                        { donationName && donationName !== donor.name && (
                            <div className="dd-donor-row__lifetime">
                                { sprintf( /* translators: %s: donor-provided name */ __( 'Given as "%s" on this donation', 'dono' ), donationName ) }
                            </div>
                        ) }
                        { donor.email && (
                            <a className="dd-donor-row__email" href={ `mailto:${ donor.email }` }>{ donor.email }</a>
                        ) }
                        { donor.phone && (
                            <div className="dd-donor-row__phone mono">{ donor.phone }</div>
                        ) }
                        { donor.address && (
                            <div className="dd-donor-row__addr">{ donor.address }</div>
                        ) }
                        <div className="dd-donor-row__lifetime">
                            { lifetime.count > 0
                                ? sprintf(
                                    /* translators: 1: donation count, 2: lifetime amount, 3: first donation date */
                                    __( '%1$s donations · %2$s lifetime · first donation %3$s', 'dono' ),
                                    lifetime.count.toLocaleString(),
                                    formatAmount( lifetime.total_cents ),
                                    formatDate( donor.first_donation_at )
                                )
                                : __( 'First donation from this donor', 'dono' ) }
                        </div>
                    </div>
                    <button
                        type="button"
                        className="dd-donor-row__link"
                        onClick={ () => onOpenDonor?.( donor.id ) }
                    >
                        { __( 'Open donor →', 'dono' ) }
                    </button>
                </div>
            </div>
        </div>
    );
}
