import { __, sprintf, _n } from '@wordpress/i18n';

export default function DonorCohort( { cohort } ) {
    if ( ! cohort ) {
        return <p className="giveflow-panel__empty">{ __( 'No donor activity yet.', 'giveflow-fundraising-campaigns' ) }</p>;
    }

    const {
        first_time, returning, conversion_pct,
        recurring_active, recurring_new_in_range, recurring_share_pct,
    } = cohort;

    return (
        <div className="giveflow-cohort">
            <div className="giveflow-cohort__stat">
                <div className="giveflow-cohort__label">{ __( 'New donors', 'giveflow-fundraising-campaigns' ) }</div>
                <div className="giveflow-cohort__value">{ first_time }</div>
                <div className="giveflow-cohort__sub">
                    { returning > 0
                        ? sprintf(
                            /* translators: 1: number of returning donors, 2: conversion percent */
                            __( '%1$d came back (%2$s%%)', 'giveflow-fundraising-campaigns' ),
                            returning,
                            conversion_pct === null ? '-' : conversion_pct
                        )
                        : __( 'No repeat donations yet.', 'giveflow-fundraising-campaigns' ) }
                </div>
            </div>

            <div className="giveflow-cohort__divider" aria-hidden="true" />

            <div className="giveflow-cohort__stat">
                <div className="giveflow-cohort__label">{ __( 'Recurring donors', 'giveflow-fundraising-campaigns' ) }</div>
                <div className="giveflow-cohort__value">{ recurring_active }</div>
                <div className="giveflow-cohort__sub">
                    { recurring_new_in_range > 0
                        ? sprintf(
                            /* translators: %d: new recurring plans in this range */
                            _n( '+%d new in range', '+%d new in range', recurring_new_in_range, 'giveflow-fundraising-campaigns' ),
                            recurring_new_in_range
                        )
                        : __( 'No new plans in range.', 'giveflow-fundraising-campaigns' ) }
                </div>
            </div>

            <div className="giveflow-cohort__share">
                <div className="giveflow-cohort__share-label">
                    { sprintf(
                        /* translators: %d: percent of revenue from recurring donors */
                        __( '%d%% of revenue is recurring', 'giveflow-fundraising-campaigns' ),
                        recurring_share_pct
                    ) }
                </div>
                <div className="giveflow-cohort__share-bar">
                    <div
                        className="giveflow-cohort__share-fill"
                        style={ { width: `${ Math.min( 100, recurring_share_pct ) }%` } }
                    />
                </div>
            </div>
        </div>
    );
}
