// Every block here is server-rendered, so the editor previews through
// ServerSideRender. campaignId=0 falls back to the page's _fundkit_campaign_id
// post meta.

import { useSelect, useDispatch } from '@wordpress/data';
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

import { registerFundKitEntities } from '../_shared/entities';
import './LayoutSwitcher';
import { registerCampaignBindingSource } from './bindings.js';
import { defaultCurrency, amountEntry } from '../_shared/format';
import './blocks.scss';

registerFundKitEntities();

// The client half of the binding source PHP registers. Without it a bound core
// block shows the source's label instead of the campaign's own value.
registerCampaignBindingSource( ( window.fundkitCampaignBlocks || {} ).bindingFields || {} );

function useBoundCampaign( campaignId ) {
    const postMetaId = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        if ( ! editor || ! editor.getEditedPostAttribute ) return 0;
        const meta = editor.getEditedPostAttribute( 'meta' ) || {};
        return Number( meta._fundkit_campaign_id || 0 );
    }, [] );

    const resolvedId = campaignId || postMetaId || 0;
    const { record, hasResolved } = useEntityRecord( 'fundkit/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );

    // A page keeps _fundkit_campaign_id after the campaign it names is deleted.
    // Treating the meta alone as context hid the picker on a page that could no
    // longer resolve a campaign at all, so the canvas said to pick one in a
    // sidebar that was not offering the control.
    const orphaned = postMetaId > 0 && hasResolved && ! record;

    // Blocks on a campaign landing page inherit its campaign, so there is
    // nothing to pick. Undecided while the lookup is in flight, or the picker
    // flashes in and out on every load.
    const onCampaignPage = postMetaId > 0 && ! orphaned;

    return { campaign: record, onCampaignPage, resolvedId };
}

function CampaignPicker( { value, onChange, noneLabel } ) {
    const { records } = useEntityRecords( 'fundkit/v1', 'campaign', { per_page: 100 } );
    // useEntityRecords yields `records: null` until the fetch resolves, and a
    // destructuring default only replaces `undefined`, so this guard is what
    // keeps the inspector from throwing on selection.
    const campaigns = Array.isArray( records ) ? records : [];
    // The blocks are registered for every block-editor user, but the campaign
    // list is gated on a FundKit capability. Without this an Editor gets a picker
    // whose only option is "Select a campaign" and no idea why.
    const empty = Array.isArray( records ) && records.length === 0;

    return (
        <>
            <SelectControl
                label={ __( 'Campaign', 'fundraising-toolkit' ) }
                value={ String( value || 0 ) }
                options={ [
                    { value: '0', label: noneLabel || __( 'Select a campaign', 'fundraising-toolkit' ) },
                    ...campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title } ) ),
                ] }
                onChange={ ( v ) => onChange( Number( v ) ) }
                __nextHasNoMarginBottom
            />
            { empty && (
                <Notice status="warning" isDismissible={ false }>
                    { __( 'No campaigns are available to you. You may not have permission to view them, or none have been created yet.', 'fundraising-toolkit' ) }
                </Notice>
            ) }
        </>
    );
}

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
            { ! onCampaignPage && (
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

    // These previews are rendered by the server, so they only change when the
    // request does. Block attributes are the usual trigger, but the campaign is
    // a separate record: edit its image or its goal and the attributes are
    // untouched, so the canvas keeps showing the answer from before the edit.
    // Carrying the campaign's own timestamp in the query makes every campaign
    // change refresh every block bound to it, not just the one that made it.
    const { record: boundCampaign } = useEntityRecord( 'fundkit/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );
    const revision = boundCampaign?.updated_at;

    if ( ! onCampaignPage && ! attributes.campaignId ) {
        return (
            <div { ...blockProps }>
                <Placeholder
                    icon={ icon }
                    label={ __( 'Fundraising Toolkit campaign block', 'fundraising-toolkit' ) }
                    instructions={ __( 'Choose which campaign this block should display.', 'fundraising-toolkit' ) }
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
                    urlQueryArgs={ revision ? { fundkit_rev: revision } : undefined }
                />
            </Disabled>
        </div>
    );
}

// The grid draws campaigns other than the one it is bound to, so the record
// that changes is rarely the one CampaignCanvas watches: a goal edited on any
// published campaign changes what this block shows. The collection's own shape
// is the key. Its length catches a campaign published or deleted, the latest
// timestamp catches an edit to any of them, and the picker already holds this
// query so watching it costs no extra request.
function useCampaignsRevision() {
    const { records } = useEntityRecords( 'fundkit/v1', 'campaign', { per_page: 100 } );
    if ( ! Array.isArray( records ) ) return undefined;

    const latest = records.reduce(
        ( max, c ) => ( c.updated_at && c.updated_at > max ? c.updated_at : max ),
        ''
    );
    return `${ records.length }:${ latest }`;
}

/**
 * Picks the campaign's cover photo from inside the block.
 *
 * The image belongs to the campaign, not to this block or this page, so the
 * control edits the campaign record and says so: the change lands everywhere
 * that campaign appears, and it lands when you choose, not when the page is
 * saved. Sending people to the campaign screen for it was a dead end in an
 * inspector that had room for the control.
 */
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
        saveEntityRecord( 'fundkit/v1', 'campaign', {
            id: campaignId,
            // null clears it; the schema refuses 0.
            image_attachment_id: attachmentId,
        }, { throwOnError: true } )
            .catch( ( err ) => setError(
                err?.message || __( 'That image could not be saved to the campaign.', 'fundraising-toolkit' )
            ) )
            .finally( () => setBusy( false ) );
    };

    const current = Number( campaign.image_attachment_id || 0 );

    return (
        <div className="fundkit-block-image-picker">
            { !! campaign.image_url && (
                <img
                    className="fundkit-block-image-picker__preview"
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
                        <div className="fundkit-block-image-picker__actions">
                            <Button variant="secondary" onClick={ open } disabled={ busy }>
                                { current
                                    ? __( 'Replace image', 'fundraising-toolkit' )
                                    : __( 'Choose image', 'fundraising-toolkit' ) }
                            </Button>
                            { !! current && (
                                <Button variant="tertiary" isDestructive onClick={ () => apply( null ) } disabled={ busy }>
                                    { __( 'Remove', 'fundraising-toolkit' ) }
                                </Button>
                            ) }
                        </div>
                    ) }
                />
            </MediaUploadCheck>

            <p className="fundkit-block-image-picker__note">
                { __( 'Saved to the campaign as soon as you choose, and used everywhere the campaign appears.', 'fundraising-toolkit' ) }
            </p>

            { error && <Notice status="error">{ error }</Notice> }
        </div>
    );
}

registerBlockType( 'fundkit/campaign-image', {
    apiVersion: 3,
    title:       __( 'Campaign image', 'fundraising-toolkit' ),
    description: __( "The campaign's cover photo. Follows the campaign, not the page it sits on.", 'fundraising-toolkit' ),
    category:    'fundkit',
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
                <PanelBody title={ __( 'Image', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <CampaignImagePicker campaign={ campaign } campaignId={ resolvedId } />
                    <SelectControl
                        label={ __( 'Aspect ratio', 'fundraising-toolkit' ) }
                        value={ attributes.aspectRatio }
                        options={ [
                            { value: '16-9', label: __( 'Wide (16:9)',     'fundraising-toolkit' ) },
                            { value: '3-2',  label: __( 'Photo (3:2)',     'fundraising-toolkit' ) },
                            { value: '4-3',  label: __( 'Classic (4:3)',   'fundraising-toolkit' ) },
                            { value: '1-1',  label: __( 'Square (1:1)',    'fundraising-toolkit' ) },
                            { value: 'auto', label: __( "The image's own", 'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { aspectRatio: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Rounded corners', 'fundraising-toolkit' ) }
                        checked={ attributes.rounded }
                        onChange={ ( v ) => setAttributes( { rounded: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Load with priority', 'fundraising-toolkit' ) }
                        help={ __( 'Leave on when this is the first image a visitor sees. Turn it off further down the page so it loads only when needed.', 'fundraising-toolkit' ) }
                        checked={ attributes.priority }
                        onChange={ ( v ) => setAttributes( { priority: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/campaign-image"
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
    { value: 'raised',    label: __( 'Amount raised',    'fundraising-toolkit' ) },
    { value: 'goal',      label: __( 'Our goal',         'fundraising-toolkit' ) },
    { value: 'remaining', label: __( 'Still needed',     'fundraising-toolkit' ) },
    { value: 'percent',   label: __( 'Of goal reached',  'fundraising-toolkit' ) },
    { value: 'donations', label: __( 'Donations',        'fundraising-toolkit' ) },
    { value: 'donors',    label: __( 'Donors',           'fundraising-toolkit' ) },
    { value: 'average',   label: __( 'Average donation', 'fundraising-toolkit' ) },
    { value: 'top',       label: __( 'Top donation',     'fundraising-toolkit' ) },
    { value: 'days_left', label: __( 'Days left',        'fundraising-toolkit' ) },
];

// Metrics this campaign cannot answer, so the editor says so instead of leaving
// the author a block that renders nothing on the front end.
function statIssue( campaign, metric ) {
    if ( ! campaign ) return null;
    const goalType = campaign.goal_type || 'amount';
    const noGoal = ! Number( goalType === 'amount' ? campaign.goal_cents : campaign.goal_count );
    if ( noGoal && [ 'goal', 'remaining', 'percent' ].includes( metric ) ) {
        return __( 'This campaign has no goal, so this stat will not render.', 'fundraising-toolkit' );
    }
    if ( metric === 'days_left' && ! campaign.ends_at ) {
        return __( 'This campaign has no end date, so this stat will not render.', 'fundraising-toolkit' );
    }
    if ( [ 'average', 'top' ].includes( metric ) && ! Number( campaign.donations_count ) ) {
        return __( 'No donations yet, so this stat will not render until the first one arrives.', 'fundraising-toolkit' );
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
    const blockProps = useBlockProps( { className: 'fundkit-stat-empty' } );

    return (
        <div { ...blockProps }>
            <div className="fundkit-stat-empty__label">{ label }</div>
            <p className="fundkit-stat-empty__note">{ issue }</p>
        </div>
    );
}

registerBlockType( 'fundkit/campaign-stat', {
    apiVersion: 3,
    title:       __( 'Campaign stat', 'fundraising-toolkit' ),
    description: __( 'A single campaign figure. Add one per number you want to show.', 'fundraising-toolkit' ),
    category:    'fundkit',
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
                <PanelBody title={ __( 'Stat', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <SelectControl
                        label={ __( 'Figure', 'fundraising-toolkit' ) }
                        value={ attributes.metric }
                        options={ STAT_METRICS }
                        onChange={ ( v ) => setAttributes( { metric: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ fallbackLabel }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Size', 'fundraising-toolkit' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'fundraising-toolkit' ) },
                            { value: 'md', label: __( 'Medium', 'fundraising-toolkit' ) },
                            { value: 'lg', label: __( 'Large',  'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'fundraising-toolkit' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'fundraising-toolkit' ) },
                            { value: 'center', label: __( 'Center', 'fundraising-toolkit' ) },
                            { value: 'right',  label: __( 'Right',  'fundraising-toolkit' ) },
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
                    block="fundkit/campaign-stat"
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

registerBlockType( 'fundkit/campaign-progress', {
    apiVersion: 3,
    title:      __( 'Campaign progress', 'fundraising-toolkit' ),
    description: __( 'Progress bar toward the campaign goal.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
                issues.push( __( 'No goal set on this campaign. Until you set one, the bar will sit at 0%.', 'fundraising-toolkit' ) );
            }
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Progress', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <ToggleControl
                        label={ __( 'Show labels', 'fundraising-toolkit' ) }
                        checked={ attributes.showLabels }
                        onChange={ ( v ) => setAttributes( { showLabels: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'fundraising-toolkit' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'fundraising-toolkit' ) },
                            { value: 'center', label: __( 'Center', 'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/campaign-progress"
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

registerBlockType( 'fundkit/donate-button', {
    apiVersion: 3,
    title:      __( 'Donate button', 'fundraising-toolkit' ),
    description: __( 'Button that opens the campaign\'s default donation form.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
            issues.push( __( 'This campaign has no default form. The button will appear but clicking it won\'t open anything until a form is set.', 'fundraising-toolkit' ) );
        }
        if ( campaign?.status === 'archived' ) {
            issues.push( __( 'This campaign is archived. The button will render but submissions will be rejected.', 'fundraising-toolkit' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donate button', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Label', 'fundraising-toolkit' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Donate now', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'fundraising-toolkit' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'fundraising-toolkit' ) },
                            { value: 'center', label: __( 'Center', 'fundraising-toolkit' ) },
                            { value: 'right',  label: __( 'Right',  'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Button size', 'fundraising-toolkit' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'fundraising-toolkit' ) },
                            { value: 'md', label: __( 'Medium', 'fundraising-toolkit' ) },
                            { value: 'lg', label: __( 'Large',  'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Full width', 'fundraising-toolkit' ) }
                        checked={ attributes.fullWidth }
                        onChange={ ( v ) => setAttributes( { fullWidth: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/donate-button"
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

registerBlockType( 'fundkit/top-donors', {
    apiVersion: 3,
    title:      __( 'Top donors', 'fundraising-toolkit' ),
    description: __( 'Leaderboard of the donors who gave the most to this campaign.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
            issues.push( __( 'No donations yet, so the leaderboard will be empty on the page.', 'fundraising-toolkit' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Top donors', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'fundraising-toolkit' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Top supporters', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'fundraising-toolkit' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'No donors to rank yet.', 'fundraising-toolkit' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Layout', 'fundraising-toolkit' ) }
                        value={ attributes.layout }
                        options={ [
                            { value: 'list',   label: __( 'List',   'fundraising-toolkit' ) },
                            { value: 'podium', label: __( 'Podium', 'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { layout: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donors', 'fundraising-toolkit' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 3 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'fundraising-toolkit' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation count per donor', 'fundraising-toolkit' ) }
                        checked={ attributes.showDonorCount }
                        onChange={ ( v ) => setAttributes( { showDonorCount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Hide anonymous donors', 'fundraising-toolkit' ) }
                        checked={ attributes.hideAnonymous }
                        onChange={ ( v ) => setAttributes( { hideAnonymous: v } ) }
                        help={ __( 'When off, anonymous donors appear as "Anonymous".', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/top-donors"
                editableTitle
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="awards"
                className="fundkit-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="fundkit-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Top supporters', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'fundkit/recent-donations', {
    apiVersion: 3,
    title:      __( 'Recent donations', 'fundraising-toolkit' ),
    description: __( 'Live feed of the most recent paid donations for this campaign.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
            issues.push( __( 'No donations yet, so the feed will be empty on the page.', 'fundraising-toolkit' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Recent donations', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'fundraising-toolkit' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Recent donations', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'fundraising-toolkit' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'No donations to show yet.', 'fundraising-toolkit' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donations', 'fundraising-toolkit' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 1 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show amount', 'fundraising-toolkit' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show time ago', 'fundraising-toolkit' ) }
                        checked={ attributes.showTime }
                        onChange={ ( v ) => setAttributes( { showTime: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'fundraising-toolkit' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Include anonymous donations', 'fundraising-toolkit' ) }
                        checked={ attributes.showAnonymous }
                        onChange={ ( v ) => setAttributes( { showAnonymous: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/recent-donations"
                editableTitle
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="list-view"
                className="fundkit-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="fundkit-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Recent donations', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'fundkit/supporter-wall', {
    apiVersion: 3,
    title:      __( 'Supporter wall', 'fundraising-toolkit' ),
    description: __( 'A wall of campaign supporters with optional messages.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
            issues.push( __( 'No donations yet, so the wall will be empty on the page.', 'fundraising-toolkit' ) );
        }
        // Displayed in major units, stored as cents.
        const minAmountMajor = ( Number( attributes.minAmountCents ) || 0 ) / 100;
        const { step: minAmountStep } = amountEntry( defaultCurrency() );
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Supporter wall', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'fundraising-toolkit' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Thank you to our supporters', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'fundraising-toolkit' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'The supporter wall is empty.', 'fundraising-toolkit' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Sort by', 'fundraising-toolkit' ) }
                        value={ attributes.sort }
                        options={ [
                            { value: 'recent',       label: __( 'Most recent',  'fundraising-toolkit' ) },
                            { value: 'alphabetical', label: __( 'Alphabetical', 'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { sort: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of supporters', 'fundraising-toolkit' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 50 } ) }
                        min={ 5 }
                        max={ 500 }
                        step={ 5 }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Minimum donation amount', 'fundraising-toolkit' ) }
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
                        help={ __( 'Only show donors who gave at least this amount. 0 = no minimum.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'fundraising-toolkit' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'fundraising-toolkit' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Columns', 'fundraising-toolkit' ) }
                        value={ attributes.columns }
                        options={ [
                            { value: 'auto', label: __( 'Auto', 'fundraising-toolkit' ) },
                            { value: '2',    label: __( '2', 'fundraising-toolkit' ) },
                            { value: '3',    label: __( '3', 'fundraising-toolkit' ) },
                            { value: '4',    label: __( '4', 'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/supporter-wall"
                editableTitle
                attributes={ attributes }
                setAttributes={ setAttributes }
                onCampaignPage={ onCampaignPage }
                resolvedId={ resolvedId }
                icon="groups"
                className="fundkit-campaign-block-edit"
            >
                <RichText
                    tagName="h3"
                    className="fundkit-campaign-block-edit__title"
                    value={ attributes.title }
                    onChange={ ( v ) => setAttributes( { title: v } ) }
                    placeholder={ __( 'Thank you to our supporters', 'fundraising-toolkit' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'fundkit/campaign-grid', {
    apiVersion: 3,
    title:       __( 'Campaigns grid', 'fundraising-toolkit' ),
    description: __( 'A responsive grid of other published campaigns as cards.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
                <PanelBody title={ __( 'Campaigns grid', 'fundraising-toolkit' ) }>
                    <TextControl
                        label={ __( 'Heading', 'fundraising-toolkit' ) }
                        value={ attributes.heading }
                        onChange={ ( v ) => setAttributes( { heading: v } ) }
                        help={ __( 'Leave empty when a Heading block above this one already names the section, as the seeded layout does.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'fundraising-toolkit' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'This is the only campaign running right now.', 'fundraising-toolkit' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'How many', 'fundraising-toolkit' ) }
                        value={ attributes.count }
                        min={ 1 }
                        max={ 12 }
                        onChange={ ( v ) => setAttributes( { count: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Order by', 'fundraising-toolkit' ) }
                        value={ attributes.orderBy }
                        options={ [
                            { value: 'recent',      label: __( 'Most recent', 'fundraising-toolkit' ) },
                            { value: 'most-funded', label: __( 'Most funded', 'fundraising-toolkit' ) },
                            { value: 'ending-soon', label: __( 'Ending soon', 'fundraising-toolkit' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { orderBy: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { ! onCampaignPage && (
                        <>
                            <CampaignPicker
                                value={ attributes.campaignId }
                                onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                                noneLabel={ __( 'Exclude none', 'fundraising-toolkit' ) }
                            />
                            <p className="fundkit-block-note fundkit-block-note--muted">
                                { __( 'The selected campaign (or this page\'s campaign) is excluded from the grid.', 'fundraising-toolkit' ) }
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
                        block="fundkit/campaign-grid"
                        attributes={ { ...attributes, campaignId: attributes.campaignId || resolvedId } }
                        urlQueryArgs={ revision ? { fundkit_rev: revision } : undefined }
                    />
                </Disabled>
            </div>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'fundkit/donation-form', {
    apiVersion: 3,
    title:       __( 'Donation form', 'fundraising-toolkit' ),
    description: __( 'Renders the campaign donation form inline on the page.', 'fundraising-toolkit' ),
    category:   'fundkit',
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
                ? `admin.php?page=fundkit-forms&form=${ formId }`
                : `admin.php?page=fundkit-campaigns&view=detail&id=${ resolvedId }&tab=forms`,
            window.location.href
        ).href;
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donation form', 'fundraising-toolkit' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'fundraising-toolkit' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'Donations are not open for this campaign yet.', 'fundraising-toolkit' ) }
                        help={ __( 'Shown when the campaign is not taking donations, so the heading above this block never captions an empty space.', 'fundraising-toolkit' ) }
                        __nextHasNoMarginBottom
                    />
                    { campaign && (
                        <p className="fundkit-block-note">
                            <Button
                                variant="secondary"
                                href={ formEditUrl }
                                target="_blank"
                                __next40pxDefaultSize
                            >
                                { formId
                                    ? __( 'Edit donation form', 'fundraising-toolkit' )
                                    : __( 'Manage donation forms', 'fundraising-toolkit' ) }
                            </Button>
                        </p>
                    ) }
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="fundkit/donation-form"
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
