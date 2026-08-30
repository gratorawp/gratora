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
        [ __( 'Amount', 'fundkit-fundraising-campaigns' ), '-' ],
        ...( showDonor   ? [ [ __( 'Donor', 'fundkit-fundraising-campaigns' ), '-' ], [ __( 'Email', 'fundkit-fundraising-campaigns' ), '-' ] ] : [] ),
        ...( showGateway ? [ [ __( 'Payment method', 'fundkit-fundraising-campaigns' ), '-' ] ] : [] ),
        [ __( 'Total', 'fundkit-fundraising-campaigns' ), '-' ],
    ];

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Summary', 'fundkit-fundraising-campaigns' ) } initialOpen>
                    <ToggleControl
                        label={ __( 'Show who is giving', 'fundkit-fundraising-campaigns' ) }
                        help={ __( 'Name, email and country, when the form collects them.', 'fundkit-fundraising-campaigns' ) }
                        checked={ showDonor }
                        onChange={ ( v ) => setAttributes( { showDonor: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show payment method', 'fundkit-fundraising-campaigns' ) }
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
        title:       __( 'Donation summary', 'fundkit-fundraising-campaigns' ),
        description: __( 'Reads back what the donor is about to give. Put it wherever the recap belongs.', 'fundkit-fundraising-campaigns' ),
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
