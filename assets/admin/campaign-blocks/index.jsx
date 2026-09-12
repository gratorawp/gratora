// Every block here is server-rendered, so the editor previews through
// ServerSideRender. campaignId=0 falls back to the page's _gratora_campaign_id
// post meta.

import { useDispatch } from '@wordpress/data';
import { useEntityRecord, useEntityRecords, store as coreStore } from '@wordpress/core-data';
import { useState } from '@wordpress/element';
import { registerBlockType } from '@wordpress/blocks';
import {
    InspectorControls,
    MediaUpload,
    MediaUploadCheck,
    RichText,
    useBlockProps,
} from '@wordpress/block-editor';
import {
    Button,
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

import { registerGratoraEntities } from '../_shared/entities';
import { CampaignPicker, canManageCampaigns, useBoundCampaign } from './campaign-field';
import './LayoutSwitcher';
import { registerCampaignBindingSource } from './bindings.js';
import { defaultCurrency, amountEntry } from '../_shared/format';
import './blocks.scss';

registerGratoraEntities();

// The client half of the binding source PHP registers. Without it a bound core
// block shows the source's label instead of the campaign's own value.
registerCampaignBindingSource( ( window.gratoraCampaignBlocks || {} ).bindingFields || {} );

/**
 * The campaign a block reads from.
 *
 * On a campaign page the block inherits that page's campaign and there is
 * nothing to decide, so this renders nothing at all. Anywhere else it is the
 * picker and only the picker. Warnings still come through, because they name
 * something the author can act on rather than restating the binding.
 */
function CampaignField( { attributes, setAttributes, onCampaignPage, issues = [] } ) {
    return (
        <>
            { ! onCampaignPage && ( canManageCampaigns() || ! attributes.campaignId ) && (
                <CampaignPicker
                    value={ attributes.campaignId }
                    onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                />
            ) }
            { issues.map( ( msg, i ) => (
                <Notice key={ i } status="warning" isDismissible={ false }>{ msg }</Notice>
            ) ) }
        </>
    );
}

// The block-renderer endpoint has no post context, so the resolved campaign id
// has to be passed to ServerSideRender explicitly.
function CampaignCanvas( { block, attributes, setAttributes, onCampaignPage, resolvedId, icon = 'megaphone', className, children, isSelected = false, interactive = false, editableTitle = false } ) {
    const blockProps = useBlockProps( className ? { className } : {} );

    // Include the campaign timestamp so server previews refresh when campaign data changes
    // without block edits.
    const { record: boundCampaign } = useEntityRecord( 'gratora/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );
    const revision = boundCampaign?.updated_at;

    if ( ! onCampaignPage && ! attributes.campaignId ) {
        return (
            <div { ...blockProps }>
                <Placeholder
                    icon={ icon }
                    label={ __( 'Gratora campaign block', 'gratora-donation-platform' ) }
                    instructions={ __( 'Choose which campaign this block should display.', 'gratora-donation-platform' ) }
                >
                    <CampaignPicker
                        value={ attributes.campaignId }
                        onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                    />
                </Placeholder>
            </div>
        );
    }

    // Interactive previews stay disabled until the block is selected, so the
    // first click selects and later clicks drive the form. Toggling isDisabled
    // rather than swapping elements keeps the iframe from remounting.
    return (
        <div { ...blockProps }>
            { children }
            <Disabled isDisabled={ interactive ? ! isSelected : true }>
                { /* The server view prints its own h3, so a title edited in the
                     canvas above is blanked here or it renders twice. */ }
                <ServerSideRender
                    block={ block }
                    attributes={ editableTitle
                        ? { ...attributes, campaignId: resolvedId, title: '' }
                        : { ...attributes, campaignId: resolvedId } }
                    urlQueryArgs={ revision ? { gratora_rev: revision } : undefined }
                />
            </Disabled>
        </div>
    );
}

// Invalidate grid previews on collection size or latest timestamp changes; reuse the picker
// query.
function useCampaignsRevision() {
    const { records } = useEntityRecords( 'gratora/v1', 'campaign', { per_page: 100 } );
    if ( ! Array.isArray( records ) ) return undefined;

    const latest = records.reduce(
        ( max, c ) => ( c.updated_at && c.updated_at > max ? c.updated_at : max ),
        ''
    );
    return `${ records.length }:${ latest }`;
}

/** Update the campaign image immediately across all appearances, independently of page save. */
function CampaignImagePicker( { campaign, campaignId } ) {
    const { saveEntityRecord } = useDispatch( coreStore );
    const [ busy, setBusy ] = useState( false );
    const [ error, setError ] = useState( null );

    if ( ! campaignId || ! campaign ) return null;

    const apply = ( attachmentId ) => {
        setBusy( true );
        setError( null );
        // core-data reports a failed save through the store, not the promise,
        // unless it is told to throw.
        saveEntityRecord( 'gratora/v1', 'campaign', {
            id: campaignId,
            // null clears it; the schema refuses 0.
            image_attachment_id: attachmentId,
        }, { throwOnError: true } )
            .catch( ( err ) => setError(
                err?.message || __( 'That image could not be saved to the campaign.', 'gratora-donation-platform' )
            ) )
            .finally( () => setBusy( false ) );
    };

    const current = Number( campaign.image_attachment_id || 0 );

    return (
        <div className="gratora-block-image-picker">
            { !! campaign.image_url && (
                <img
                    className="gratora-block-image-picker__preview"
                    src={ campaign.image_url }
                    alt=""
                />
            ) }

            <MediaUploadCheck>
                <MediaUpload
                    allowedTypes={ [ 'image' ] }
                    value={ current }
                    onSelect={ ( media ) => apply( Number( media.id ) ) }
                    render={ ( { open } ) => (
                        <div className="gratora-block-image-picker__actions">
                            <Button variant="secondary" onClick={ open } disabled={ busy }>
                                { current
                                    ? __( 'Replace image', 'gratora-donation-platform' )
                                    : __( 'Choose image', 'gratora-donation-platform' ) }
                            </Button>
                            { !! current && (
                                <Button variant="tertiary" isDestructive onClick={ () => apply( null ) } disabled={ busy }>
                                    { __( 'Remove', 'gratora-donation-platform' ) }
                                </Button>
                            ) }
                        </div>
                    ) }
                />
            </MediaUploadCheck>

            <p className="gratora-block-image-picker__note">
                { __( 'Saved to the campaign as soon as you choose, and used everywhere the campaign appears.', 'gratora-donation-platform' ) }
            </p>

            { error && <Notice status="error">{ error }</Notice> }
        </div>
    );
}

registerBlockType( 'gratora/campaign-image', {
    apiVersion: 3,
    title:       __( 'Campaign image', 'gratora-donation-platform' ),
    description: __( "The campaign's cover photo. Follows the campaign, not the page it sits on.", 'gratora-donation-platform' ),
    category:    'gratora',
    icon:        'format-image',
    attributes: {
        campaignId:  { type: 'integer', default: 0 },
        aspectRatio: { type: 'string',  default: '16-9' },
        rounded:     { type: 'boolean', default: true },
        priority:    { type: 'boolean', default: true },
    },
    edit: function CampaignImageEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        // No "add one elsewhere" note: the picker below is where you add one.
        const issues = [];
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Image', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <CampaignImagePicker campaign={ campaign } campaignId={ resolvedId } />
                    <SelectControl
                        label={ __( 'Aspect ratio', 'gratora-donation-platform' ) }
                        value={ attributes.aspectRatio }
                        options={ [
                            { value: '16-9', label: __( 'Wide (16:9)',     'gratora-donation-platform' ) },
                            { value: '3-2',  label: __( 'Photo (3:2)',     'gratora-donation-platform' ) },
                            { value: '4-3',  label: __( 'Classic (4:3)',   'gratora-donation-platform' ) },
                            { value: '1-1',  label: __( 'Square (1:1)',    'gratora-donation-platform' ) },
                            { value: 'auto', label: __( "The image's own", 'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { aspectRatio: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Rounded corners', 'gratora-donation-platform' ) }
                        checked={ attributes.rounded }
                        onChange={ ( v ) => setAttributes( { rounded: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Load with priority', 'gratora-donation-platform' ) }
                        help={ __( 'Leave on when this is the first image a visitor sees. Turn it off further down the page so it loads only when needed.', 'gratora-donation-platform' ) }
                        checked={ attributes.priority }
                        onChange={ ( v ) => setAttributes( { priority: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/campaign-image"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="format-image"
            />
        </>;
    },
    save: () => null,
} );

// Mirrors CampaignStatMetrics::labels() in PHP, which is what actually renders;
// a key here that is not there falls back to raised.
const STAT_METRICS = [
    { value: 'raised',    label: __( 'Amount raised',    'gratora-donation-platform' ) },
    { value: 'goal',      label: __( 'Our goal',         'gratora-donation-platform' ) },
    { value: 'remaining', label: __( 'Still needed',     'gratora-donation-platform' ) },
    { value: 'percent',   label: __( 'Of goal reached',  'gratora-donation-platform' ) },
    { value: 'donations', label: __( 'Donations',        'gratora-donation-platform' ) },
    { value: 'donors',    label: __( 'Donors',           'gratora-donation-platform' ) },
    { value: 'average',   label: __( 'Average donation', 'gratora-donation-platform' ) },
    { value: 'top',       label: __( 'Top donation',     'gratora-donation-platform' ) },
    { value: 'days_left', label: __( 'Days left',        'gratora-donation-platform' ) },
];

// Metrics this campaign cannot answer, so the editor says so instead of leaving
// the author a block that renders nothing on the front end.
function statIssue( campaign, metric ) {
    if ( ! campaign ) return null;
    const goalType = campaign.goal_type || 'amount';
    const noGoal = ! Number( goalType === 'amount' ? campaign.goal_cents : campaign.goal_count );
    if ( noGoal && [ 'goal', 'remaining', 'percent' ].includes( metric ) ) {
        return __( 'This campaign has no goal, so this stat will not render.', 'gratora-donation-platform' );
    }
    if ( metric === 'days_left' && ! campaign.ends_at ) {
        return __( 'This campaign has no end date, so this stat will not render.', 'gratora-donation-platform' );
    }
    if ( [ 'average', 'top' ].includes( metric ) && ! Number( campaign.donations_count ) ) {
        return __( 'No donations yet, so this stat will not render until the first one arrives.', 'gratora-donation-platform' );
    }
    return null;
}

/**
 * Stands in for a stat the campaign cannot answer.
 *
 * Shows the figure's own label so the block still reads as the thing the
 * author placed, greyed rather than styled as an error: nothing is broken,
 * the number simply does not exist yet.
 */
function StatNotRendering( { label, issue } ) {
    const blockProps = useBlockProps( { className: 'gratora-stat-empty' } );

    return (
        <div { ...blockProps }>
            <div className="gratora-stat-empty__label">{ label }</div>
            <p className="gratora-stat-empty__note">{ issue }</p>
        </div>
    );
}

registerBlockType( 'gratora/campaign-stat', {
    apiVersion: 3,
    title:       __( 'Campaign stat', 'gratora-donation-platform' ),
    description: __( 'A single campaign figure. Add one per number you want to show.', 'gratora-donation-platform' ),
    category:    'gratora',
    icon:        'chart-bar',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        metric:     { type: 'string',  default: 'raised' },
        label:      { type: 'string',  default: '' },
        size:       { type: 'string',  default: 'sm' },
        align:      { type: 'string',  default: 'left' },
    },
    edit: function CampaignStatEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issue  = statIssue( campaign, attributes.metric );
        const issues = issue ? [ issue ] : [];
        const fallbackLabel = ( STAT_METRICS.find( ( m ) => m.value === attributes.metric ) || {} ).label || '';
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Stat', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <SelectControl
                        label={ __( 'Figure', 'gratora-donation-platform' ) }
                        value={ attributes.metric }
                        options={ STAT_METRICS }
                        onChange={ ( v ) => setAttributes( { metric: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Label', 'gratora-donation-platform' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ fallbackLabel }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Size', 'gratora-donation-platform' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'gratora-donation-platform' ) },
                            { value: 'md', label: __( 'Medium', 'gratora-donation-platform' ) },
                            { value: 'lg', label: __( 'Large',  'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'gratora-donation-platform' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'gratora-donation-platform' ) },
                            { value: 'center', label: __( 'Center', 'gratora-donation-platform' ) },
                            { value: 'right',  label: __( 'Right',  'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            { issue ? (
                // A metric the campaign cannot answer renders nothing, which is
                // right on the page and useless here: the editor would show
                // WordPress's own "Block rendered as empty" and leave the author
                // guessing which of their choices caused it.
                <StatNotRendering label={ fallbackLabel } issue={ issue } />
            ) : (
                <CampaignCanvas
                    block="gratora/campaign-stat"
                    attributes={ attributes }
                    setAttributes={ setAttributes }
                    onCampaignPage={ onCampaignPage }
                    resolvedId={ resolvedId }
                    icon="chart-bar"
                />
            ) }
        </>;
    },
    save: () => null,
} );

registerBlockType( 'gratora/campaign-progress', {
    apiVersion: 3,
    title:      __( 'Campaign progress', 'gratora-donation-platform' ),
    description: __( 'Progress bar toward the campaign goal.', 'gratora-donation-platform' ),
    category:   'gratora',
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
                issues.push( __( 'No goal set on this campaign. Until you set one, the bar will sit at 0%.', 'gratora-donation-platform' ) );
            }
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Progress', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <ToggleControl
                        label={ __( 'Show labels', 'gratora-donation-platform' ) }
                        checked={ attributes.showLabels }
                        onChange={ ( v ) => setAttributes( { showLabels: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'gratora-donation-platform' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'gratora-donation-platform' ) },
                            { value: 'center', label: __( 'Center', 'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/campaign-progress"
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

registerBlockType( 'gratora/donate-button', {
    apiVersion: 3,
    title:      __( 'Donate button', 'gratora-donation-platform' ),
    description: __( 'Button that opens the campaign\'s default donation form.', 'gratora-donation-platform' ),
    category:   'gratora',
    icon:       'heart',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        label:      { type: 'string',  default: '' },
        align:      { type: 'string',  default: 'left' },
        size:       { type: 'string',  default: 'md' },
        fullWidth:  { type: 'boolean', default: false },
    },
    edit: function DonateButtonEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && ! campaign.default_form_id ) {
            issues.push( __( 'This campaign has no default form. The button will appear but clicking it won\'t open anything until a form is set.', 'gratora-donation-platform' ) );
        }
        if ( campaign?.status === 'archived' ) {
            issues.push( __( 'This campaign is archived. The button will render but submissions will be rejected.', 'gratora-donation-platform' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donate button', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Label', 'gratora-donation-platform' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Donate now', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'gratora-donation-platform' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'gratora-donation-platform' ) },
                            { value: 'center', label: __( 'Center', 'gratora-donation-platform' ) },
                            { value: 'right',  label: __( 'Right',  'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Button size', 'gratora-donation-platform' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'gratora-donation-platform' ) },
                            { value: 'md', label: __( 'Medium', 'gratora-donation-platform' ) },
                            { value: 'lg', label: __( 'Large',  'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Full width', 'gratora-donation-platform' ) }
                        checked={ attributes.fullWidth }
                        onChange={ ( v ) => setAttributes( { fullWidth: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/donate-button"
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

registerBlockType( 'gratora/top-donors', {
    apiVersion: 3,
    title:      __( 'Top donors', 'gratora-donation-platform' ),
    description: __( 'Leaderboard of the donors who gave the most to this campaign.', 'gratora-donation-platform' ),
    category:   'gratora',
    icon:       'awards',
    attributes: {
        campaignId:     { type: 'integer', default: 0 },
        title:          { type: 'string',  default: '' },
        emptyText:      { type: 'string',  default: '' },
        limit:          { type: 'integer', default: 10 },
        showAmount:     { type: 'boolean', default: true },
        showDonorCount: { type: 'boolean', default: false },
        hideAnonymous:  { type: 'boolean', default: false },
        layout:         { type: 'string',  default: 'list' },
    },
    edit: function TopDonorsEdit( { attributes, setAttributes } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const issues = [];
        if ( campaign && Number( campaign.donations_count ) === 0 ) {
            issues.push( __( 'No donations yet, so the leaderboard will be empty on the page.', 'gratora-donation-platform' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Top donors', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'gratora-donation-platform' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Top supporters', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'gratora-donation-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'No donors to rank yet.', 'gratora-donation-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Layout', 'gratora-donation-platform' ) }
                        value={ attributes.layout }
                        options={ [
                            { value: 'list',   label: __( 'List',   'gratora-donation-platform' ) },
                            { value: 'podium', label: __( 'Podium', 'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { layout: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donors', 'gratora-donation-platform' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 3 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'gratora-donation-platform' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation count per donor', 'gratora-donation-platform' ) }
                        checked={ attributes.showDonorCount }
                        onChange={ ( v ) => setAttributes( { showDonorCount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Hide anonymous donors', 'gratora-donation-platform' ) }
                        checked={ attributes.hideAnonymous }
                        onChange={ ( v ) => setAttributes( { hideAnonymous: v } ) }
                        help={ __( 'When off, anonymous donors appear as "Anonymous".', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/top-donors"
                editableTitle
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="awards"
                className="gratora-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="gratora-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Top supporters', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'gratora/recent-donations', {
    apiVersion: 3,
    title:      __( 'Recent donations', 'gratora-donation-platform' ),
    description: __( 'Live feed of the most recent paid donations for this campaign.', 'gratora-donation-platform' ),
    category:   'gratora',
    icon:       'list-view',
    attributes: {
        campaignId:    { type: 'integer', default: 0 },
        title:         { type: 'string',  default: '' },
        emptyText:     { type: 'string',  default: '' },
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
            issues.push( __( 'No donations yet, so the feed will be empty on the page.', 'gratora-donation-platform' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Recent donations', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'gratora-donation-platform' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Recent donations', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'gratora-donation-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'No donations to show yet.', 'gratora-donation-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donations', 'gratora-donation-platform' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 1 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show amount', 'gratora-donation-platform' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show time ago', 'gratora-donation-platform' ) }
                        checked={ attributes.showTime }
                        onChange={ ( v ) => setAttributes( { showTime: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'gratora-donation-platform' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Include anonymous donations', 'gratora-donation-platform' ) }
                        checked={ attributes.showAnonymous }
                        onChange={ ( v ) => setAttributes( { showAnonymous: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/recent-donations"
                editableTitle
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="list-view"
                className="gratora-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="gratora-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Recent donations', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'gratora/supporter-wall', {
    apiVersion: 3,
    title:      __( 'Supporter wall', 'gratora-donation-platform' ),
    description: __( 'A wall of campaign supporters with optional messages.', 'gratora-donation-platform' ),
    category:   'gratora',
    icon:       'groups',
    attributes: {
        campaignId:     { type: 'integer', default: 0 },
        title:          { type: 'string',  default: '' },
        emptyText:      { type: 'string',  default: '' },
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
            issues.push( __( 'No donations yet, so the wall will be empty on the page.', 'gratora-donation-platform' ) );
        }
        // Displayed in major units, stored as cents.
        const minAmountMajor = ( Number( attributes.minAmountCents ) || 0 ) / 100;
        const { step: minAmountStep } = amountEntry( defaultCurrency() );
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Supporter wall', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'gratora-donation-platform' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Thank you to our supporters', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'gratora-donation-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'The supporter wall is empty.', 'gratora-donation-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Sort by', 'gratora-donation-platform' ) }
                        value={ attributes.sort }
                        options={ [
                            { value: 'recent',       label: __( 'Most recent',  'gratora-donation-platform' ) },
                            { value: 'alphabetical', label: __( 'Alphabetical', 'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { sort: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of supporters', 'gratora-donation-platform' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 50 } ) }
                        min={ 5 }
                        max={ 500 }
                        step={ 5 }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Minimum donation amount', 'gratora-donation-platform' ) }
                        type="number"
                        min={ 0 }
                        step={ minAmountStep }
                        value={ String( minAmountMajor || '' ) }
                        onChange={ ( v ) => {
                            const major = Number( v );
                            const cents = Number.isFinite( major ) && major > 0
                                ? Math.round( major * 100 )
                                : 0;
                            setAttributes( { minAmountCents: cents } );
                        } }
                        help={ __( 'Only show donors who gave at least this amount. 0 = no minimum.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'gratora-donation-platform' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'gratora-donation-platform' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Columns', 'gratora-donation-platform' ) }
                        value={ attributes.columns }
                        options={ [
                            { value: 'auto', label: __( 'Auto', 'gratora-donation-platform' ) },
                            { value: '2',    label: __( '2', 'gratora-donation-platform' ) },
                            { value: '3',    label: __( '3', 'gratora-donation-platform' ) },
                            { value: '4',    label: __( '4', 'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/supporter-wall"
                editableTitle
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="groups"
                className="gratora-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="gratora-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Thank you to our supporters', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'gratora/campaign-grid', {
    apiVersion: 3,
    title:       __( 'Campaigns grid', 'gratora-donation-platform' ),
    description: __( 'A responsive grid of other published campaigns as cards.', 'gratora-donation-platform' ),
    category:   'gratora',
    icon:       'grid-view',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        count:      { type: 'integer', default: 3 },
        orderBy:    { type: 'string',  default: 'recent' },
        heading:    { type: 'string',  default: '' },
        emptyText:  { type: 'string',  default: '' },
    },
    edit: function GridEdit( { attributes, setAttributes } ) {
        const { onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const revision = useCampaignsRevision();
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Campaigns grid', 'gratora-donation-platform' ) }>
                    <TextControl
                        label={ __( 'Heading', 'gratora-donation-platform' ) }
                        value={ attributes.heading }
                        onChange={ ( v ) => setAttributes( { heading: v } ) }
                        help={ __( 'Leave empty when a Heading block above this one already names the section, as the seeded layout does.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'gratora-donation-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        help={ __( 'Shown when there is nothing to list. Left empty, the block says whether this is the only campaign running or that none are, whichever fits the page.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'How many', 'gratora-donation-platform' ) }
                        value={ attributes.count }
                        min={ 1 }
                        max={ 12 }
                        onChange={ ( v ) => setAttributes( { count: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Order by', 'gratora-donation-platform' ) }
                        value={ attributes.orderBy }
                        options={ [
                            { value: 'recent',      label: __( 'Most recent', 'gratora-donation-platform' ) },
                            { value: 'most-funded', label: __( 'Most funded', 'gratora-donation-platform' ) },
                            { value: 'ending-soon', label: __( 'Ending soon', 'gratora-donation-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { orderBy: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { ! onCampaignPage && (
                        <>
                            <CampaignPicker
                                value={ attributes.campaignId }
                                onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                                noneLabel={ __( 'Exclude none', 'gratora-donation-platform' ) }
                            />
                            <p className="gratora-block-note gratora-block-note--muted">
                                { __( 'The selected campaign (or this page\'s campaign) is excluded from the grid.', 'gratora-donation-platform' ) }
                            </p>
                        </>
                    ) }
                </PanelBody>
            </InspectorControls>
            { /* Not CampaignCanvas: campaignId here is the campaign to leave
                 out, so the canvas's "choose a campaign" placeholder would
                 block the grid's own default of excluding nothing. */ }
            <div { ...useBlockProps() }>
                <Disabled>
                    <ServerSideRender
                        block="gratora/campaign-grid"
                        attributes={ { ...attributes, campaignId: attributes.campaignId || resolvedId } }
                        urlQueryArgs={ revision ? { gratora_rev: revision } : undefined }
                    />
                </Disabled>
            </div>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'gratora/donation-form', {
    apiVersion: 3,
    title:       __( 'Donation form', 'gratora-donation-platform' ),
    description: __( 'Renders the campaign donation form inline on the page.', 'gratora-donation-platform' ),
    category:   'gratora',
    icon:       'money-alt',
    attributes: {
        campaignId: { type: 'integer', default: 0 },
        emptyText:  { type: 'string',  default: '' },
    },
    edit: function DonationFormEdit( { attributes, setAttributes, isSelected } ) {
        const { campaign, onCampaignPage, resolvedId } = useBoundCampaign( attributes.campaignId );
        const formId = Number( campaign?.default_form_id || 0 );
        // The forms screen is a hidden page that renders only an editor, so
        // with no form to open the author goes to the campaign's Forms tab,
        // where one can be created.
        const formEditUrl = new URL(
            formId
                ? `admin.php?page=gratora-forms&form=${ formId }`
                : `admin.php?page=gratora-campaigns&view=detail&id=${ resolvedId }&tab=forms`,
            window.location.href
        ).href;
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donation form', 'gratora-donation-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'gratora-donation-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'Donations are not open for this campaign yet.', 'gratora-donation-platform' ) }
                        help={ __( 'Shown when the campaign is not taking donations, so the heading above this block never captions an empty space.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    { campaign && (
                        <p className="gratora-block-note">
                            <Button
                                variant="secondary"
                                href={ formEditUrl }
                                target="_blank"
                                __next40pxDefaultSize
                            >
                                { formId
                                    ? __( 'Edit donation form', 'gratora-donation-platform' )
                                    : __( 'Manage donation forms', 'gratora-donation-platform' ) }
                            </Button>
                        </p>
                    ) }
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="gratora/donation-form"
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="money-alt"
                isSelected={ isSelected }
                interactive
            />
        </>;
    },
    save: () => null,
} );
