import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import apiFetch from '@wordpress/api-fetch';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import Btn from '../../_shared/components/Btn';
import { ToggleRow } from '../../_shared/components/Switch';
import { downloadFile } from '../../_shared/download';
import { notify } from '../../_shared/notify';

// The UK tax year runs 6 April to 5 April, and a claim period sits inside one.
function taxYearBounds( today = new Date() ) {
    const y = today.getUTCFullYear();
    const startYear = ( today.getUTCMonth() > 3 || ( today.getUTCMonth() === 3 && today.getUTCDate() >= 6 ) )
        ? y : y - 1;
    return { from: `${ startYear }-04-06`, to: `${ startYear + 1 }-04-05` };
}

function pounds( cents ) {
    return new Intl.NumberFormat( undefined, { style: 'currency', currency: 'GBP' } )
        .format( ( cents || 0 ) / 100 );
}

export default function GiftAidPanel( { s } ) {
    const bounds = taxYearBounds();
    const [ from, setFrom ]       = useState( bounds.from );
    const [ to, setTo ]           = useState( bounds.to );
    const [ summary, setSummary ] = useState( null );
    const [ busy, setBusy ]       = useState( false );

    const enabled = !! s.value( 'enabled', false );

    const check = async () => {
        setBusy( true );
        try {
            const path = `/dono/v1/admin/gift-aid/summary?from=${ encodeURIComponent( from ) }&to=${ encodeURIComponent( to ) }`;
            setSummary( await apiFetch( { path } ) );
        } catch ( e ) {
            setSummary( null );
            notify.error( e?.message || __( 'Could not read the claim.', 'dono' ) );
        } finally {
            setBusy( false );
        }
    };

    const download = () => downloadFile(
        `/dono/v1/admin/gift-aid/export?from=${ encodeURIComponent( from ) }&to=${ encodeURIComponent( to ) }`,
        `dono-gift-aid-${ from }-to-${ to }.csv`,
    ).catch( ( e ) => notify.error( e?.message || __( 'Could not download the schedule.', 'dono' ) ) );

    return (
        <div className="dono-panel">
            <Card
                title={ __( 'Gift Aid', 'dono' ) }
                sub={ __( 'UK charities can reclaim 25p from HMRC for every £1 given by a UK taxpayer who makes a declaration.', 'dono' ) }
                edited={ s.isDirty }
            >
                <ToggleRow
                    title={ __( 'Offer Gift Aid on donation forms', 'dono' ) }
                    sub={ __( 'Adds the declaration to forms that include the Gift Aid block. Only sterling donations from individuals are claimable.', 'dono' ) }
                    checked={ enabled }
                    onChange={ s.setValue( 'enabled' ) }
                />

                <FormRow
                    label={ __( 'HMRC charity reference', 'dono' ) }
                    help={ __( 'The reference HMRC issued when your charity registered for Gift Aid. Kept with your claims.', 'dono' ) }
                >
                    <input
                        type="text"
                        className="dono-input"
                        style={ { maxWidth: 220 } }
                        value={ s.value( 'charity_reference', '' ) }
                        onChange={ ( e ) => s.edit( { charity_reference: e.target.value } ) }
                        placeholder={ __( 'e.g. AB12345', 'dono' ) }
                    />
                </FormRow>

                <FormRow
                    label={ __( 'Declaration wording', 'dono' ) }
                    help={ __( 'Leave blank to use HMRC’s model wording, which is what a declaration is expected to say. Change it only if your own wording has been checked.', 'dono' ) }
                >
                    <textarea
                        className="dono-input"
                        rows={ 4 }
                        value={ s.value( 'statement', '' ) }
                        onChange={ ( e ) => s.edit( { statement: e.target.value } ) }
                        placeholder={ __( 'HMRC model wording', 'dono' ) }
                    />
                </FormRow>
            </Card>

            <Card
                title={ __( 'Claim', 'dono' ) }
                sub={ __( 'The schedule you upload to Charities Online. Check it before you submit: HMRC rejects a whole schedule on one bad row.', 'dono' ) }
            >
                { ! enabled && (
                    <p className="dono-muted">
                        { __( 'Gift Aid is off, so nothing is being recorded as claimable.', 'dono' ) }
                    </p>
                ) }

                <FormRow label={ __( 'Period', 'dono' ) } help={ __( 'Defaults to the current UK tax year.', 'dono' ) }>
                    <div style={ { display: 'flex', gap: 8, alignItems: 'center', flexWrap: 'wrap' } }>
                        <input
                            type="date"
                            className="dono-input"
                            style={ { maxWidth: 170 } }
                            value={ from }
                            onChange={ ( e ) => { setFrom( e.target.value ); setSummary( null ); } }
                        />
                        <span className="dono-muted">{ __( 'to', 'dono' ) }</span>
                        <input
                            type="date"
                            className="dono-input"
                            style={ { maxWidth: 170 } }
                            value={ to }
                            onChange={ ( e ) => { setTo( e.target.value ); setSummary( null ); } }
                        />
                        <Btn onClick={ check } disabled={ busy }>
                            { busy ? __( 'Checking…', 'dono' ) : __( 'Check claim', 'dono' ) }
                        </Btn>
                    </div>
                </FormRow>

                { summary && (
                    <FormRow label={ __( 'This period', 'dono' ) }>
                        <div>
                            <p>
                                { sprintf(
                                    /* translators: 1: number of donations, 2: total given, 3: amount reclaimable */
                                    __( '%1$d donations totalling %2$s. You can reclaim %3$s.', 'dono' ),
                                    summary.rows,
                                    pounds( summary.amount_cents ),
                                    pounds( summary.reclaim_cents ),
                                ) }
                            </p>
                            { summary.skipped > 0 && (
                                <p className="dono-muted">
                                    { sprintf(
                                        /* translators: %d: number of donations left out of the claim */
                                        __( '%d left out: a missing house name or number, a missing postcode, or fully refunded. Fill in the donor’s address to include them.', 'dono' ),
                                        summary.skipped,
                                    ) }
                                </p>
                            ) }
                            { summary.rows > 0 && (
                                <p style={ { marginTop: 12 } }>
                                    <Btn onClick={ download }>
                                        { __( 'Download schedule (CSV)', 'dono' ) }
                                    </Btn>
                                </p>
                            ) }
                        </div>
                    </FormRow>
                ) }
            </Card>
        </div>
    );
}
