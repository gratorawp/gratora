import { __ } from '@wordpress/i18n';

import { formatDateTime } from '../helpers';
import { countryName } from '../../../../_shared/countries';

export default function MetadataCard( { donation } ) {
    return (
        <div className="dd-rail-card">
            <div className="dd-rail-card__head">
                <span className="dd-rail-card__title">{ __( 'Metadata', 'dono-fundraising-platform' ) }</span>
            </div>
            <div className="dd-rail-card__body">
                <div className="dd-rail-stat">
                    <span className="dd-rail-stat__lbl">{ __( 'Created', 'dono-fundraising-platform' ) }</span>
                    <span className="dd-rail-stat__val" style={ { fontSize: 12.5 } }>{ formatDateTime( donation.created_at ) }</span>
                </div>
                { donation.frequency && (
                    <div className="dd-rail-stat">
                        <span className="dd-rail-stat__lbl">{ __( 'Frequency', 'dono-fundraising-platform' ) }</span>
                        <span className="dd-rail-stat__val" style={ { textTransform: 'capitalize' } }>
                            { donation.frequency.replace( '_', ' ' ) }
                        </span>
                    </div>
                ) }
                { donation.country && (
                    <div className="dd-rail-stat">
                        <span className="dd-rail-stat__lbl">{ __( 'Donor country', 'dono-fundraising-platform' ) }</span>
                        <span className="dd-rail-stat__val">{ countryName( donation.country ) }</span>
                    </div>
                ) }
            </div>
        </div>
    );
}
