import { __, sprintf, _n } from '@wordpress/i18n';

export default function DonorCohort( { cohort } ) {
    if ( ! cohort ) {
        return <p className="gratora-panel__empty">{ __( 'No donor activity yet.', 'gratora' ) }</p>;
    }

    const {
        first_time, returning, conversion_pct,
        recurring_active, recurring_new_in_range, recurring_share_pct,
    } = cohort;

    return (
        <div className="gratora-cohort">
            <div className="gratora-cohort__stat">
                <div className="gratora-cohort__label">{ __( 'New donors', 'gratora' ) }</div>
                <div className="gratora-cohort__value">{ first_time }</div>
                <div className="gratora-cohort__sub">
                    { returning > 0
                        ? sprintf(
                            /* translators: 1: number of returning donors, 2: conversion percent */
                            __( '%1$d came back (%2$s%%)', 'gratora' ),
                            returning,
                            conversion_pct === null ? '-' : conversion_pct
                        )
                        : __( 'No repeat donations yet.', 'gratora' ) }
                </div>
            </div>

            <div className="gratora-cohort__divider" aria-hidden="true" />

            <div className="gratora-cohort__stat">
                <div className="gratora-cohort__label">{ __( 'Recurring donors', 'gratora' ) }</div>
                <div className="gratora-cohort__value">{ recurring_active }</div>
                <div className="gratora-cohort__sub">
                    { recurring_new_in_range > 0
                        ? sprintf(
                            /* translators: %d: new recurring plans in this range */
                            _n( '+%d new in range', '+%d new in range', recurring_new_in_range, 'gratora' ),
                            recurring_new_in_range
                        )
                        : __( 'No new plans in range.', 'gratora' ) }
                </div>
            </div>

            <div className="gratora-cohort__share">
                <div className="gratora-cohort__share-label">
                    { sprintf(
                        /* translators: %d: percent of revenue from recurring donors */
                        __( '%d%% of revenue is recurring', 'gratora' ),
                        recurring_share_pct
                    ) }
                </div>
                <div className="gratora-cohort__share-bar">
                    <div
                        className="gratora-cohort__share-fill"
                        style={ { width: `${ Math.min( 100, recurring_share_pct ) }%` } }
                    />
                </div>
            </div>
        </div>
    );
}
