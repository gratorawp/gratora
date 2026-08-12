import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, CheckboxControl, Notice, ExternalLink } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/consent';

/**
 * The organization's consent purposes. The block picks from these rather than
 * defining its own: a purpose invented on a form exists nowhere else, so the
 * wording a donor agreed to and the wording the org later edits could never
 * agree, and the donor portal would have nothing to label it with.
 */
function registryPurposes() {
    const c = typeof window !== 'undefined' && window.donoFormsEditor && window.donoFormsEditor.consents;
    return Array.isArray( c ) ? c : [];
}

function settingsUrl() {
    return ( typeof window !== 'undefined' && window.donoFormsEditor && window.donoFormsEditor.consentsSettingsUrl ) || '';
}

function Edit( { attributes, setAttributes } ) {
    const {
        label       = '',
        helpText    = '',
        purposeKeys = [],
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const registry = registryPurposes();
    const picked   = Array.isArray( purposeKeys ) ? purposeKeys : [];

    // Order follows the registry, so the form reads the way Settings lists it.
    const shown = registry.filter( ( p ) => picked.includes( p.key ) );

    const toggle = ( key, on ) => setAttributes( {
        purposeKeys: on
            ? [ ...picked.filter( ( k ) => k !== key ), key ]
            : picked.filter( ( k ) => k !== key ),
    } );

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--consent' } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Consent', 'dono-fundraising-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Heading', 'dono-fundraising-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'How can we stay in touch?', 'dono-fundraising-platform' ) }
                        help={ __( 'Click the heading on the form to edit it inline.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Help text', 'dono-fundraising-platform' ) }
                        value={ helpText }
                        onChange={ ( v ) => setAttributes( { helpText: v } ) }
                        placeholder={ __( 'Optional explanation shown below the heading.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />

                    { registry.length === 0 ? (
                        <Notice status="warning" isDismissible={ false }>
                            { __( 'No consent purposes exist yet. A purpose names something your organization actually does, so you define it once and every form asks for it the same way.', 'dono-fundraising-platform' ) }
                            { settingsUrl() && (
                                <>
                                    { ' ' }
                                    <ExternalLink href={ settingsUrl() }>
                                        { __( 'Add one in Settings, Consents.', 'dono-fundraising-platform' ) }
                                    </ExternalLink>
                                </>
                            ) }
                        </Notice>
                    ) : (
                        <>
                            { registry.map( ( p ) => (
                                <CheckboxControl
                                    key={ p.key }
                                    label={ p.required
                                        ? `${ p.label } ${ __( '(required)', 'dono-fundraising-platform' ) }`
                                        : p.label }
                                    help={ p.description || undefined }
                                    checked={ picked.includes( p.key ) }
                                    onChange={ ( on ) => toggle( p.key, on ) }
                                    __nextHasNoMarginBottom
                                />
                            ) ) }
                            { settingsUrl() && (
                                <p style={ { marginTop: 12 } }>
                                    <ExternalLink href={ settingsUrl() }>
                                        { __( 'Edit the wording in Settings, Consents.', 'dono-fundraising-platform' ) }
                                    </ExternalLink>
                                </p>
                            ) }
                        </>
                    ) }
                </PanelBody>
                <ConditionPanel
                    value={ condition }
                    onChange={ ( v ) => setAttributes( { condition: v } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="span"
                    className="dono-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'How can we stay in touch?', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                />
                { helpText && <div className="dono-block-preview__hint">{ helpText }</div> }
                { shown.length === 0
                    ? (
                        <div className="dono-block-preview__field">
                            { registry.length === 0
                                ? __( 'No consent purposes exist yet. Add one in Settings, Consents.', 'dono-fundraising-platform' )
                                : __( 'Pick which purposes this form asks for.', 'dono-fundraising-platform' ) }
                        </div>
                    )
                    : shown.map( ( p ) => (
                        <div key={ p.key } className="dono-block-preview__field">
                            { p.required ? `${ p.label } (${ __( 'required', 'dono-fundraising-platform' ) })` : p.label }
                        </div>
                    ) ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Consent', 'dono-fundraising-platform' ),
        description: __( 'Asks the donor to opt in to purposes your organization has defined in Settings.', 'dono-fundraising-platform' ),
        category:   'dono-extras',
        icon:       BlockIcons[ 'consent' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:       { type: 'string', default: '' },
            helpText:    { type: 'string', default: '' },
            purposeKeys: { type: 'array',  default: [] },
            condition:   { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
