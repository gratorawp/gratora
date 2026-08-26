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
    const channelLabel = CHANNEL_LABEL[ donation.channel ] || donation.channel || __( 'Direct', 'giveflow-fundraising-campaigns' );

    return (
        <div className="dd-card">
            <div className="dd-card__body">
                <div className="dd-kv">
                    <KvRow label={ __( 'Amount', 'giveflow-fundraising-campaigns' ) }>
                        <span className="dd-kv__val--big num">{ formatAmount( donation.amount_cents, donation.currency ) }</span>
                    </KvRow>

                    <KvRow label={ __( 'Fee & net', 'giveflow-fundraising-campaigns' ) }>
                        <span className="mono">
                            { formatAmount( donation.fee_cents, donation.currency ) } { __( 'fee', 'giveflow-fundraising-campaigns' ) }
                            { ' · ' }
                            <strong style={ { color: 'var(--dd-accent-dark, #34306b)' } }>
                                { formatAmount( donation.net_cents, donation.currency ) } { __( 'net', 'giveflow-fundraising-campaigns' ) }
                            </strong>
                        </span>
                    </KvRow>

                    <KvRow label={ __( 'Gateway', 'giveflow-fundraising-campaigns' ) }>
                        <span style={ { textTransform: 'capitalize' } }>{ donation.gateway }</span>
                    </KvRow>

                    { donation.payment_method_brand && donation.payment_method_last4 && (
                        <KvRow label={ __( 'Payment method', 'giveflow-fundraising-campaigns' ) }>
                            <span style={ { textTransform: 'capitalize' } }>{ donation.payment_method_brand }</span>
                            { ' ' }{ __( 'ending', 'giveflow-fundraising-campaigns' ) }{ ' ' }
                            <span className="mono">{ donation.payment_method_last4 }</span>
                        </KvRow>
                    ) }

                    { donation.campaign && (
                        <KvRow label={ __( 'Campaign', 'giveflow-fundraising-campaigns' ) }>
                            <a href={ campaignHref( donation.campaign.id ) }>{ donation.campaign.title }</a>
                        </KvRow>
                    ) }

                    { donation.fund && (
                        <KvRow label={ __( 'Fund', 'giveflow-fundraising-campaigns' ) }>
                            { donation.fund.name }
                        </KvRow>
                    ) }

                    { donation.form && (
                        <KvRow label={ __( 'Form', 'giveflow-fundraising-campaigns' ) }>
                            <a href={ formEditorHref( donation.form.id ) }>{ donation.form.title }</a>
                        </KvRow>
                    ) }

                    <KvRow label={ __( 'Channel', 'giveflow-fundraising-campaigns' ) }>
                        <span className="dd-channel-chip">{ channelLabel }</span>
                    </KvRow>

                    <KvRow label={ __( 'Donated', 'giveflow-fundraising-campaigns' ) }>
                        { donation.paid_at ? timeAgo( donation.paid_at ) : __( 'not paid', 'giveflow-fundraising-campaigns' ) }
                        <span className="dd-kv__sub">{ formatDateTime( donation.paid_at || donation.created_at ) }</span>
                    </KvRow>

                    { donation.frequency && donation.frequency !== 'one_time' && (
                        <KvRow label={ __( 'Frequency', 'giveflow-fundraising-campaigns' ) }>
                            <span style={ { textTransform: 'capitalize' } }>{ donation.frequency }</span>
                            { donation.recurring_plan_id && (
                                <span className="dd-kv__sub">{ __( 'Part of a recurring plan', 'giveflow-fundraising-campaigns' ) }</span>
                            ) }
                        </KvRow>
                    ) }

                    { donation.note_to_org && (
                        <KvRow label={ __( 'Donor note', 'giveflow-fundraising-campaigns' ) }>
                            <em>&quot;{ donation.note_to_org }&quot;</em>
                        </KvRow>
                    ) }

                    { donation.custom_data && Object.keys( donation.custom_data ).length > 0 && (
                        <KvRow label={ __( 'Form fields', 'giveflow-fundraising-campaigns' ) }>
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
