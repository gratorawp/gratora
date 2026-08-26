/**
 * giveflow/section: a styled container for headings and copy.
 *
 * Storage is plain custom attributes (no WP block-supports, no Style Engine).
 * The editor + the donor-facing runtime + the server walker all read the
 * same attrs through `sectionStyle()` so the markup stays in lockstep.
 */

import { useBlockProps, InspectorControls, InnerBlocks } from '@wordpress/block-editor';
import { PanelBody } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';

import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import Field          from '../../../_shared/components/Field';
import ColorInput     from '../../../_shared/components/ColorInput';
import Slider         from '../../../_shared/components/Slider';
import BoxControl     from '../../../_shared/components/BoxControl';
import Segmented      from '../../../_shared/components/Segmented';

const NAME = 'giveflow/section';
const ALLOWED = [ 'giveflow/heading', 'giveflow/paragraph', 'giveflow/section' ];

const BORDER_STYLES = [ 'none', 'solid', 'dashed', 'dotted' ];

const SHADOW_PRESETS = [
    { value: '',                                                                       label: __( 'None',       'giveflow-fundraising-campaigns' ) },
    { value: '0 1px 2px rgba(15,23,42,.06)',                                           label: __( 'Subtle',     'giveflow-fundraising-campaigns' ) },
    { value: '0 1px 3px rgba(15,23,42,.08), 0 1px 2px rgba(15,23,42,.04)',             label: __( 'Soft',       'giveflow-fundraising-campaigns' ) },
    { value: '0 4px 14px rgba(15,23,42,.10)',                                          label: __( 'Medium',     'giveflow-fundraising-campaigns' ) },
    { value: '0 12px 32px rgba(15,23,42,.14)',                                         label: __( 'Pronounced', 'giveflow-fundraising-campaigns' ) },
];

/**
 * Convert the block's attrs into a React inline style object. Mirrors
 * SectionBlock::sectionStyle() in PHP so the editor preview, runtime, and
 * standalone do_blocks() render identical markup.
 */
export function sectionStyle( attrs = {} ) {
    const border  = attrs.border  || {};
    const padding = attrs.padding || {};
    const margin  = attrs.margin  || {};
    const px = ( n ) => ( Number.isFinite( Number( n ) ) && Number( n ) > 0 ? `${ Number( n ) }px` : undefined );

    return {
        backgroundColor: attrs.background || undefined,
        color:           attrs.textColor  || undefined,
        borderColor:     border.color || undefined,
        borderWidth:     px( border.width ),
        borderStyle:     border.width > 0 ? ( border.style || 'solid' ) : undefined,
        borderRadius:    px( border.radius ),
        boxShadow:       attrs.shadow || undefined,
        paddingTop:      px( padding.top ),
        paddingRight:    px( padding.right ),
        paddingBottom:   px( padding.bottom ),
        paddingLeft:     px( padding.left ),
        marginTop:       px( margin.top ),
        marginRight:     px( margin.right ),
        marginBottom:    px( margin.bottom ),
        marginLeft:      px( margin.left ),
        minHeight:       attrs.minHeight ? `${ Number( attrs.minHeight ) }px` : undefined,
    };
}

function Edit( { attributes, setAttributes } ) {
    const {
        background = '',
        textColor  = '',
        border     = { color: '', width: 0, style: 'solid', radius: 0 },
        shadow     = '',
        padding    = { top: 0, right: 0, bottom: 0, left: 0 },
        margin     = { top: 0, right: 0, bottom: 0, left: 0 },
        minHeight  = 0,
        condition  = DEFAULT_CONDITION,
    } = attributes;

    const setBorder = ( patch ) => setAttributes( { border: { ...border, ...patch } } );

    const isPresetShadow = SHADOW_PRESETS.some( ( p ) => p.value === shadow );
    const [ showCustomShadow, setShowCustomShadow ] = useState( !! shadow && ! isPresetShadow );

    const blockProps = useBlockProps( {
        className: 'giveflow-block-preview giveflow-block-preview--section',
        style:     sectionStyle( attributes ),
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Section', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <Field label={ __( 'Background color', 'giveflow-fundraising-campaigns' ) }>
                        <ColorInput value={ background } onChange={ ( v ) => setAttributes( { background: v } ) } />
                    </Field>

                    <Field label={ __( 'Text color', 'giveflow-fundraising-campaigns' ) }>
                        <ColorInput value={ textColor } onChange={ ( v ) => setAttributes( { textColor: v } ) } />
                    </Field>

                    <Field label={ __( 'Border color', 'giveflow-fundraising-campaigns' ) }>
                        <ColorInput value={ border.color } onChange={ ( v ) => setBorder( { color: v } ) } />
                    </Field>
                    <Slider
                        label={ __( 'Border width', 'giveflow-fundraising-campaigns' ) }
                        value={ border.width || 0 }
                        onChange={ ( v ) => setBorder( { width: v } ) }
                        min={ 0 } max={ 20 } unit="px"
                    />
                    <Segmented
                        label={ __( 'Border style', 'giveflow-fundraising-campaigns' ) }
                        value={ border.style || 'solid' }
                        onChange={ ( v ) => setBorder( { style: v } ) }
                        options={ BORDER_STYLES }
                    />
                    <Slider
                        label={ __( 'Border radius', 'giveflow-fundraising-campaigns' ) }
                        value={ border.radius || 0 }
                        onChange={ ( v ) => setBorder( { radius: v } ) }
                        min={ 0 } max={ 60 } unit="px"
                    />

                    <Field label={ __( 'Shadow', 'giveflow-fundraising-campaigns' ) }>
                        <div className="giveflow-shadow-grid">
                            { SHADOW_PRESETS.map( ( p ) => {
                                const isOn = ! showCustomShadow && shadow === p.value;
                                return (
                                    <button
                                        key={ p.label }
                                        type="button"
                                        className={ `giveflow-shadow-grid__tile ${ isOn ? 'is-on' : '' }` }
                                        title={ p.label }
                                        aria-label={ p.label }
                                        aria-pressed={ isOn }
                                        onClick={ () => {
                                            setShowCustomShadow( false );
                                            setAttributes( { shadow: p.value } );
                                        } }
                                    >
                                        { p.value === '' ? (
                                            <span className="giveflow-shadow-grid__tile__none">
                                                { __( 'None', 'giveflow-fundraising-campaigns' ) }
                                            </span>
                                        ) : (
                                            <span
                                                className="giveflow-shadow-grid__tile__sample"
                                                style={ { boxShadow: p.value } }
                                            />
                                        ) }
                                    </button>
                                );
                            } ) }
                        </div>
                    </Field>
                    <Field>
                        <label className="giveflow-checkbox-row">
                            <input
                                type="checkbox"
                                checked={ showCustomShadow }
                                onChange={ ( e ) => setShowCustomShadow( e.target.checked ) }
                            />
                            <span>{ __( 'Use custom shadow value', 'giveflow-fundraising-campaigns' ) }</span>
                        </label>
                    </Field>
                    { showCustomShadow && (
                        <Field label={ __( 'Custom shadow CSS', 'giveflow-fundraising-campaigns' ) } help={ __( 'Any valid box-shadow value.', 'giveflow-fundraising-campaigns' ) }>
                            <input
                                type="text"
                                className="giveflow-input"
                                value={ shadow }
                                onChange={ ( e ) => setAttributes( { shadow: e.target.value } ) }
                                placeholder="0 4px 14px rgba(0,0,0,.1)"
                            />
                        </Field>
                    ) }

                    <BoxControl
                        title={ __( 'Padding', 'giveflow-fundraising-campaigns' ) }
                        value={ padding }
                        onChange={ ( next ) => setAttributes( { padding: { ...padding, ...next } } ) }
                        sides="four"
                        unit="px"
                        linkable
                    />
                    <BoxControl
                        title={ __( 'Margin', 'giveflow-fundraising-campaigns' ) }
                        value={ margin }
                        onChange={ ( next ) => setAttributes( { margin: { ...margin, ...next } } ) }
                        sides="four"
                        unit="px"
                        linkable
                    />
                    <Slider
                        label={ __( 'Minimum height', 'giveflow-fundraising-campaigns' ) }
                        value={ minHeight || 0 }
                        onChange={ ( v ) => setAttributes( { minHeight: v } ) }
                        min={ 0 } max={ 800 } unit="px"
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <InnerBlocks
                    allowedBlocks={ ALLOWED }
                    renderAppender={ InnerBlocks.ButtonBlockAppender }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Section', 'giveflow-fundraising-campaigns' ),
        description: __( 'A styled container for headings and copy. Use it for hero areas, impact statements, or intro blurbs.', 'giveflow-fundraising-campaigns' ),
        category:    'giveflow-content',
        icon:        BlockIcons[ 'section' ],
        supports:    { html: false, anchor: false, inserter: true },
        attributes: {
            background: { type: 'string', default: '' },
            textColor:  { type: 'string', default: '' },
            border: {
                type: 'object',
                default: { color: '', width: 0, style: 'solid', radius: 0 },
            },
            shadow:    { type: 'string', default: '' },
            padding: {
                type: 'object',
                default: { top: 0, right: 0, bottom: 0, left: 0 },
            },
            margin: {
                type: 'object',
                default: { top: 0, right: 0, bottom: 0, left: 0 },
            },
            minHeight: { type: 'number', default: 0 },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => <InnerBlocks.Content />,
    } );
}
