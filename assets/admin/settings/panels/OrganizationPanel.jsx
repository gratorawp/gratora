import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import { COUNTRIES, isEuCountry } from '../../../_shared/countries';

export default function OrganizationPanel( { s } ) {
    const country = s.value( 'country', '' );
    const showVat = isEuCountry( country );

    // Onboarding persists structured siblings (address_line1, city, ...)
    // alongside address_lines. If the user edits in onboarding then later
    // here, the siblings stay stale unless we surface them. We seed
    // address_lines from the structured record when the array is empty so
    // pre-onboarded values show up in the inputs.
    const stored = Array.isArray( s.record.address_lines ) ? s.record.address_lines : [];
    const seeded = stored.length === 0 && ( s.record.address_line1 || s.record.city || s.record.postal_code )
        ? [
            String( s.record.address_line1 || '' ),
            String( s.record.postal_code   || '' ),
            String( s.record.city          || '' ),
        ]
        : stored;
    const addressLines = seeded;

    // When the panel writes a line, mirror the value back into the matching
    // structured sibling so the two views never drift out of sync.
    const updateAddressLine = ( idx, value ) => {
        const next = [ ...addressLines ];
        next[ idx ] = value;
        const patch = { address_lines: next };
        if ( idx === 0 ) patch.address_line1 = value;
        if ( idx === 1 ) patch.postal_code   = value;
        if ( idx === 2 ) patch.city          = value;
        s.edit( patch );
    };

    return (
        <div className="giveflow-panel">
            <Card
                title={ __( 'Identity', 'giveflow-fundraising-campaigns' ) }
                meta={ __( 'Used by receipts and footer', 'giveflow-fundraising-campaigns' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Legal name', 'giveflow-fundraising-campaigns' ) }
                    required
                    help={ __( 'The entity that legally receives donations.', 'giveflow-fundraising-campaigns' ) }
                >
                    <input type="text" className="giveflow-input" { ...s.bind( 'legal_name' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Display name', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Donor-facing name in subject lines and headers.', 'giveflow-fundraising-campaigns' ) }
                >
                    <input type="text" className="giveflow-input" { ...s.bind( 'name' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Contact email', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Printed on receipts, so donors know where to reply.', 'giveflow-fundraising-campaigns' ) }
                >
                    <input type="email" className="giveflow-input" { ...s.bind( 'email' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Address', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'Optional. Receipts print it when set; donors claiming tax relief usually need it.', 'giveflow-fundraising-campaigns' ) }
                >
                    <div className="giveflow-stack-12">
                        <input
                            type="text"
                            className="giveflow-input"
                            placeholder={ __( 'Street', 'giveflow-fundraising-campaigns' ) }
                            value={ addressLines[ 0 ] || '' }
                            onChange={ ( e ) => updateAddressLine( 0, e.target.value ) }
                        />
                        <div className="giveflow-grid-2-eq">
                            <input
                                type="text"
                                className="giveflow-input"
                                placeholder={ __( 'Postcode', 'giveflow-fundraising-campaigns' ) }
                                value={ addressLines[ 1 ] || '' }
                                onChange={ ( e ) => updateAddressLine( 1, e.target.value ) }
                            />
                            <input
                                type="text"
                                className="giveflow-input"
                                placeholder={ __( 'City', 'giveflow-fundraising-campaigns' ) }
                                value={ addressLines[ 2 ] || '' }
                                onChange={ ( e ) => updateAddressLine( 2, e.target.value ) }
                            />
                        </div>
                    </div>
                </FormRow>

                <FormRow
                    label={ __( 'Country', 'giveflow-fundraising-campaigns' ) }
                    required
                    help={ __( 'Drives tax-ID format and VAT visibility.', 'giveflow-fundraising-campaigns' ) }
                >
                    <select
                        className="giveflow-select"
                        value={ country }
                        onChange={ ( e ) => s.setValue( 'country' )( e.target.value ) }
                    >
                        <option value="">{ __( 'Select a country', 'giveflow-fundraising-campaigns' ) }</option>
                        { COUNTRIES.map( ( c ) => (
                            <option key={ c.code } value={ c.code }>{ c.name }</option>
                        ) ) }
                    </select>
                </FormRow>

                <FormRow
                    label={ __( 'Tax ID / EU VAT', 'giveflow-fundraising-campaigns' ) }
                    help={ __( 'VIES validation is not performed.', 'giveflow-fundraising-campaigns' ) }
                >
                    <div className="giveflow-grid-2-eq">
                        <input
                            type="text"
                            className="giveflow-input giveflow-input--mono"
                            placeholder={ __( 'Tax number', 'giveflow-fundraising-campaigns' ) }
                            { ...s.bind( 'tax_id' ) }
                        />
                        { showVat && (
                            <input
                                type="text"
                                className="giveflow-input giveflow-input--mono"
                                placeholder={ __( 'EU VAT ID', 'giveflow-fundraising-campaigns' ) }
                                { ...s.bind( 'vat_id' ) }
                            />
                        ) }
                    </div>
                </FormRow>
            </Card>
        </div>
    );
}
