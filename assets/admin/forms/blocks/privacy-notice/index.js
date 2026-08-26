import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, TextControl, ExternalLink, Notice } from '@wordpress/components';
import { __ } from '@wordpress/i18n';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import Segmented from '../../../_shared/components/Segmented';

const NAME = 'giveflow/privacy-notice';

const ALIGN_OPTIONS = [
    { value: 'left',   label: __( 'Left',   'giveflow-fundraising-campaigns' ) },
    { value: 'center', label: __( 'Center', 'giveflow-fundraising-campaigns' ) },
    { value: 'right',  label: __( 'Right',  'giveflow-fundraising-campaigns' ) },
];

function readPrivacyUrl() {
    if ( typeof window === 'undefined' ) return '';
    return String( window.giveflow?.privacy_policy_url || '' );
}

function Edit( { attributes, setAttributes } ) {
    const {
        text: textAttr         = '',
        linkText: linkTextAttr = '',
        align    = 'left',
        condition = DEFAULT_CONDITION,
    } = attributes;

    // Fall back to the translated strings for the preview; the saved attrs stay
    // empty so the PHP render emits the localized default.
    const text     = textAttr     || __( 'By donating you agree to our', 'giveflow-fundraising-campaigns' );
    const linkText = linkTextAttr || __( 'Privacy Policy', 'giveflow-fundraising-campaigns' );

    const settingsUrl = readPrivacyUrl();
    const hasUrl      = settingsUrl !== '';
    const settingsHref = ( typeof window !== 'undefined' && window.giveflow?.wp?.settings_url )
        ? `${ window.giveflow.wp.settings_url }#privacy`
        : '#';

    const blockProps = useBlockProps( {
        className: 'giveflow-block-preview giveflow-block-preview--privacy',
        style: { padding: '4px 0', textAlign: align },
    } );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Privacy notice', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <TextControl
                        label={ __( 'Leading text', 'giveflow-fundraising-campaigns' ) }
                        value={ textAttr }
                        placeholder={ __( 'By donating you agree to our', 'giveflow-fundraising-campaigns' ) }
                        onChange={ ( v ) => setAttributes( { text: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Link label', 'giveflow-fundraising-campaigns' ) }
                        value={ linkTextAttr }
                        placeholder={ __( 'Privacy Policy', 'giveflow-fundraising-campaigns' ) }
                        onChange={ ( v ) => setAttributes( { linkText: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <Segmented
                        label={ __( 'Alignment', 'giveflow-fundraising-campaigns' ) }
                        value={ align }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        options={ ALIGN_OPTIONS }
                    />
                    { hasUrl ? (
                        <p style={ { fontSize: 12, color: '#6b7280', margin: '12px 0 0' } }>
                            { __( 'Linking to:', 'giveflow-fundraising-campaigns' ) }{ ' ' }
                            <ExternalLink href={ settingsUrl }>{ settingsUrl }</ExternalLink>
                        </p>
                    ) : (
                        <Notice status="warning" isDismissible={ false } style={ { marginTop: 12 } }>
                            { __( 'No privacy policy URL is set. Donors will see the text but no link until you add one in', 'giveflow-fundraising-campaigns' ) }{ ' ' }
                            <ExternalLink href={ settingsHref }>{ __( 'Settings → Privacy', 'giveflow-fundraising-campaigns' ) }</ExternalLink>.
                        </Notice>
                    ) }
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <p style={ { margin: 0, fontSize: 13, color: '#6b7280', lineHeight: 1.5 } }>
                    { text }
                    { text && ( hasUrl ? ' ' : '' ) }
                    { hasUrl && (
                        <a
                            href={ settingsUrl }
                            onClick={ ( e ) => e.preventDefault() }
                            style={ { color: 'var(--giveflow-accent, #211d3f)', textDecoration: 'underline' } }
                        >
                            { linkText }
                        </a>
                    ) }
                </p>
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Privacy notice', 'giveflow-fundraising-campaigns' ),
        description: __( 'A short line of text plus a link to your privacy policy. Pair with the URL set in Settings → Privacy.', 'giveflow-fundraising-campaigns' ),
        category:   'giveflow-content',
        icon:       BlockIcons[ 'privacy-notice' ] || BlockIcons[ 'paragraph' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            text:     { type: 'string', default: '' },
            linkText: { type: 'string', default: '' },
            align:    { type: 'string', default: 'left' },
            condition: { type: 'object', default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
