import { __, sprintf } from '@wordpress/i18n';

import { formatAmount, formatDate, initials } from '../helpers';

export default function DonorCard( { donor, donationName, isAnonymous, onOpenDonor } ) {
    if ( ! donor ) {
        return (
            <div className="dd-card">
                <div className="dd-card__body">
                    <p className="dd-empty">{ __( 'No donor record is attached to this donation.', 'gratora' ) }</p>
                </div>
            </div>
        );
    }

    const lifetime = donor.lifetime || { count: 0, total_cents: 0 };
    const anonNote = isAnonymous
        ? __( 'This donor asked not to be named on public donor lists. Their details are unchanged here and on their receipt.', 'gratora' )
        : null;

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                <div className={ `dd-donor-row${ donor.redacted ? ' is-faceless' : '' }` }>
                    { ! donor.redacted && (
                        <span className="dd-avatar">{ initials( donor.name ) }</span>
                    ) }
                    <div className="dd-donor-row__main">
                        <div className="dd-donor-row__name">{ donor.name }</div>
                        { donationName && donationName !== donor.name && (
                            <div className="dd-donor-row__lifetime">
                                { sprintf( /* translators: %s: donor-provided name */ __( 'Given as "%s" on this donation', 'gratora' ), donationName ) }
                            </div>
                        ) }
                        { anonNote && <div className="dd-donor-row__lifetime">{ anonNote }</div> }
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
                                    __( '%1$s donations · %2$s lifetime · first donation %3$s', 'gratora' ),
                                    lifetime.count.toLocaleString(),
                                    formatAmount( lifetime.total_cents ),
                                    formatDate( donor.first_donation_at )
                                )
                                : __( 'First donation from this donor', 'gratora' ) }
                        </div>
                    </div>
                    <button
                        type="button"
                        className="dd-donor-row__link"
                        onClick={ () => onOpenDonor?.( donor.id ) }
                    >
                        { __( 'Open donor →', 'gratora' ) }
                    </button>
                </div>
            </div>
        </div>
    );
}
