import { __ } from '@wordpress/i18n';

import Card from '../../_shared/components/Card';
import FormRow from '../../_shared/components/FormRow';
import { localizedCountries } from '../../../_shared/countries';

export default function OrganizationPanel( { s } ) {
    const country = s.value( 'country', '' );

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
        <div className="gratora-panel">
            <Card
                title={ __( 'Identity', 'gratora' ) }
                meta={ __( 'Used by receipts and footer', 'gratora' ) }
                edited={ s.isDirty }
            >
                <FormRow
                    label={ __( 'Legal name', 'gratora' ) }
                    required
                    help={ __( 'The entity that legally receives donations.', 'gratora' ) }
                >
                    <input type="text" className="gratora-input" { ...s.bind( 'legal_name' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Display name', 'gratora' ) }
                    help={ __( 'Donor-facing name in subject lines and headers.', 'gratora' ) }
                >
                    <input type="text" className="gratora-input" { ...s.bind( 'name' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Contact email', 'gratora' ) }
                    help={ __( 'Printed on receipts, so donors know where to reply.', 'gratora' ) }
                >
                    <input type="email" className="gratora-input" { ...s.bind( 'email' ) } />
                </FormRow>

                <FormRow
                    label={ __( 'Address', 'gratora' ) }
                    help={ __( 'Optional. Receipts print it when set; donors claiming tax relief usually need it.', 'gratora' ) }
                >
                    <div className="gratora-stack-12">
                        <input
                            type="text"
                            className="gratora-input"
                            placeholder={ __( 'Street', 'gratora' ) }
                            value={ addressLines[ 0 ] || '' }
                            onChange={ ( e ) => updateAddressLine( 0, e.target.value ) }
                        />
                        <div className="gratora-grid-2-eq">
                            <input
                                type="text"
                                className="gratora-input"
                                placeholder={ __( 'Postcode', 'gratora' ) }
                                value={ addressLines[ 1 ] || '' }
                                onChange={ ( e ) => updateAddressLine( 1, e.target.value ) }
                            />
                            <input
                                type="text"
                                className="gratora-input"
                                placeholder={ __( 'City', 'gratora' ) }
                                value={ addressLines[ 2 ] || '' }
                                onChange={ ( e ) => updateAddressLine( 2, e.target.value ) }
                            />
                        </div>
                    </div>
                </FormRow>

                <FormRow
                    label={ __( 'Country', 'gratora' ) }
                    required
                    help={ __( 'Drives tax-ID format and VAT visibility.', 'gratora' ) }
                >
                    <select
                        className="gratora-select"
                        value={ country }
                        onChange={ ( e ) => s.setValue( 'country' )( e.target.value ) }
                    >
                        <option value="">{ __( 'Select a country', 'gratora' ) }</option>
                        { localizedCountries().map( ( c ) => (
                            <option key={ c.code } value={ c.code }>{ c.label }</option>
                        ) ) }
                    </select>
                </FormRow>

                <FormRow
                    label={ __( 'Tax ID / EU VAT', 'gratora' ) }
                    help={ __( 'VIES validation is not performed.', 'gratora' ) }
                >
                    <div className="gratora-grid-2-eq">
                        <input
                            type="text"
                            className="gratora-input gratora-input--mono"
                            placeholder={ __( 'Tax number', 'gratora' ) }
                            { ...s.bind( 'tax_id' ) }
                        />
                        { /* Always offered, whatever the country reads today:
                             hiding it leaves a number printing on every receipt
                             with no field on screen to clear it. */ }
                        <input
                            type="text"
                            className="gratora-input gratora-input--mono"
                            placeholder={ __( 'EU VAT ID', 'gratora' ) }
                            { ...s.bind( 'vat_id' ) }
                        />
                    </div>
                </FormRow>
            </Card>
        </div>
    );
}
