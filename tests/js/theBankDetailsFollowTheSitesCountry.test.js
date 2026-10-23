/**
 * A bank in the United States is paid with a routing and an account number;
 * the IBAN and BIC sample stays for everywhere else.
 */

import { bankDetailsSample } from '../../assets/admin/settings/panels/bankDetailsSample';

it( 'asks a site in the United States for a routing and an account number', () => {
    const sample = bankDetailsSample( 'US' );

    expect( sample ).toMatch( /Account name: …\nRouting number: …\nAccount number: …/ );
    expect( sample ).not.toMatch( /IBAN|BIC/ );
} );

it.each( [ 'DE', 'GB', '' ] )( 'keeps the IBAN and BIC for %p', ( country ) => {
    expect( bankDetailsSample( country ) ).toMatch( /Account holder: …\nIBAN: …\nBIC: …/ );
} );
