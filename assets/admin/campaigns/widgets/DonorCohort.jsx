import { __, sprintf, _n } from '@wordpress/i18n';

export default function DonorCohort( { cohort } ) {
    if ( ! cohort ) {
        return <p className="dono-panel__empty">{ __( 'No donor activity yet.', 'dono' ) }</p>;
    }

    const {
        first_time, returning, conversion_pct,
        recurring_active, recurring_new_in_range, recurring_share_pct,
    } = cohort;

    return (
        <div className="dono-cohort">
            <div className="dono-cohort__stat">
                <div className="dono-cohort__label">{ __( 'New donors', 'dono' ) }</div>
                <div className="dono-cohort__value">{ first_time }</div>
                <div className="dono-cohort__sub">
                    { returning > 0
                        ? sprintf(
                            /* translators: 1: number of returning donors, 2: conversion percent */
                            __( '%1$d came back (%2$s%%)', 'dono' ),
                            returning,
                            conversion_pct === null ? '-' : conversion_pct
                        )
                        : __( 'No repeat donations yet.', 'dono' ) }
                </div>
            </div>

            <div className="dono-cohort__divider" aria-hidden="true" />

            <div className="dono-cohort__stat">
                <div className="dono-cohort__label">{ __( 'Recurring donors', 'dono' ) }</div>
                <div className="dono-cohort__value">{ recurring_active }</div>
                <div className="dono-cohort__sub">
                    { recurring_new_in_range > 0
                        ? sprintf(
                            /* translators: %d: new recurring plans in this range */
                            _n( '+%d new in range', '+%d new in range', recurring_new_in_range, 'dono' ),
                            recurring_new_in_range
                        )
                        : __( 'No new plans in range.', 'dono' ) }
                </div>
            </div>

            <div className="dono-cohort__share">
                <div className="dono-cohort__share-label">
                    { sprintf(
                        /* translators: %d: percent of revenue from recurring donors */
                        __( '%d%% of revenue is recurring', 'dono' ),
                        recurring_share_pct
                    ) }
                </div>
                <div className="dono-cohort__share-bar">
                    <div
                        className="dono-cohort__share-fill"
                        style={ { width: `${ Math.min( 100, recurring_share_pct ) }%` } }
                    />
                </div>
            </div>
        </div>
    );
}
