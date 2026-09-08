import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/cover-fees';

/** Keep draft decimal text; number inputs report partial values as empty. */
function DecimalControl( { label, help, display, onCommit } ) {
    const [ draft, setDraft ] = useState( null );

    return (
        <TextControl
            label={ label }
            help={ help }
            inputMode="decimal"
            value={ draft !== null ? draft : display }
            onChange={ ( v ) => {
                setDraft( v );
                const n = parseFloat( String( v ).replace( ',', '.' ) );
                onCommit( Number.isFinite( n ) && n >= 0 ? n : 0 );
            } }
            onBlur={ () => setDraft( null ) }
            __nextHasNoMarginBottom
        />
    );
}

function Edit( { attributes, setAttributes } ) {
    const {
        percent   = 2.9,
        fixed     = 30,
        label     = __( 'I\'d like to help cover the transaction fee', 'fundraising-toolkit' ),
        defaultOn = false,
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--check' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Cover the fees', 'fundraising-toolkit' ) } initialOpen>
                    <DecimalControl
                        label={ __( 'Percent fee', 'fundraising-toolkit' ) }
                        help={ __( 'e.g. 2.9 for Stripe', 'fundraising-toolkit' ) }
                        display={ String( percent ) }
                        onCommit={ ( n ) => setAttributes( { percent: n } ) }
                    />
                    <DecimalControl
                        label={ __( 'Fixed fee', 'fundraising-toolkit' ) }
                        help={ __( 'e.g. 0.30 for Stripe', 'fundraising-toolkit' ) }
                        display={ ( fixed / 100 ).toFixed( 2 ) }
                        onCommit={ ( n ) => setAttributes( { fixed: Math.round( n * 100 ) } ) }
                    />
                    <ToggleControl
                        label={ __( 'Default checked', 'fundraising-toolkit' ) }
                        checked={ defaultOn }
                        onChange={ ( v ) => setAttributes( { defaultOn: v } ) }
                        help={ __( 'Best practice: leave off so donors opt in.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <span
                    style={ {
                        width:        16,
                        height:       16,
                        borderRadius: 3,
                        border:       '1px solid #888',
                        background:   defaultOn ? 'var(--fundkit-accent, #211d3f)' : '#fff',
                        flexShrink:   0,
                    } }
                />
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'I\'d like to help cover the transaction fee', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                    style={ { fontSize: 13, flex: 1 } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Cover the fees', 'fundraising-toolkit' ),
        description: __( 'Lets the donor opt to cover the payment processing fee.', 'fundraising-toolkit' ),
        category:   'fundkit-amount',
        icon:       BlockIcons[ 'cover-fees' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            percent:   { type: 'number',  default: 2.9 },
            fixed:     { type: 'number',  default: 30 },
            label:     { type: 'string',  default: '' },
            defaultOn: { type: 'boolean', default: false },
            condition: { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
