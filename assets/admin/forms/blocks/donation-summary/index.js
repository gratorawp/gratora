import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/donation-summary';

function Edit( { attributes, setAttributes } ) {
    const {
        showDonor   = true,
        showGateway = true,
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--summary' } );

    const rows = [
        [ __( 'Amount', 'fundraising-toolkit' ), '-' ],
        ...( showDonor   ? [ [ __( 'Donor', 'fundraising-toolkit' ), '-' ], [ __( 'Email', 'fundraising-toolkit' ), '-' ] ] : [] ),
        ...( showGateway ? [ [ __( 'Payment method', 'fundraising-toolkit' ), '-' ] ] : [] ),
        [ __( 'Total', 'fundraising-toolkit' ), '-' ],
    ];

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Summary', 'fundraising-toolkit' ) } initialOpen>
                    <ToggleControl
                        label={ __( 'Show who is giving', 'fundraising-toolkit' ) }
                        help={ __( 'Name, email and country, when the form collects them.', 'fundraising-toolkit' ) }
                        checked={ showDonor }
                        onChange={ ( v ) => setAttributes( { showDonor: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show payment method', 'fundraising-toolkit' ) }
                        checked={ showGateway }
                        onChange={ ( v ) => setAttributes( { showGateway: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    value={ condition }
                    onChange={ ( v ) => setAttributes( { condition: v } ) }
                />
            </InspectorControls>

            <div { ...blockProps }>
                <dl className="fundkit-block-preview__summary">
                    { rows.map( ( [ label, value ] ) => (
                        <div key={ label } className="fundkit-block-preview__summary-row">
                            <dt>{ label }</dt>
                            <dd>{ value }</dd>
                        </div>
                    ) ) }
                </dl>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Donation summary', 'fundraising-toolkit' ),
        description: __( 'Reads back what the donor is about to give. Put it wherever the recap belongs.', 'fundraising-toolkit' ),
        category:    'fundkit-extras',
        icon:        BlockIcons[ 'donation-summary' ],
        // One recap per form. Two would disagree the moment a condition hid a
        // field from one of them.
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            showDonor:   { type: 'boolean', default: true },
            showGateway: { type: 'boolean', default: true },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
