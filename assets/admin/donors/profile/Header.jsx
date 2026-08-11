import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { formatMonth } from './helpers';
import { IconRotate, IconAlert } from './icons';

function HeadChip( { children, tone = 'ok', mono = false } ) {
    const classes = [ 'head-chip' ];
    if ( tone !== 'ok' )    classes.push( `is-${ tone }` );
    return (
        <span className={ classes.join( ' ' ) }>
            { tone === 'ok' && <span className="head-chip__dot" /> }
            { mono ? <span className="mono" style={ { fontSize: '11px' } }>{ children }</span> : children }
        </span>
    );
}

function Banner( { kind, message, onAction } ) {
    const cls = kind === 'redacted' ? 'banner banner--info' : 'banner banner--warn';
    return (
        <div className={ cls } role="status">
            <span className="banner__icon">
                <IconAlert width="20" height="20" />
            </span>
            <div className="banner__body">{ message }</div>
            { onAction && (
                <div className="banner__actions">
                    <button type="button" className="btn btn--sm" onClick={ onAction }>
                        { __( 'Open Recurring →', 'dono' ) }
                    </button>
                </div>
            ) }
        </div>
    );
}

export default function Header( { donor, banners, recurring, onBack, onEdit, onTabSwitch } ) {

    const isAnon     = donor.is_anonymous;
    const isRedacted = !! donor.redacted_at;
    const activeCount = recurring?.plans?.filter( ( p ) => p.status === 'active' ).length || 0;

    return (
        <header className="dp-head">
            <div className="dp-crumbs">
                <button type="button" onClick={ onBack }>{ __( 'Dono', 'dono' ) }</button>
                <span className="sep">›</span>
                <button type="button" onClick={ onBack }>{ __( 'Donors', 'dono' ) }</button>
                <span className="sep">›</span>
                <span>{ donor.name }</span>
            </div>

            <div className="dp-page-head">
                <div className="dp-page-head__left">
                    <h1 className={ isRedacted ? 'is-redacted' : isAnon ? 'is-anon' : '' }>
                        { donor.name }
                    </h1>
                    <div className="dp-page-head__chips">
                        { donor.first_donation_at && (
                            <HeadChip>
                                { sprintf( /* translators: %s: month */ __( 'Donor since %s', 'dono' ), formatMonth( donor.first_donation_at ) ) }
                            </HeadChip>
                        ) }
                        { activeCount > 0 && (
                            <HeadChip tone="violet">
                                <IconRotate className="ic" width="11" height="11" />
                                { activeCount === 1
                                    ? __( '1 active plan', 'dono' )
                                    : sprintf( /* translators: %d: count */ __( '%d active plans', 'dono' ), activeCount ) }
                            </HeadChip>
                        ) }
                        <HeadChip tone="gray" mono>{ donor.reference }</HeadChip>
                        { isRedacted && <HeadChip tone="gray">{ __( 'Redacted', 'dono' ) }</HeadChip> }
                    </div>
                </div>
                <div className="dp-page-head__actions">
                    <button type="button" className="btn" onClick={ onEdit }>
                        { __( 'Edit details', 'dono' ) }
                    </button>
                </div>
            </div>

            { banners?.map( ( b ) => (
                <Banner
                    key={ b.kind }
                    kind={ b.kind }
                    message={ b.message }
                    onAction={ b.kind === 'past_due' && onTabSwitch ? () => onTabSwitch( 'recurring' ) : null }
                />
            ) ) }
        </header>
    );
}
