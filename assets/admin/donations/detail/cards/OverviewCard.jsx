import { __ } from '@wordpress/i18n';
import { formatAmount, formatDateTime, timeAgo, CHANNEL_LABEL } from '../helpers';
import { detailHref as campaignHref, formEditorHref } from '../../../_shared/format';

function KvRow( { label, children, strike = false } ) {
    return (
        <div className={ `dd-kv__row${ strike ? ' is-strike' : '' }` }>
            <div className="dd-kv__lbl">{ label }</div>
            <div className="dd-kv__val">{ children }</div>
        </div>
    );
}

export default function OverviewCard( { donation } ) {
    const channelLabel = CHANNEL_LABEL[ donation.channel ] || donation.channel || __( 'Direct', 'dono' );

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                <div className="dd-kv">
                    <KvRow label={ __( 'Amount', 'dono' ) }>
                        <span className="dd-kv__val--big num">{ formatAmount( donation.amount_cents, donation.currency ) }</span>
                    </KvRow>

                    <KvRow label={ __( 'Fee & net', 'dono' ) }>
                        <span className="mono">
                            { formatAmount( donation.fee_cents, donation.currency ) } { __( 'fee', 'dono' ) }
                            { ' · ' }
                            <strong style={ { color: 'var(--dd-accent-dark, #14693a)' } }>
                                { formatAmount( donation.net_cents, donation.currency ) } { __( 'net', 'dono' ) }
                            </strong>
                        </span>
                    </KvRow>

                    <KvRow label={ __( 'Gateway', 'dono' ) }>
                        <span style={ { textTransform: 'capitalize' } }>{ donation.gateway }</span>
                    </KvRow>

                    { donation.payment_method_brand && donation.payment_method_last4 && (
                        <KvRow label={ __( 'Payment method', 'dono' ) }>
                            <span style={ { textTransform: 'capitalize' } }>{ donation.payment_method_brand }</span>
                            { ' ' }{ __( 'ending', 'dono' ) }{ ' ' }
                            <span className="mono">{ donation.payment_method_last4 }</span>
                        </KvRow>
                    ) }

                    { donation.campaign && (
                        <KvRow label={ __( 'Campaign', 'dono' ) }>
                            <a href={ campaignHref( donation.campaign.id ) }>{ donation.campaign.title }</a>
                        </KvRow>
                    ) }

                    { donation.form && (
                        <KvRow label={ __( 'Form', 'dono' ) }>
                            <a href={ formEditorHref( donation.form.id ) }>{ donation.form.title }</a>
                        </KvRow>
                    ) }

                    <KvRow label={ __( 'Channel', 'dono' ) }>
                        <span className="dd-channel-chip">{ channelLabel }</span>
                    </KvRow>

                    <KvRow label={ __( 'Donated', 'dono' ) }>
                        { donation.paid_at ? timeAgo( donation.paid_at ) : __( 'not paid', 'dono' ) }
                        <span className="dd-kv__sub">{ formatDateTime( donation.paid_at || donation.created_at ) }</span>
                    </KvRow>

                    { donation.frequency && donation.frequency !== 'one_time' && (
                        <KvRow label={ __( 'Frequency', 'dono' ) }>
                            <span style={ { textTransform: 'capitalize' } }>{ donation.frequency }</span>
                            { donation.recurring_plan_id && (
                                <span className="dd-kv__sub">{ __( 'Part of a recurring plan', 'dono' ) }</span>
                            ) }
                        </KvRow>
                    ) }

                    { donation.note_to_org && (
                        <KvRow label={ __( 'Donor note', 'dono' ) }>
                            <em>&quot;{ donation.note_to_org }&quot;</em>
                        </KvRow>
                    ) }

                    { donation.custom_data && Object.keys( donation.custom_data ).length > 0 && (
                        <KvRow label={ __( 'Form fields', 'dono' ) }>
                            <div className="dd-kv__customs">
                                { Object.entries( donation.custom_data ).map( ( [ k, val ] ) => (
                                    <div key={ k } className="dd-kv__custom">
                                        <span className="dd-kv__sub">
                                            { ( donation.custom_field_labels && donation.custom_field_labels[ k ] ) || k }
                                        </span>
                                        <span>{ Array.isArray( val ) ? val.join( ', ' ) : String( val ) }</span>
                                    </div>
                                ) ) }
                            </div>
                        </KvRow>
                    ) }
                </div>
            </div>
        </div>
    );
}
