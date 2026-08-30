import { __, sprintf, _n } from '@wordpress/i18n';

export default function DonorCohort( { cohort } ) {
    if ( ! cohort ) {
        return <p className="fundkit-panel__empty">{ __( 'No donor activity yet.', 'fundkit-fundraising-campaigns' ) }</p>;
    }

    const {
        first_time, returning, conversion_pct,
        recurring_active, recurring_new_in_range, recurring_share_pct,
    } = cohort;

    return (
        <div className="fundkit-cohort">
            <div className="fundkit-cohort__stat">
                <div className="fundkit-cohort__label">{ __( 'New donors', 'fundkit-fundraising-campaigns' ) }</div>
                <div className="fundkit-cohort__value">{ first_time }</div>
                <div className="fundkit-cohort__sub">
                    { returning > 0
                        ? sprintf(
                            /* translators: 1: number of returning donors, 2: conversion percent */
                            __( '%1$d came back (%2$s%%)', 'fundkit-fundraising-campaigns' ),
                            returning,
                            conversion_pct === null ? '-' : conversion_pct
                        )
                        : __( 'No repeat donations yet.', 'fundkit-fundraising-campaigns' ) }
                </div>
            </div>

            <div className="fundkit-cohort__divider" aria-hidden="true" />

            <div className="fundkit-cohort__stat">
                <div className="fundkit-cohort__label">{ __( 'Recurring donors', 'fundkit-fundraising-campaigns' ) }</div>
                <div className="fundkit-cohort__value">{ recurring_active }</div>
                <div className="fundkit-cohort__sub">
                    { recurring_new_in_range > 0
                        ? sprintf(
                            /* translators: %d: new recurring plans in this range */
                            _n( '+%d new in range', '+%d new in range', recurring_new_in_range, 'fundkit-fundraising-campaigns' ),
                            recurring_new_in_range
                        )
                        : __( 'No new plans in range.', 'fundkit-fundraising-campaigns' ) }
                </div>
            </div>

            <div className="fundkit-cohort__share">
                <div className="fundkit-cohort__share-label">
                    { sprintf(
                        /* translators: %d: percent of revenue from recurring donors */
                        __( '%d%% of revenue is recurring', 'fundkit-fundraising-campaigns' ),
                        recurring_share_pct
                    ) }
                </div>
                <div className="fundkit-cohort__share-bar">
                    <div
                        className="fundkit-cohort__share-fill"
                        style={ { width: `${ Math.min( 100, recurring_share_pct ) }%` } }
                    />
                </div>
            </div>
        </div>
    );
}
