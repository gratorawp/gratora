import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/cover-fees';

function Edit( { attributes, setAttributes } ) {
    const {
        percent   = 2.9,
        fixed     = 30,
        label     = __( 'I\'d like to help cover the transaction fee', 'dono' ),
        defaultOn = false,
        condition = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--check' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Cover the fees', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Percent fee', 'dono' ) }
                        type="number"
                        step="0.1"
                        min={ 0 }
                        value={ String( percent ) }
                        onChange={ ( v ) => setAttributes( { percent: parseFloat( v ) || 0 } ) }
                        help={ __( 'e.g. 2.9 for Stripe', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Fixed fee', 'dono' ) }
                        type="number"
                        step="0.01"
                        min={ 0 }
                        value={ fixed ? ( fixed / 100 ).toFixed( 2 ) : '' }
                        onChange={ ( v ) => {
                            const major = parseFloat( String( v ).replace( ',', '.' ) );
                            setAttributes( { fixed: isNaN( major ) ? 0 : Math.round( major * 100 ) } );
                        } }
                        help={ __( 'e.g. 0.30 for Stripe', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Default checked', 'dono' ) }
                        checked={ defaultOn }
                        onChange={ ( v ) => setAttributes( { defaultOn: v } ) }
                        help={ __( 'Best practice: leave off so donors opt in.', 'dono' ) }
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
                        background:   defaultOn ? 'var(--dono-accent, #1e8a4e)' : '#fff',
                        flexShrink:   0,
                    } }
                />
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'I\'d like to help cover the transaction fee', 'dono' ) }
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
        title:      __( 'Cover the fees', 'dono' ),
        description: __( 'Lets the donor opt to cover the payment processing fee.', 'dono' ),
        category:   'dono-amount',
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
