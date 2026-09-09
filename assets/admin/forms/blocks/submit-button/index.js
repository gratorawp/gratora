// Mirrors the server render in src/Forms/Blocks/SubmitButtonBlock.php.

import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'gratora/submit-button';

const ALIGN_OPTIONS = [
    { value: 'left',   label: __( 'Left',   'gratora' ) },
    { value: 'center', label: __( 'Center', 'gratora' ) },
    { value: 'right',  label: __( 'Right',  'gratora' ) },
    { value: 'full',   label: __( 'Full width', 'gratora' ) },
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
                <PanelBody title={ __( 'Button', 'gratora' ) } initialOpen>
                    <Segmented
                        label={ __( 'Alignment', 'gratora' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ ALIGN_OPTIONS }
                    />
                    <p style={ { fontSize: 12, color: '#6b7280', margin: '12px 0 0' } }>
                        { __( 'Use {amount} and {frequency} in the label to insert the live values at runtime, e.g. "Donate {amount} {frequency}".', 'gratora' ) }
                    </p>
                </PanelBody>
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Donate now', 'gratora' ) }
                    allowedFormats={ [] }
                    style={ {
                        display:       'inline-block',
                        padding:       '10px 20px',
                        background:    'var(--gratora-button-bg, var(--gratora-accent, #211d3f))',
                        color:         'var(--gratora-button-fg, var(--gratora-on-accent, #fff))',
                        borderRadius:  'var(--gratora-radius-sm, 4px)',
                        fontWeight:    500,
                        fontSize:      '14px',
                        width:         align === 'full' ? '100%' : 'auto',
                        textAlign:     'center',
                    } }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Donate button', 'gratora' ),
        description: __( 'The button that completes the donation.', 'gratora' ),
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
