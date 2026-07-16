// Campaign content blocks for the WP post/page editor.
// All blocks are server-rendered; the editor uses ServerSideRender for live preview.
// campaignId=0 falls back to the page's _dono_campaign_id post meta.

import { useSelect } from '@wordpress/data';
import { useEntityRecord, useEntityRecords } from '@wordpress/core-data';
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, RichText, useBlockProps } from '@wordpress/block-editor';
import {
    Disabled,
    PanelBody,
    Placeholder,
    RangeControl,
    SelectControl,
    TextControl,
    ToggleControl,
} from '@wordpress/components';
import Notice from '../_shared/components/Notice';
import ServerSideRender from '@wordpress/server-side-render';
import { __ } from '@wordpress/i18n';

import { registerDonoEntities } from '../_shared/entities';
import './blocks.scss';

registerDonoEntities();

function useBoundCampaign( campaignId ) {
    const postMetaId = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        if ( ! editor || ! editor.getEditedPostAttribute ) return 0;
        const meta = editor.getEditedPostAttribute( 'meta' ) || {};
        return Number( meta._dono_campaign_id || 0 );
    }, [] );

    // The page itself is bound to a campaign (a campaign landing page): blocks
    // auto-inherit it, so the picker is hidden. Elsewhere the author picks one.
    const onCampaignPage = postMetaId > 0;
    const resolvedId = campaignId || postMetaId || 0;
    const { record } = useEntityRecord( 'dono/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );
    return { campaign: record, onCampaignPage, resolvedId };
}

function CampaignPicker( { value, onChange, noneLabel } ) {
    const { records } = useEntityRecords( 'dono/v1', 'campaign', { per_page: 100 } );
    // useEntityRecords yields `records: null` until the fetch resolves, and a
    // destructuring default only replaces `undefined`, not `null` - so guard
    // before mapping or the inspector throws the moment a block is selected.
    const campaigns = Array.isArray( records ) ? records : [];
    return (
        <SelectControl
            label={ __( 'Campaign', 'dono' ) }
            value={ String( value || 0 ) }
            options={ [
                { value: '0', label: noneLabel || __( 'Select a campaign', 'dono' ) },
                ...campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title } ) ),
            ] }
            onChange={ ( v ) => onChange( Number( v ) ) }
            __nextHasNoMarginBottom
        />
    );
}

function BoundCampaignNote( { campaign, issues = [] } ) {
    if ( ! campaign ) {
        return (
            <p className="dono-block-note dono-block-note--muted">
                { __( 'Not bound to a campaign yet.', 'dono' ) }
            </p>
        );
    }
    return (
        <>
            <p className="dono-block-note">
                { __( 'Linked to:', 'dono' ) } <strong>{ campaign.title }</strong>
                { campaign.status === 'archived' && (
                    <span className="dono-block-note__pill">{ __( 'Archived', 'dono' ) }</span>
                ) }
                { campaign.status === 'draft' && (
                    <span className="dono-block-note__pill">{ __( 'Draft', 'dono' ) }</span>
                ) }
            </p>
            { issues.map( ( msg, i ) => (
                <Notice key={ i } status="warning" isDismissible={ false }>{ msg }</Notice>
            ) ) }
        </>
    );
}

/**
 * Inspector campaign control: the picker is shown only when the block lives
 * somewhere other than a campaign page; on a campaign page the block inherits
 * that page's campaign automatically, so we just show the bound note.
 */
function CampaignField( { attributes, setAttributes, campaign, onCampaignPage, issues = [] } ) {
    return (
        <>
            { ! onCampaignPage && (
                <CampaignPicker
                    value={ attributes.campaignId }
                    onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                />
            ) }
            <BoundCampaignNote campaign={ campaign } issues={ issues } />
        </>
    );
}

/**
 * Canvas body for a campaign block: unbound on a non-campaign page shows an inline
 * picker Placeholder; otherwise previews via ServerSideRender with the resolved
 * campaign id (block-renderer has no post context).
 */
function CampaignCanvas( { block, attributes, setAttributes, onCampaignPage, resolvedId, icon = 'megaphone', className, children } ) {
    const blockProps = useBlockProps( className ? { className } : {} );

    if ( ! onCampaignPage && ! attributes.campaignId ) {
        return (
            <div { ...blockProps }>
                <Placeholder
                    icon={ icon }
                    label={ __( 'Dono campaign block', 'dono' ) }
                    instructions={ __( 'Choose which campaign this block should display.', 'dono' ) }
                >
                    <CampaignPicker
                        value={ attributes.campaignId }
                        onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                    />
                </Placeholder>
            </div>
        );
    }

    return (
        <div { ...blockProps }>
            { children }
            <Disabled>
                <ServerSideRender block={ block } attributes={ { ...attributes, campaignId: resolvedId } } />
            </Disabled>
        </div>
    );
}

// dono/campaign-hero
registerBlockType( 'dono/campaign-hero', {
    apiVersion: 3,
    title:      __( 'Campaign hero', 'dono' ),
    description: __( 'Title, description and cover image for the campaign.', 'dono' ),
    category:   'dono',
    icon:       'megaphone',
    attributes: {
        campaignId:      { type: 'integer', default: 0 },
        showDescription: { type: 'boolean', default: true },
        showCover:       { type: 'boolean', default: true },
        showSummary:     { type: 'boolean', default: true },
        headingLevel:    { type: 'integer', default: 1 },
        align:           { type: 'string',  default: 'left' },
    },
    edit: function HeroEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && attributes.showCover && ! campaign.image_attachment_id ) {
            issues.push( __( 'This campaign has no cover image set. The hero will render without one.', 'dono' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Hero', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <ToggleControl
                        label={ __( 'Show description', 'dono' ) }
                        checked={ attributes.showDescription }
                        onChange={ ( v ) => setAttributes( { showDescription: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show cover image', 'dono' ) }
                        checked={ attributes.showCover }
                        onChange={ ( v ) => setAttributes( { showCover: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show amount raised', 'dono' ) }
                        checked={ attributes.showSummary }
                        onChange={ ( v ) => setAttributes( { showSummary: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Heading level', 'dono' ) }
                        value={ String( attributes.headingLevel || 1 ) }
                        options={ [
                            { value: '1', label: __( 'H1', 'dono' ) },
                            { value: '2', label: __( 'H2', 'dono' ) },
                            { value: '3', label: __( 'H3', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { headingLevel: Number( v ) || 1 } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono' ) },
                            { value: 'center', label: __( 'Center', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/campaign-hero"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="megaphone"
            />
        </>;
    },
    save: () => null,
} );

// dono/campaign-stats
registerBlockType( 'dono/campaign-stats', {
    apiVersion: 3,
    title:      __( 'Campaign stats', 'dono' ),
    description: __( 'Amount raised, donations count, donors count.', 'dono' ),
    category:   'dono',
    icon:       'chart-bar',
    attributes: {
        campaignId:    { type: 'integer', default: 0 },
        showRaised:    { type: 'boolean', default: true },
        showDonations: { type: 'boolean', default: true },
        showDonors:    { type: 'boolean', default: true },
        align:         { type: 'string',  default: 'left' },
    },
    edit: function StatsEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && Number( campaign.donations_count ) === 0 ) {
            issues.push( __( 'No donations yet. All stats will display zero on the page.', 'dono' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Stats', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <ToggleControl
                        label={ __( 'Show amount raised', 'dono' ) }
                        checked={ attributes.showRaised }
                        onChange={ ( v ) => setAttributes( { showRaised: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donations count', 'dono' ) }
                        checked={ attributes.showDonations }
                        onChange={ ( v ) => setAttributes( { showDonations: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donors count', 'dono' ) }
                        checked={ attributes.showDonors }
                        onChange={ ( v ) => setAttributes( { showDonors: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono' ) },
                            { value: 'center', label: __( 'Center', 'dono' ) },
                            { value: 'right',  label: __( 'Right',  'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/campaign-stats"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="chart-bar"
            />
        </>;
    },
    save: () => null,
} );

// dono/campaign-progress
registerBlockType( 'dono/campaign-progress', {
    apiVersion: 3,
    title:      __( 'Campaign progress', 'dono' ),
    description: __( 'Progress bar toward the campaign goal.', 'dono' ),
    category:   'dono',
    icon:       'chart-line',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        showLabels: { type: 'boolean', default: true },
        align:      { type: 'string',  default: 'left' },
    },
    edit: function ProgressEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign ) {
            const goalType = campaign.goal_type || 'amount';
            const target = goalType === 'amount' ? ( campaign.goal_cents ?? 0 ) : ( campaign.goal_count ?? 0 );
            if ( ! target ) {
                issues.push( __( 'No goal set on this campaign. The bar will sit at 0% until you set one.', 'dono' ) );
            }
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Progress', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <ToggleControl
                        label={ __( 'Show labels', 'dono' ) }
                        checked={ attributes.showLabels }
                        onChange={ ( v ) => setAttributes( { showLabels: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono' ) },
                            { value: 'center', label: __( 'Center', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/campaign-progress"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="chart-line"
            />
        </>;
    },
    save: () => null,
} );

// dono/donate-button
registerBlockType( 'dono/donate-button', {
    apiVersion: 3,
    title:      __( 'Donate button', 'dono' ),
    description: __( 'Button that opens the campaign\'s default donation form.', 'dono' ),
    category:   'dono',
    icon:       'heart',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        label:      { type: 'string',  default: 'Donate now' },
        align:      { type: 'string',  default: 'left' },
        size:       { type: 'string',  default: 'md' },
        fullWidth:  { type: 'boolean', default: false },
    },
    edit: function DonateButtonEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && ! campaign.default_form_id ) {
            issues.push( __( 'This campaign has no default form. The button will appear but clicking it won\'t open anything until a form is set.', 'dono' ) );
        }
        if ( campaign?.status === 'archived' ) {
            issues.push( __( 'This campaign is archived. The button will render but submissions will be rejected.', 'dono' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donate button', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono' ) },
                            { value: 'center', label: __( 'Center', 'dono' ) },
                            { value: 'right',  label: __( 'Right',  'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Button size', 'dono' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'dono' ) },
                            { value: 'md', label: __( 'Medium', 'dono' ) },
                            { value: 'lg', label: __( 'Large',  'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Full width', 'dono' ) }
                        checked={ attributes.fullWidth }
                        onChange={ ( v ) => setAttributes( { fullWidth: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/donate-button"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="heart"
            />
        </>;
    },
    save: () => null,
} );

// dono/top-donors
registerBlockType( 'dono/top-donors', {
    apiVersion: 3,
    title:      __( 'Top donors', 'dono' ),
    description: __( 'Leaderboard of the donors who gave the most to this campaign.', 'dono' ),
    category:   'dono',
    icon:       'awards',
    attributes: {
        campaignId:     { type: 'integer', default: 0 },
        title:          { type: 'string',  default: '' },
        limit:          { type: 'integer', default: 10 },
        showAmount:     { type: 'boolean', default: true },
        showDonorCount: { type: 'boolean', default: false },
        hideAnonymous:  { type: 'boolean', default: false },
        layout:         { type: 'string',  default: 'list' },
        showRank:       { type: 'boolean', default: true },
    },
    edit: function TopDonorsEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && Number( campaign.donations_count ) === 0 ) {
            issues.push( __( 'No donations yet, so the leaderboard will be empty on the page.', 'dono' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Top donors', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'dono' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Top supporters', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Layout', 'dono' ) }
                        value={ attributes.layout }
                        options={ [
                            { value: 'list',   label: __( 'List',   'dono' ) },
                            { value: 'podium', label: __( 'Podium', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { layout: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donors', 'dono' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 3 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'dono' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation count per donor', 'dono' ) }
                        checked={ attributes.showDonorCount }
                        onChange={ ( v ) => setAttributes( { showDonorCount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Hide anonymous donors', 'dono' ) }
                        checked={ attributes.hideAnonymous }
                        onChange={ ( v ) => setAttributes( { hideAnonymous: v } ) }
                        help={ __( 'When off, anonymous donors appear as "Anonymous".', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show rank', 'dono' ) }
                        checked={ attributes.showRank }
                        onChange={ ( v ) => setAttributes( { showRank: v } ) }
                        help={ __( 'Show the numbered position for each donor.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/top-donors"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="awards"
                className="dono-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="dono-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Top supporters', 'dono' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

// dono/recent-donations
registerBlockType( 'dono/recent-donations', {
    apiVersion: 3,
    title:      __( 'Recent donations', 'dono' ),
    description: __( 'Live feed of the most recent paid donations for this campaign.', 'dono' ),
    category:   'dono',
    icon:       'list-view',
    attributes: {
        campaignId:    { type: 'integer', default: 0 },
        title:         { type: 'string',  default: '' },
        limit:         { type: 'integer', default: 10 },
        showAmount:    { type: 'boolean', default: true },
        showTime:      { type: 'boolean', default: true },
        showMessage:   { type: 'boolean', default: true },
        showAnonymous: { type: 'boolean', default: true },
    },
    edit: function RecentDonationsEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && Number( campaign.donations_count ) === 0 ) {
            issues.push( __( 'No donations yet, so the feed will be empty on the page.', 'dono' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Recent donations', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'dono' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Recent donations', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donations', 'dono' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 1 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show amount', 'dono' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show time ago', 'dono' ) }
                        checked={ attributes.showTime }
                        onChange={ ( v ) => setAttributes( { showTime: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'dono' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Include anonymous donations', 'dono' ) }
                        checked={ attributes.showAnonymous }
                        onChange={ ( v ) => setAttributes( { showAnonymous: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/recent-donations"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="list-view"
                className="dono-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="dono-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Recent donations', 'dono' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

// dono/supporter-wall
registerBlockType( 'dono/supporter-wall', {
    apiVersion: 3,
    title:      __( 'Supporter wall', 'dono' ),
    description: __( 'A wall of campaign supporters with optional messages.', 'dono' ),
    category:   'dono',
    icon:       'groups',
    attributes: {
        campaignId:     { type: 'integer', default: 0 },
        title:          { type: 'string',  default: '' },
        limit:          { type: 'integer', default: 50 },
        sort:           { type: 'string',  default: 'recent' },
        showMessage:    { type: 'boolean', default: true },
        showAmount:     { type: 'boolean', default: false },
        minAmountCents: { type: 'integer', default: 0 },
        columns:        { type: 'string',  default: 'auto' },
    },
    edit: function SupporterWallEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && Number( campaign.donations_count ) === 0 ) {
            issues.push( __( 'No donations yet, so the wall will be empty on the page.', 'dono' ) );
        }
        // Display in major units; store as cents.
        const minAmountMajor = ( Number( attributes.minAmountCents ) || 0 ) / 100;
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Supporter wall', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'dono' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Thank you to our supporters', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Sort by', 'dono' ) }
                        value={ attributes.sort }
                        options={ [
                            { value: 'recent',       label: __( 'Most recent',  'dono' ) },
                            { value: 'alphabetical', label: __( 'Alphabetical', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { sort: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of supporters', 'dono' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 50 } ) }
                        min={ 5 }
                        max={ 500 }
                        step={ 5 }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Minimum donation amount', 'dono' ) }
                        type="number"
                        min={ 0 }
                        step="0.01"
                        value={ String( minAmountMajor || '' ) }
                        onChange={ ( v ) => {
                            const major = Number( v );
                            const cents = Number.isFinite( major ) && major > 0
                                ? Math.round( major * 100 )
                                : 0;
                            setAttributes( { minAmountCents: cents } );
                        } }
                        help={ __( 'Only show donors who gave at least this amount. 0 = no minimum.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'dono' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'dono' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Columns', 'dono' ) }
                        value={ attributes.columns }
                        options={ [
                            { value: 'auto', label: __( 'Auto', 'dono' ) },
                            { value: '2',    label: __( '2', 'dono' ) },
                            { value: '3',    label: __( '3', 'dono' ) },
                            { value: '4',    label: __( '4', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/supporter-wall"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="groups"
                className="dono-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="dono-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Thank you to our supporters', 'dono' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

// dono/campaign-grid
registerBlockType( 'dono/campaign-grid', {
    apiVersion: 3,
    title:       __( 'Campaigns grid', 'dono' ),
    description: __( 'A responsive grid of other published campaigns as cards.', 'dono' ),
    category:   'dono',
    icon:       'grid-view',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        count:      { type: 'integer', default: 3 },
        orderBy:    { type: 'string',  default: 'recent' },
        heading:    { type: 'string',  default: '' },
    },
    edit: function GridEdit( { attributes, setAttributes } ) {
        const { onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Campaigns grid', 'dono' ) }>
                    <TextControl
                        label={ __( 'Heading', 'dono' ) }
                        value={ attributes.heading }
                        onChange={ ( v ) => setAttributes( { heading: v } ) }
                        placeholder={ __( 'More ways to give', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'How many', 'dono' ) }
                        value={ attributes.count }
                        min={ 1 }
                        max={ 12 }
                        onChange={ ( v ) => setAttributes( { count: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Order by', 'dono' ) }
                        value={ attributes.orderBy }
                        options={ [
                            { value: 'recent',      label: __( 'Most recent', 'dono' ) },
                            { value: 'most-funded', label: __( 'Most funded', 'dono' ) },
                            { value: 'ending-soon', label: __( 'Ending soon', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { orderBy: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { ! onCampaignPage && (
                        <>
                            <CampaignPicker
                                value={ attributes.campaignId }
                                onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                                noneLabel={ __( 'Exclude none', 'dono' ) }
                            />
                            <p className="dono-block-note dono-block-note--muted">
                                { __( 'The selected campaign (or this page\'s campaign) is excluded from the grid.', 'dono' ) }
                            </p>
                        </>
                    ) }
                </PanelBody>
            </InspectorControls>
            <div { ...useBlockProps() }>
                <Disabled>
                    <ServerSideRender
                        block="dono/campaign-grid"
                        attributes={ { ...attributes, campaignId: attributes.campaignId || resolvedId } }
                    />
                </Disabled>
            </div>
        </>;
    },
    save: () => null,
} );

// dono/donation-form
registerBlockType( 'dono/donation-form', {
    apiVersion: 3,
    title:       __( 'Donation form', 'dono' ),
    description: __( 'Renders the campaign donation form inline on the page.', 'dono' ),
    category:   'dono',
    icon:       'money-alt',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        align:      { type: 'string',  default: 'left' },
    },
    edit: function DonationFormEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donation form', 'dono' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        campaign={ campaign }
                        onCampaignPage={ onCampaignPage }
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono' ) },
                            { value: 'center', label: __( 'Center', 'dono' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/donation-form"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="money-alt"
            />
        </>;
    },
    save: () => null,
} );
