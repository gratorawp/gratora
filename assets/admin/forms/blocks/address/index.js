import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'fundkit/address';

function Edit( { attributes, setAttributes } ) {
    const {
        label          = '',
        showLine1      = true,
        showLine2      = true,
        showCity       = true,
        showRegion     = true,
        showPostal     = true,
        showCountry    = true,
        requireLine1   = true,
        requireCity    = true,
        requireRegion  = false,
        requirePostal  = true,
        requireCountry = true,
        line1Label     = '',
        line2Label     = '',
        cityLabel      = '',
        regionLabel    = '',
        postalLabel    = '',
        countryLabel   = '',
        condition      = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'fundkit-block-preview fundkit-block-preview--address' } );

    const fieldRow = ( show, labelValue, labelKey, fallback, requireKey, requireValue ) => {
        if ( ! show ) return null;
        return (
            <div>
                <RichText
                    tagName="span"
                    className="fundkit-block-preview__label"
                    value={ labelValue }
                    onChange={ ( v ) => setAttributes( { [ labelKey ]: v } ) }
                    placeholder={ fallback }
                    allowedFormats={ [] }
                />
                { requireValue && (
                    <em className="fundkit-block-preview__req" aria-hidden="true">*</em>
                ) }
                <div className="fundkit-block-preview__field" />
            </div>
        );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Address', 'fundkit-fundraising-campaigns' ) } initialOpen>
                    <TextControl
                        label={ __( 'Heading', 'fundkit-fundraising-campaigns' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Mailing address', 'fundkit-fundraising-campaigns' ) }
                        help={ __( 'Click the heading or any field label to edit inline.', 'fundkit-fundraising-campaigns' ) }
                        __nextHasNoMarginBottom
                    />

                    <ToggleControl
                        label={ __( 'Line 1: show', 'fundkit-fundraising-campaigns' ) }
                        checked={ showLine1 }
                        onChange={ ( v ) => setAttributes( { showLine1: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { showLine1 && (
                        <>
                            <TextControl
                                label={ __( 'Line 1: label', 'fundkit-fundraising-campaigns' ) }
                                value={ line1Label }
                                onChange={ ( v ) => setAttributes( { line1Label: v } ) }
                                placeholder={ __( 'Address line 1', 'fundkit-fundraising-campaigns' ) }
                                __nextHasNoMarginBottom
                            />
                            <ToggleControl
                                label={ __( 'Line 1: required', 'fundkit-fundraising-campaigns' ) }
                                checked={ requireLine1 }
                                onChange={ ( v ) => setAttributes( { requireLine1: v } ) }
                                __nextHasNoMarginBottom
                            />
                        </>
                    ) }

                    <ToggleControl
                        label={ __( 'Line 2: show', 'fundkit-fundraising-campaigns' ) }
                        checked={ showLine2 }
                        onChange={ ( v ) => setAttributes( { showLine2: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { showLine2 && (
                        <TextControl
                            label={ __( 'Line 2: label', 'fundkit-fundraising-campaigns' ) }
                            value={ line2Label }
                            onChange={ ( v ) => setAttributes( { line2Label: v } ) }
                            placeholder={ __( 'Apartment, suite, etc.', 'fundkit-fundraising-campaigns' ) }
                            __nextHasNoMarginBottom
                        />
                    ) }

                    <ToggleControl
                        label={ __( 'City: show', 'fundkit-fundraising-campaigns' ) }
                        checked={ showCity }
                        onChange={ ( v ) => setAttributes( { showCity: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { showCity && (
                        <>
                            <TextControl
                                label={ __( 'City: label', 'fundkit-fundraising-campaigns' ) }
                                value={ cityLabel }
                                onChange={ ( v ) => setAttributes( { cityLabel: v } ) }
                                placeholder={ __( 'City', 'fundkit-fundraising-campaigns' ) }
                                __nextHasNoMarginBottom
                            />
                            <ToggleControl
                                label={ __( 'City: required', 'fundkit-fundraising-campaigns' ) }
                                checked={ requireCity }
                                onChange={ ( v ) => setAttributes( { requireCity: v } ) }
                                __nextHasNoMarginBottom
                            />
                        </>
                    ) }

                    <ToggleControl
                        label={ __( 'State / region: show', 'fundkit-fundraising-campaigns' ) }
                        checked={ showRegion }
                        onChange={ ( v ) => setAttributes( { showRegion: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { showRegion && (
                        <>
                            <TextControl
                                label={ __( 'State / region: label', 'fundkit-fundraising-campaigns' ) }
                                value={ regionLabel }
                                onChange={ ( v ) => setAttributes( { regionLabel: v } ) }
                                placeholder={ __( 'State / region', 'fundkit-fundraising-campaigns' ) }
                                __nextHasNoMarginBottom
                            />
                            <ToggleControl
                                label={ __( 'State / region: required', 'fundkit-fundraising-campaigns' ) }
                                checked={ requireRegion }
                                onChange={ ( v ) => setAttributes( { requireRegion: v } ) }
                                __nextHasNoMarginBottom
                            />
                        </>
                    ) }

                    <ToggleControl
                        label={ __( 'Postal code: show', 'fundkit-fundraising-campaigns' ) }
                        checked={ showPostal }
                        onChange={ ( v ) => setAttributes( { showPostal: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { showPostal && (
                        <>
                            <TextControl
                                label={ __( 'Postal code: label', 'fundkit-fundraising-campaigns' ) }
                                value={ postalLabel }
                                onChange={ ( v ) => setAttributes( { postalLabel: v } ) }
                                placeholder={ __( 'Postal code', 'fundkit-fundraising-campaigns' ) }
                                __nextHasNoMarginBottom
                            />
                            <ToggleControl
                                label={ __( 'Postal code: required', 'fundkit-fundraising-campaigns' ) }
                                checked={ requirePostal }
                                onChange={ ( v ) => setAttributes( { requirePostal: v } ) }
                                __nextHasNoMarginBottom
                            />
                        </>
                    ) }

                    <ToggleControl
                        label={ __( 'Country: show', 'fundkit-fundraising-campaigns' ) }
                        checked={ showCountry }
                        onChange={ ( v ) => setAttributes( { showCountry: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { showCountry && (
                        <>
                            <TextControl
                                label={ __( 'Country: label', 'fundkit-fundraising-campaigns' ) }
                                value={ countryLabel }
                                onChange={ ( v ) => setAttributes( { countryLabel: v } ) }
                                placeholder={ __( 'Country', 'fundkit-fundraising-campaigns' ) }
                                __nextHasNoMarginBottom
                            />
                            <ToggleControl
                                label={ __( 'Country: required', 'fundkit-fundraising-campaigns' ) }
                                checked={ requireCountry }
                                onChange={ ( v ) => setAttributes( { requireCountry: v } ) }
                                __nextHasNoMarginBottom
                            />
                        </>
                    ) }
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="div"
                    className="fundkit-block-preview__title"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Mailing address', 'fundkit-fundraising-campaigns' ) }
                    allowedFormats={ [] }
                />
                <div style={ { display: 'flex', flexDirection: 'column', gap: 10 } }>
                    { fieldRow( showLine1, line1Label, 'line1Label', __( 'Address line 1', 'fundkit-fundraising-campaigns' ), 'requireLine1', requireLine1 ) }
                    { fieldRow( showLine2, line2Label, 'line2Label', __( 'Apartment, suite, etc.', 'fundkit-fundraising-campaigns' ), null, false ) }
                    { ( showCity || showRegion ) && (
                        <div className="fundkit-block-preview__grid-2">
                            { fieldRow( showCity, cityLabel, 'cityLabel', __( 'City', 'fundkit-fundraising-campaigns' ), 'requireCity', requireCity ) }
                            { fieldRow( showRegion, regionLabel, 'regionLabel', __( 'State / region', 'fundkit-fundraising-campaigns' ), 'requireRegion', requireRegion ) }
                        </div>
                    ) }
                    { ( showPostal || showCountry ) && (
                        <div className="fundkit-block-preview__grid-2">
                            { fieldRow( showPostal, postalLabel, 'postalLabel', __( 'Postal code', 'fundkit-fundraising-campaigns' ), 'requirePostal', requirePostal ) }
                            { fieldRow( showCountry, countryLabel, 'countryLabel', __( 'Country', 'fundkit-fundraising-campaigns' ), 'requireCountry', requireCountry ) }
                        </div>
                    ) }
                </div>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Address', 'fundkit-fundraising-campaigns' ),
        description: __( 'Structured donor mailing address.', 'fundkit-fundraising-campaigns' ),
        category:    'fundkit-donor',
        icon:        BlockIcons[ 'address' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:          { type: 'string',  default: '' },
            showLine1:      { type: 'boolean', default: true },
            showLine2:      { type: 'boolean', default: true },
            showCity:       { type: 'boolean', default: true },
            showRegion:     { type: 'boolean', default: true },
            showPostal:     { type: 'boolean', default: true },
            showCountry:    { type: 'boolean', default: true },
            requireLine1:   { type: 'boolean', default: true },
            requireCity:    { type: 'boolean', default: true },
            requireRegion:  { type: 'boolean', default: false },
            requirePostal:  { type: 'boolean', default: true },
            requireCountry: { type: 'boolean', default: true },
            line1Label:     { type: 'string',  default: '' },
            line2Label:     { type: 'string',  default: '' },
            cityLabel:      { type: 'string',  default: '' },
            regionLabel:    { type: 'string',  default: '' },
            postalLabel:    { type: 'string',  default: '' },
            countryLabel:   { type: 'string',  default: '' },
            condition:      { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
