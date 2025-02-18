import { __, sprintf } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { initials, formatMonth, formatDate, SEGMENT_LABELS } from './helpers';
import { countryName } from '../../../_shared/countries';
import { IconMail, IconMapPin, IconCalendar, IconCopy, IconPhone } from './icons';

function CopyButton( { value, label } ) {
    const [ ok, setOk ] = useState( false );
    const copy = async () => {
        if ( ! value ) return;
        try {
            await navigator.clipboard.writeText( value );
            setOk( true );
            setTimeout( () => setOk( false ), 1200 );
        } catch ( _ ) {}
    };
    return (
        <button type="button" className="dp-id-row__copy" aria-label={ label } onClick={ copy } title={ ok ? __( 'Copied', 'dono' ) : label }>
            <IconCopy width="12" height="12" />
        </button>
    );
}

function IdentityRow( { icon, value, copyable, sub, valClass = '' } ) {
    return (
        <div className="dp-id-row">
            <span className="dp-id-row__ic">{ icon }</span>
            <span className={ `dp-id-row__val ${ valClass }` }>
                { value }
                { sub && <span className="dp-id-row__sub">{ sub }</span> }
            </span>
            { copyable
                ? <CopyButton value={ copyable } label={ __( 'Copy', 'dono' ) } />
                : <span /> }
        </div>
    );
}

export default function IdentityCard( { donor, magicLinkUrl, onMagicLinkRefresh } ) {
    const [ copiedMagic, setCopiedMagic ] = useState( false );
    const isAnon     = donor.is_anonymous;
    const isRedacted = !! donor.redacted_at;

    const avatarText = isAnon
        ? `#${ donor.id.toString( 16 ).toUpperCase().padStart( 2, '0' ) }`
        : isRedacted ? '--' : initials( donor.name );
    const avatarClass = isAnon ? 'dp-avatar is-hash' : isRedacted ? 'dp-avatar is-redacted' : 'dp-avatar';

    const segment = donor.segment || 'other';
    const statusLabel = isRedacted
        ? __( 'Redacted', 'dono' )
        : isAnon
            ? __( 'Anonymous', 'dono' )
            : SEGMENT_LABELS[ segment ] || segment;
    const statusClass = isRedacted ? 'is-redact' : isAnon ? 'is-anon' : '';

    const typeLabel = donor.donor_type === 'organization'
        ? __( 'Organisation', 'dono' )
        : donor.donor_type === 'household'
            ? __( 'Household', 'dono' )
            : __( 'Individual', 'dono' );

    const copyMagic = async () => {
        if ( ! magicLinkUrl ) return;
        try {
            await navigator.clipboard.writeText( magicLinkUrl );
            setCopiedMagic( true );
            setTimeout( () => setCopiedMagic( false ), 1500 );
        } catch ( _ ) {}
    };

    return (
        <div className="dp-card dp-identity">
            <div className="dp-card__body">
                <div className="dp-id-top">
                    <span className={ avatarClass }>{ avatarText }</span>
                    <div className="dp-id-name-block">
                        <div className={ `dp-id-name ${ isRedacted ? 'is-redacted' : isAnon ? 'is-anon' : '' }` }>
                            { donor.name }
                        </div>
                        <div className="dp-id-sub">
                            <span>{ typeLabel }</span>
                            { donor.company && (
                                <>
                                    <span style={ { color: '#cbd0d5' } }>·</span>
                                    <span>{ donor.company }</span>
                                </>
                            ) }
                        </div>
                        <span className={ `dp-id-status ${ statusClass }` }>{ statusLabel }</span>
                    </div>
                </div>

                <div className="dp-id-rows">
                    { donor.email && (
                        <IdentityRow
                            icon={ <IconMail width="14" height="14" /> }
                            value={ donor.email }
                            copyable={ donor.email }
                            valClass="is-mono"
                        />
                    ) }
                    { isRedacted && (
                        <IdentityRow
                            icon={ <IconMail width="14" height="14" /> }
                            value={ __( 'Redacted', 'dono' ) }
                            valClass="is-redacted"
                        />
                    ) }
                    { donor.phone && ! isRedacted && (
                        <IdentityRow
                            icon={ <IconPhone width="14" height="14" /> }
                            value={ donor.phone }
                            copyable={ donor.phone }
                            valClass="is-mono"
                        />
                    ) }
                    { donor.address && ! isRedacted && (
                        <IdentityRow
                            icon={ <IconMapPin width="14" height="14" /> }
                            value={ <span style={ { whiteSpace: 'pre-wrap' } }>{ donor.address }</span> }
                            sub={ donor.country ? countryName( donor.country ) : null }
                        />
                    ) }
                    { donor.country && ! donor.address && (
                        <IdentityRow
                            icon={ <IconMapPin width="14" height="14" /> }
                            value={ countryName( donor.country ) }
                        />
                    ) }
                    { donor.first_donation_at && (
                        <IdentityRow
                            icon={ <IconCalendar width="14" height="14" /> }
                            value={ sprintf( /* translators: %s: month */ __( 'Donor since %s', 'dono' ), formatMonth( donor.first_donation_at ) ) }
                            sub={ donor.last_donation_at ? sprintf( /* translators: %s: date */ __( 'Last donation %s', 'dono' ), formatDate( donor.last_donation_at ) ) : null }
                        />
                    ) }
                </div>

                { magicLinkUrl && ! isRedacted && (
                    <div className="dp-id-magic">
                        <div className="dp-magic-link" title={ magicLinkUrl }>
                            <span className="dp-magic-link__url">{ magicLinkUrl }</span>
                            <button type="button" className="dp-magic-link__copy" onClick={ copyMagic }>
                                { copiedMagic ? __( 'Copied', 'dono' ) : __( 'Copy', 'dono' ) }
                            </button>
                        </div>
                        <div className="dp-id-magic__help">
                            { __( 'Donor self-service link. Reset history is not exposed.', 'dono' ) }
                        </div>
                    </div>
                ) }
            </div>
        </div>
    );
}
