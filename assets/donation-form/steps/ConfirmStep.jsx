/** @jsxImportSource preact */

import { formatAmount, frequencyLabel } from '../util/format';
import { coveredFeeCents } from '../state/store';
import { visibleGateways } from '../util/gateways';
import { countryName } from '../../_shared/countries';

export default function ConfirmStep( { state, config, showDonor = true, showGateway = true } ) {

    const v        = state.values;
    const cents    = v.amount_cents || 0;
    const amount   = formatAmount( cents, state.currency );
    const fullName = [ v.profile.first_name, v.profile.last_name ].filter( Boolean ).join( ' ' );

    const freqLabel = frequencyLabel( v.frequency, config.i18n );

    // Zero when unchecked or hidden by a condition, so the shown total always
    // equals what buildPayload charges.
    const fee   = coveredFeeCents( state );
    const total = formatAmount( cents + fee, state.currency );

    // The method the donation would go through, when the form has one to offer.
    const method = visibleGateways( config, state ).find( ( o ) => o.id === state.gateway );

    return (
        <div class="gratora-form__confirm">
            <dl class="gratora-form__summary">
                <div class="gratora-form__summary-row">
                    <dt>{ config.i18n.amount }</dt>
                    <dd class="gratora-form__summary-amount">{ amount }</dd>
                </div>
                { freqLabel && (
                    <div class="gratora-form__summary-row">
                        <dt>{ config.i18n.frequency }</dt>
                        <dd>{ freqLabel }</dd>
                    </div>
                ) }
                { fee > 0 && (
                    <div class="gratora-form__summary-row">
                        <dt>{ config.i18n.fees }</dt>
                        <dd>{ formatAmount( fee, state.currency ) }</dd>
                    </div>
                ) }
                { showDonor && fullName && (
                    <div class="gratora-form__summary-row">
                        <dt>{ config.i18n.donor }</dt>
                        <dd>{ fullName }</dd>
                    </div>
                ) }
                { showDonor && v.email && (
                    <div class="gratora-form__summary-row">
                        <dt>{ config.i18n.email }</dt>
                        <dd>{ v.email }</dd>
                    </div>
                ) }
                { showDonor && v.profile.country && (
                    <div class="gratora-form__summary-row">
                        <dt>{ config.i18n.country }</dt>
                        <dd>{ countryName( v.profile.country ) }</dd>
                    </div>
                ) }
                { showGateway && method && (
                    <div class="gratora-form__summary-row">
                        <dt>{ config.i18n.paymentMethod }</dt>
                        <dd>{ method.label || method.id }</dd>
                    </div>
                ) }
                <div class="gratora-form__summary-row gratora-form__summary-row--total">
                    <dt>{ config.i18n.total }</dt>
                    <dd class="gratora-form__summary-amount">{ total }</dd>
                </div>
            </dl>
        </div>
    );
}
