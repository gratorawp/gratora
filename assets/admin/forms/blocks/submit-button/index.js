// Mirrors the server render in src/Forms/Blocks/SubmitButtonBlock.php.

import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'gratora/submit-button';

const ALIGN_OPTIONS = [
    { value: 'left',   label: __( 'Left',   'gratora-donation-platform' ) },
    { value: 'center', label: __( 'Center', 'gratora-donation-platform' ) },
    { value: 'right',  label: __( 'Right',  'gratora-donation-platform' ) },
    { value: 'full',   label: __( 'Full width', 'gratora-donation-platform' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { label = '', align = 'left' } = attributes;

    const justify = {
        left:   'flex-start',
        center: 'center',
        right:  'flex-end',
        full:   'stretch',
    }[ align ];

    const blockProps = useBlockProps( {
        style: {
            padding:        '16px',
            display:        'flex',
            justifyContent: justify,
        },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Button', 'gratora-donation-platform' ) } initialOpen>
                    <Segmented
                        label={ __( 'Alignment', 'gratora-donation-platform' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ ALIGN_OPTIONS }
                    />
                    <p style={ { fontSize: 12, color: '#6b7280', margin: '12px 0 0' } }>
                        { __( 'Use {amount} and {frequency} in the label to insert the live values at runtime, e.g. "Donate {amount} {frequency}".', 'gratora-donation-platform' ) }
                    </p>
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Donate now', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                    style={ {
                        display:        'inline-flex',
                        alignItems:     'center',
                        justifyContent: 'center',
                        minHeight:      'var(--gratora-button-size, 48px)',
                        padding:        '0 22px',
                        border:         'var(--gratora-button-border, 0)',
                        background:     'var(--gratora-button-bg, var(--gratora-accent, #211d3f))',
                        color:          'var(--gratora-button-fg, var(--gratora-on-accent, #fff))',
                        borderRadius:   'var(--gratora-button-radius, var(--gratora-radius-sm, 8px))',
                        fontWeight:     'var(--gratora-button-weight, 600)',
                        fontSize:       '14px',
                        boxShadow:      'var(--gratora-button-shadow, 0 1px 2px rgba(0,0,0,.08))',
                        width:          align === 'full' ? '100%' : 'auto',
                        textAlign:      'center',
                    } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Donate button', 'gratora-donation-platform' ),
        description: __( 'The button that completes the donation.', 'gratora-donation-platform' ),
        category:   'gratora-extras',
        icon:       BlockIcons[ 'submit-button' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:       { type: 'string',  default: '' },
            align:       { type: 'string',  default: 'left' },
        },
        edit: Edit,
        save: () => null,
    } );
}
