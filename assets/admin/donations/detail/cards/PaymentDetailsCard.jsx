import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';

import { formatAmount } from '../helpers';
import { IconCopy } from '../icons';

function MonoCopy( { value, label } ) {
    const [ ok, setOk ] = useState( false );
    if ( ! value ) return <span className="muted">-</span>;
    return (
        <span className="dd-mono-copy">
            <span className="mono">{ value }</span>
            <button
                type="button"
                className="dd-mono-copy__btn"
                aria-label={ label }
                title={ ok ? __( 'Copied', 'dono-fundraising-platform' ) : label }
                onClick={ async () => {
                    try {
                        await navigator.clipboard.writeText( value );
                        setOk( true );
                        setTimeout( () => setOk( false ), 1200 );
                    } catch ( _ ) {}
                } }
            >
                <IconCopy width="12" height="12" />
            </button>
        </span>
    );
}

export default function PaymentDetailsCard( { donation } ) {
    const hasFees = donation.fee_cents > 0;

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                <div className="dd-kv" style={ { gridTemplateColumns: '160px 1fr' } }>
                    <div className="dd-kv__row">
                        <div className="dd-kv__lbl">{ __( 'Intent ID', 'dono-fundraising-platform' ) }</div>
                        <div className="dd-kv__val"><MonoCopy value={ donation.gateway_intent_id } label={ __( 'Copy intent ID', 'dono-fundraising-platform' ) } /></div>
                    </div>
                    <div className="dd-kv__row">
                        <div className="dd-kv__lbl">{ __( 'Transaction ID', 'dono-fundraising-platform' ) }</div>
                        <div className="dd-kv__val"><MonoCopy value={ donation.gateway_txn_id } label={ __( 'Copy transaction ID', 'dono-fundraising-platform' ) } /></div>
                    </div>
                </div>

                { hasFees && (
                    <div style={ { marginTop: 18 } }>
                        <div className="dd-section-lbl">{ __( 'Fees breakdown', 'dono-fundraising-platform' ) }</div>
                        <div className="dd-fees">
                            <div className="dd-fees__cell">
                                <div className="dd-fees__lbl">{ __( 'Gross', 'dono-fundraising-platform' ) }</div>
                                <div className="dd-fees__val num">{ formatAmount( donation.amount_cents, donation.currency ) }</div>
                            </div>
                            <div className="dd-fees__cell dd-fees__cell--neg">
                                <div className="dd-fees__lbl">{ __( 'Gateway fee', 'dono-fundraising-platform' ) }</div>
                                <div className="dd-fees__val num">- { formatAmount( donation.fee_cents, donation.currency ) }</div>
                            </div>
                            <div className="dd-fees__cell dd-fees__cell--net">
                                <div className="dd-fees__lbl">{ __( 'Net', 'dono-fundraising-platform' ) }</div>
                                <div className="dd-fees__val num">{ formatAmount( donation.net_cents, donation.currency ) }</div>
                            </div>
                        </div>
                    </div>
                ) }
            </div>
        </div>
    );
}
