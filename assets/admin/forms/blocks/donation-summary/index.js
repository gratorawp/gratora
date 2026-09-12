import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'gratora/donation-summary';

function Edit( { attributes, setAttributes } ) {
    const {
        showDonor   = true,
        showGateway = true,
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--summary' } );

    const rows = [
        [ __( 'Amount', 'gratora-donation-platform' ), '-' ],
        ...( showDonor   ? [ [ __( 'Donor', 'gratora-donation-platform' ), '-' ], [ __( 'Email', 'gratora-donation-platform' ), '-' ] ] : [] ),
        ...( showGateway ? [ [ __( 'Payment method', 'gratora-donation-platform' ), '-' ] ] : [] ),
        [ __( 'Total', 'gratora-donation-platform' ), '-' ],
    ];

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Summary', 'gratora-donation-platform' ) } initialOpen>
                    <ToggleControl
                        label={ __( 'Show who is giving', 'gratora-donation-platform' ) }
                        help={ __( 'Name, email and country, when the form collects them.', 'gratora-donation-platform' ) }
                        checked={ showDonor }
                        onChange={ ( v ) => setAttributes( { showDonor: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show payment method', 'gratora-donation-platform' ) }
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
                <dl className="gratora-block-preview__summary">
                    { rows.map( ( [ label, value ] ) => (
                        <div key={ label } className="gratora-block-preview__summary-row">
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
        title:       __( 'Donation summary', 'gratora-donation-platform' ),
        description: __( 'Reads back what the donor is about to give. Put it wherever the recap belongs.', 'gratora-donation-platform' ),
        category:    'gratora-extras',
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
