import { __ } from '@wordpress/i18n';

import { IconAlert } from './icons';

function Banner( { variant, children } ) {
    return (
        <div className={ `dd-banner dd-banner--${ variant }` } role="status">
            <IconAlert className="dd-banner__icon" width="20" height="20" />
            <div className="dd-banner__body">{ children }</div>
        </div>
    );
}

export default function Banners( { donation } ) {
    const isTest      = !! donation.is_test;
    const isDisputed  = donation.status === 'disputed';
    const isFailed    = donation.status === 'failed';

    if ( ! isTest && ! isDisputed && ! isFailed ) return null;

    return (
        <>
            { isTest && (
                <Banner variant="warn">
                    <strong>{ __( 'Test-mode donation.', 'dono' ) }</strong>{ ' ' }
                    { __( 'No real money changed hands.', 'dono' ) }
                </Banner>
            ) }
            { isDisputed && (
                <Banner variant="danger">
                    <strong>{ __( 'Chargeback in progress.', 'dono' ) }</strong>{ ' ' }
                    { __( 'Review the dispute in your gateway dashboard before refunding.', 'dono' ) }
                </Banner>
            ) }
            { isFailed && donation.failure_reason && (
                <Banner variant="danger">
                    <strong>{ __( 'Payment failed.', 'dono' ) }</strong>{ ' ' }
                    { donation.failure_reason }
                </Banner>
            ) }
        </>
    );
}
