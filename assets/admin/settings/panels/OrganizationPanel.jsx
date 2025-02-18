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
        <div className="dono-panel">
            <Card
                title={ __( 'Identity', 'dono' ) }
                meta={ __( 'Used by receipts and footer', 'dono' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Legal name', 'dono' ) }
                    required
                    help={ __( 'The entity that legally receives donations.', 'dono' ) }
                >
                    <input type="text" className="dono-input" { ...s.bind( 'legal_name' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Display name', 'dono' ) }
                    help={ __( 'Donor-facing name in subject lines and headers.', 'dono' ) }
                >
                    <input type="text" className="dono-input" { ...s.bind( 'name' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Contact email', 'dono' ) }
                    help={ __( 'Public, shown in the donation page footer.', 'dono' ) }
                >
                    <input type="email" className="dono-input" { ...s.bind( 'email' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Address', 'dono' ) }
                    required
                >
                    <div className="dono-stack-12">
                        <input
                            type="text"
                            className="dono-input"
                            placeholder={ __( 'Street', 'dono' ) }
                            value={ addressLines[ 0 ] || '' }
                            onChange={ ( e ) => updateAddressLine( 0, e.target.value ) }
                        />
                        <div className="dono-grid-2-eq">
                            <input
                                type="text"
                                className="dono-input"
                                placeholder={ __( 'Postcode', 'dono' ) }
                                value={ addressLines[ 1 ] || '' }
                                onChange={ ( e ) => updateAddressLine( 1, e.target.value ) }
                            />
                            <input
                                type="text"
                                className="dono-input"
                                placeholder={ __( 'City', 'dono' ) }
                                value={ addressLines[ 2 ] || '' }
                                onChange={ ( e ) => updateAddressLine( 2, e.target.value ) }
                            />
                        </div>
                    </div>
                </FormRow>

                <FormRow
                    label={ __( 'Country', 'dono' ) }
                    required
                    help={ __( 'Drives tax-ID format and VAT visibility.', 'dono' ) }
                >
                    <select
                        className="dono-select"
                        value={ country }
                        onChange={ ( e ) => s.setValue( 'country' )( e.target.value ) }
                    >
                        <option value="">{ __( 'Select a country', 'dono' ) }</option>
                        { COUNTRIES.map( ( c ) => (
                            <option key={ c.code } value={ c.code }>{ c.name }</option>
                        ) ) }
                    </select>
                </FormRow>

                <FormRow
                    label={ __( 'Tax ID / EU VAT', 'dono' ) }
                    help={ __( 'VIES validation is not performed.', 'dono' ) }
                >
                    <div className="dono-grid-2-eq">
                        <input
                            type="text"
                            className="dono-input dono-input--mono"
                            placeholder={ __( 'Tax number', 'dono' ) }
                            { ...s.bind( 'tax_id' ) }
                        />
                        { showVat && (
                            <input
                                type="text"
                                className="dono-input dono-input--mono"
                                placeholder={ __( 'EU VAT ID', 'dono' ) }
                                { ...s.bind( 'vat_id' ) }
                            />
                        ) }
                    </div>
                </FormRow>
            </Card>
        </div>
    );
}
