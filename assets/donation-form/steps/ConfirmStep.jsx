/** @jsxImportSource preact */

import { formatAmount, frequencyLabel } from '../util/format';
import { coveredFeeCents } from '../state/store';

export default function ConfirmStep( { state, config, showDonor = true, showGateway = true } ) {

    const v        = state.values;
    const cents    = v.amount_cents || 0;
    const amount   = formatAmount( cents, state.currency );
    const fullName = [ v.profile.first_name, v.profile.last_name ].filter( Boolean ).join( ' ' );

    const freqLabel = frequencyLabel( v.frequency, config.i18n );

    // Zero when unchecked or when a condition hides the cover-fees field, so
    // the shown total always equals what buildPayload charges.
    const fee   = coveredFeeCents( state );
    const total = formatAmount( cents + fee, state.currency );

    return (
        <div class="dono-form__confirm">
            <dl class="dono-form__summary">
                <div class="dono-form__summary-row">
                    <dt>{ config.i18n.amount }</dt>
                    <dd class="dono-form__summary-amount">{ amount }</dd>
                </div>
                { freqLabel && (
                    <div class="dono-form__summary-row">
                        <dt>{ config.i18n.frequency }</dt>
                        <dd>{ freqLabel }</dd>
                    </div>
                ) }
                { fee > 0 && (
                    <div class="dono-form__summary-row">
                        <dt>{ config.i18n.fees }</dt>
                        <dd>{ formatAmount( fee, state.currency ) }</dd>
                    </div>
                ) }
                { showDonor && fullName && (
                    <div class="dono-form__summary-row">
                        <dt>{ config.i18n.donor }</dt>
                        <dd>{ fullName }</dd>
                    </div>
                ) }
                { showDonor && (
                    <div class="dono-form__summary-row">
                        <dt>{ config.i18n.email }</dt>
                        <dd>{ v.email }</dd>
                    </div>
                ) }
                { showDonor && v.profile.country && (
                    <div class="dono-form__summary-row">
                        <dt>{ config.i18n.country }</dt>
                        <dd>{ v.profile.country }</dd>
                    </div>
                ) }
                { showGateway && (
                    <div class="dono-form__summary-row">
                        <dt>{ config.i18n.paymentMethod }</dt>
                        <dd>{ gatewayLabel( state.gateway, config ) }</dd>
                    </div>
                ) }
                <div class="dono-form__summary-row dono-form__summary-row--total">
                    <dt>{ config.i18n.total }</dt>
                    <dd class="dono-form__summary-amount">{ total }</dd>
                </div>
            </dl>
        </div>
    );
}

function gatewayLabel( id, config ) {
    const opts = config?.gateways?.options;
    if ( Array.isArray( opts ) ) {
        const found = opts.find( ( o ) => o.id === id );
        if ( found && found.label ) return found.label;
    }
    return id;
}
