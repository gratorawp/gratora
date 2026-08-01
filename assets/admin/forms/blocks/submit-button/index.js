/**
 * dono/submit-button: the donate button.
 *
 * Editor side mirrors the server-side render in
 * src/Forms/Blocks/SubmitButtonBlock.php.
 */

import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'dono/submit-button';

const ALIGN_OPTIONS = [
    { value: 'left',   label: __( 'Left',   'dono' ) },
    { value: 'center', label: __( 'Center', 'dono' ) },
    { value: 'right',  label: __( 'Right',  'dono' ) },
    { value: 'full',   label: __( 'Full width', 'dono' ) },
];

function Edit( { attributes, setAttributes } ) {
    const { label = '', align = 'left', showSummary = true } = attributes;

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
                <PanelBody title={ __( 'Button', 'dono' ) } initialOpen>
                    <Segmented
                        label={ __( 'Alignment', 'dono' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ ALIGN_OPTIONS }
                    />
                    <p style={ { fontSize: 12, color: '#6b7280', margin: '12px 0 0' } }>
                        { __( 'Use {amount} and {frequency} in the label to insert the live values at runtime, e.g. "Donate {amount} {frequency}".', 'dono' ) }
                    </p>
                    <ToggleControl
                        label={ __( 'Show donation summary', 'dono' ) }
                        help={ __( 'Shows the donor a summary (amount, fees, total) just above this button before they submit.', 'dono' ) }
                        checked={ showSummary }
                        onChange={ ( v ) => setAttributes( { showSummary: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            { showSummary && (
                <div style={ { fontSize: 11, color: '#6b7280', padding: '0 16px', fontStyle: 'italic' } }>
                    { __( '↑ A donation summary appears here for the donor', 'dono' ) }
                </div>
            ) }
            <div { ...blockProps }>
                <RichText
                    tagName="span"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Donate now', 'dono' ) }
                    allowedFormats={ [] }
                    style={ {
                        display:       'inline-block',
                        padding:       '10px 20px',
                        background:    'var(--dono-accent, #1e8a4e)',
                        color:         '#fff',
                        borderRadius:  'var(--dono-radius-sm, 4px)',
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

/**
 * @param {{register: (name: string, def: object) => any}} api
 */
export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Donate button', 'dono' ),
        description: __( 'The button that completes the donation.', 'dono' ),
        category:   'dono-extras',
        icon:       BlockIcons[ 'submit-button' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:       { type: 'string',  default: '' },
            align:       { type: 'string',  default: 'left' },
            showSummary: { type: 'boolean', default: true },
        },
        edit: Edit,
        save: () => null,
    } );
}
