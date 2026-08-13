// Every block here is server-rendered, so the editor previews through
// ServerSideRender. campaignId=0 falls back to the page's _dono_campaign_id
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

import { registerDonoEntities } from '../_shared/entities';
import { registerCampaignBindingSource } from './bindings.js';
import { defaultCurrency, currencyDecimals } from '../_shared/format';
import './blocks.scss';

registerDonoEntities();

// The client half of the binding source PHP registers. Without it a bound core
// block shows the source's label instead of the campaign's own value.
registerCampaignBindingSource( ( window.donoCampaignBlocks || {} ).bindingFields || {} );

function useBoundCampaign( campaignId ) {
    const postMetaId = useSelect( ( select ) => {
        const editor = select( 'core/editor' );
        if ( ! editor || ! editor.getEditedPostAttribute ) return 0;
        const meta = editor.getEditedPostAttribute( 'meta' ) || {};
        return Number( meta._dono_campaign_id || 0 );
    }, [] );

    const resolvedId = campaignId || postMetaId || 0;
    const { record, hasResolved } = useEntityRecord( 'dono/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );

    // A page keeps _dono_campaign_id after the campaign it names is deleted.
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
    const { records } = useEntityRecords( 'dono/v1', 'campaign', { per_page: 100 } );
    // useEntityRecords yields `records: null` until the fetch resolves, and a
    // destructuring default only replaces `undefined`, so this guard is what
    // keeps the inspector from throwing on selection.
    const campaigns = Array.isArray( records ) ? records : [];
    return (
        <SelectControl
            label={ __( 'Campaign', 'dono-fundraising-platform' ) }
            value={ String( value || 0 ) }
            options={ [
                { value: '0', label: noneLabel || __( 'Select a campaign', 'dono-fundraising-platform' ) },
                ...campaigns.map( ( c ) => ( { value: String( c.id ), label: c.title } ) ),
            ] }
            onChange={ ( v ) => onChange( Number( v ) ) }
            __nextHasNoMarginBottom
        />
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
    const { record: boundCampaign } = useEntityRecord( 'dono/v1', 'campaign', resolvedId, {
        enabled: resolvedId > 0,
    } );
    const revision = boundCampaign?.updated_at;

    if ( ! onCampaignPage && ! attributes.campaignId ) {
        return (
            <div { ...blockProps }>
                <Placeholder
                    icon={ icon }
                    label={ __( 'Dono campaign block', 'dono-fundraising-platform' ) }
                    instructions={ __( 'Choose which campaign this block should display.', 'dono-fundraising-platform' ) }
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
                    urlQueryArgs={ revision ? { dono_rev: revision } : undefined }
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
    const { records } = useEntityRecords( 'dono/v1', 'campaign', { per_page: 100 } );
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
        saveEntityRecord( 'dono/v1', 'campaign', {
            id: campaignId,
            // null clears it; the schema refuses 0.
            image_attachment_id: attachmentId,
        } )
            .catch( () => setError( __( 'That image could not be saved to the campaign.', 'dono-fundraising-platform' ) ) )
            .finally( () => setBusy( false ) );
    };

    const current = Number( campaign.image_attachment_id || 0 );

    return (
        <div className="dono-block-image-picker">
            { !! campaign.image_url && (
                <img
                    className="dono-block-image-picker__preview"
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
                        <div className="dono-block-image-picker__actions">
                            <Button variant="secondary" onClick={ open } disabled={ busy }>
                                { current
                                    ? __( 'Replace image', 'dono-fundraising-platform' )
                                    : __( 'Choose image', 'dono-fundraising-platform' ) }
                            </Button>
                            { !! current && (
                                <Button variant="tertiary" isDestructive onClick={ () => apply( null ) } disabled={ busy }>
                                    { __( 'Remove', 'dono-fundraising-platform' ) }
                                </Button>
                            ) }
                        </div>
                    ) }
                />
            </MediaUploadCheck>

            <p className="dono-block-image-picker__note">
                { __( 'Saved to the campaign as soon as you choose, and used everywhere the campaign appears.', 'dono-fundraising-platform' ) }
            </p>

            { error && <Notice status="error">{ error }</Notice> }
        </div>
    );
}

registerBlockType( 'dono/campaign-image', {
    apiVersion: 3,
    title:       __( 'Campaign image', 'dono-fundraising-platform' ),
    description: __( "The campaign's cover photo. Follows the campaign, not the page it sits on.", 'dono-fundraising-platform' ),
    category:    'dono',
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
                <PanelBody title={ __( 'Image', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <CampaignImagePicker campaign={ campaign } campaignId={ resolvedId } />
                    <SelectControl
                        label={ __( 'Aspect ratio', 'dono-fundraising-platform' ) }
                        value={ attributes.aspectRatio }
                        options={ [
                            { value: '16-9', label: __( 'Wide (16:9)',     'dono-fundraising-platform' ) },
                            { value: '3-2',  label: __( 'Photo (3:2)',     'dono-fundraising-platform' ) },
                            { value: '4-3',  label: __( 'Classic (4:3)',   'dono-fundraising-platform' ) },
                            { value: '1-1',  label: __( 'Square (1:1)',    'dono-fundraising-platform' ) },
                            { value: 'auto', label: __( "The image's own", 'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { aspectRatio: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Rounded corners', 'dono-fundraising-platform' ) }
                        checked={ attributes.rounded }
                        onChange={ ( v ) => setAttributes( { rounded: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Load with priority', 'dono-fundraising-platform' ) }
                        help={ __( 'Leave on when this is the first image a visitor sees. Turn it off further down the page so it loads only when needed.', 'dono-fundraising-platform' ) }
                        checked={ attributes.priority }
                        onChange={ ( v ) => setAttributes( { priority: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/campaign-image"
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
    { value: 'raised',    label: __( 'Amount raised',    'dono-fundraising-platform' ) },
    { value: 'goal',      label: __( 'Our goal',         'dono-fundraising-platform' ) },
    { value: 'remaining', label: __( 'Still needed',     'dono-fundraising-platform' ) },
    { value: 'percent',   label: __( 'Of goal reached',  'dono-fundraising-platform' ) },
    { value: 'donations', label: __( 'Donations',        'dono-fundraising-platform' ) },
    { value: 'donors',    label: __( 'Donors',           'dono-fundraising-platform' ) },
    { value: 'average',   label: __( 'Average donation', 'dono-fundraising-platform' ) },
    { value: 'top',       label: __( 'Top donation',     'dono-fundraising-platform' ) },
    { value: 'days_left', label: __( 'Days left',        'dono-fundraising-platform' ) },
];

// Metrics this campaign cannot answer, so the editor says so instead of leaving
// the author a block that renders nothing on the front end.
function statIssue( campaign, metric ) {
    if ( ! campaign ) return null;
    const noGoal = ! Number( campaign.goal_cents );
    if ( noGoal && [ 'goal', 'remaining', 'percent' ].includes( metric ) ) {
        return __( 'This campaign has no goal, so this stat will not render.', 'dono-fundraising-platform' );
    }
    if ( metric === 'days_left' && ! campaign.ends_at ) {
        return __( 'This campaign has no end date, so this stat will not render.', 'dono-fundraising-platform' );
    }
    if ( [ 'average', 'top' ].includes( metric ) && ! Number( campaign.donations_count ) ) {
        return __( 'No donations yet, so this stat will not render until the first one arrives.', 'dono-fundraising-platform' );
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
    const blockProps = useBlockProps( { className: 'dono-stat-empty' } );

    return (
        <div { ...blockProps }>
            <div className="dono-stat-empty__label">{ label }</div>
            <p className="dono-stat-empty__note">{ issue }</p>
        </div>
    );
}

registerBlockType( 'dono/campaign-stat', {
    apiVersion: 3,
    title:       __( 'Campaign stat', 'dono-fundraising-platform' ),
    description: __( 'A single campaign figure. Add one per number you want to show.', 'dono-fundraising-platform' ),
    category:    'dono',
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
                <PanelBody title={ __( 'Stat', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <SelectControl
                        label={ __( 'Figure', 'dono-fundraising-platform' ) }
                        value={ attributes.metric }
                        options={ STAT_METRICS }
                        onChange={ ( v ) => setAttributes( { metric: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Label', 'dono-fundraising-platform' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ fallbackLabel }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Size', 'dono-fundraising-platform' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'dono-fundraising-platform' ) },
                            { value: 'md', label: __( 'Medium', 'dono-fundraising-platform' ) },
                            { value: 'lg', label: __( 'Large',  'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono-fundraising-platform' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono-fundraising-platform' ) },
                            { value: 'center', label: __( 'Center', 'dono-fundraising-platform' ) },
                            { value: 'right',  label: __( 'Right',  'dono-fundraising-platform' ) },
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
                    block="dono/campaign-stat"
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

registerBlockType( 'dono/campaign-progress', {
    apiVersion: 3,
    title:      __( 'Campaign progress', 'dono-fundraising-platform' ),
    description: __( 'Progress bar toward the campaign goal.', 'dono-fundraising-platform' ),
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
                issues.push( __( 'No goal set on this campaign. Until you set one, the bar will sit at 0%.', 'dono-fundraising-platform' ) );
            }
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Progress', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <ToggleControl
                        label={ __( 'Show labels', 'dono-fundraising-platform' ) }
                        checked={ attributes.showLabels }
                        onChange={ ( v ) => setAttributes( { showLabels: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono-fundraising-platform' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono-fundraising-platform' ) },
                            { value: 'center', label: __( 'Center', 'dono-fundraising-platform' ) },
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

registerBlockType( 'dono/donate-button', {
    apiVersion: 3,
    title:      __( 'Donate button', 'dono-fundraising-platform' ),
    description: __( 'Button that opens the campaign\'s default donation form.', 'dono-fundraising-platform' ),
    category:   'dono',
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
            issues.push( __( 'This campaign has no default form. The button will appear but clicking it won\'t open anything until a form is set.', 'dono-fundraising-platform' ) );
        }
        if ( campaign?.status === 'archived' ) {
            issues.push( __( 'This campaign is archived. The button will render but submissions will be rejected.', 'dono-fundraising-platform' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donate button', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Label', 'dono-fundraising-platform' ) }
                        value={ attributes.label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Donate now', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Alignment', 'dono-fundraising-platform' ) }
                        value={ attributes.align }
                        options={ [
                            { value: 'left',   label: __( 'Left',   'dono-fundraising-platform' ) },
                            { value: 'center', label: __( 'Center', 'dono-fundraising-platform' ) },
                            { value: 'right',  label: __( 'Right',  'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { align: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Button size', 'dono-fundraising-platform' ) }
                        value={ attributes.size }
                        options={ [
                            { value: 'sm', label: __( 'Small',  'dono-fundraising-platform' ) },
                            { value: 'md', label: __( 'Medium', 'dono-fundraising-platform' ) },
                            { value: 'lg', label: __( 'Large',  'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { size: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Full width', 'dono-fundraising-platform' ) }
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

registerBlockType( 'dono/top-donors', {
    apiVersion: 3,
    title:      __( 'Top donors', 'dono-fundraising-platform' ),
    description: __( 'Leaderboard of the donors who gave the most to this campaign.', 'dono-fundraising-platform' ),
    category:   'dono',
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
            issues.push( __( 'No donations yet, so the leaderboard will be empty on the page.', 'dono-fundraising-platform' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Top donors', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'dono-fundraising-platform' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Top supporters', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'dono-fundraising-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'No donors to rank yet.', 'dono-fundraising-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Layout', 'dono-fundraising-platform' ) }
                        value={ attributes.layout }
                        options={ [
                            { value: 'list',   label: __( 'List',   'dono-fundraising-platform' ) },
                            { value: 'podium', label: __( 'Podium', 'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { layout: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donors', 'dono-fundraising-platform' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 3 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'dono-fundraising-platform' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation count per donor', 'dono-fundraising-platform' ) }
                        checked={ attributes.showDonorCount }
                        onChange={ ( v ) => setAttributes( { showDonorCount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Hide anonymous donors', 'dono-fundraising-platform' ) }
                        checked={ attributes.hideAnonymous }
                        onChange={ ( v ) => setAttributes( { hideAnonymous: v } ) }
                        help={ __( 'When off, anonymous donors appear as "Anonymous".', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/top-donors"
                editableTitle
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
                    placeholder={ __( 'Top supporters', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'dono/recent-donations', {
    apiVersion: 3,
    title:      __( 'Recent donations', 'dono-fundraising-platform' ),
    description: __( 'Live feed of the most recent paid donations for this campaign.', 'dono-fundraising-platform' ),
    category:   'dono',
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
            issues.push( __( 'No donations yet, so the feed will be empty on the page.', 'dono-fundraising-platform' ) );
        }
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Recent donations', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'dono-fundraising-platform' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Recent donations', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'dono-fundraising-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'No donations to show yet.', 'dono-fundraising-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of donations', 'dono-fundraising-platform' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 10 } ) }
                        min={ 1 }
                        max={ 50 }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show amount', 'dono-fundraising-platform' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show time ago', 'dono-fundraising-platform' ) }
                        checked={ attributes.showTime }
                        onChange={ ( v ) => setAttributes( { showTime: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'dono-fundraising-platform' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Include anonymous donations', 'dono-fundraising-platform' ) }
                        checked={ attributes.showAnonymous }
                        onChange={ ( v ) => setAttributes( { showAnonymous: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/recent-donations"
                editableTitle
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
                    placeholder={ __( 'Recent donations', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'dono/supporter-wall', {
    apiVersion: 3,
    title:      __( 'Supporter wall', 'dono-fundraising-platform' ),
    description: __( 'A wall of campaign supporters with optional messages.', 'dono-fundraising-platform' ),
    category:   'dono',
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
            issues.push( __( 'No donations yet, so the wall will be empty on the page.', 'dono-fundraising-platform' ) );
        }
        // Displayed in major units, stored as cents. Entry decimals follow the
        // org currency (JPY none, BHD three) so the step matches what renders.
        const minAmountMajor = ( Number( attributes.minAmountCents ) || 0 ) / 100;
        const minAmountDp    = currencyDecimals( defaultCurrency() );
        const minAmountStep  = minAmountDp > 0 ? '0.' + '0'.repeat( minAmountDp - 1 ) + '1' : '1';
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Supporter wall', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                        issues={ issues }
                    />
                    <TextControl
                        label={ __( 'Title', 'dono-fundraising-platform' ) }
                        value={ attributes.title }
                        onChange={ ( v ) => setAttributes( { title: v } ) }
                        placeholder={ __( 'Thank you to our supporters', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'dono-fundraising-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'The supporter wall is empty.', 'dono-fundraising-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Sort by', 'dono-fundraising-platform' ) }
                        value={ attributes.sort }
                        options={ [
                            { value: 'recent',       label: __( 'Most recent',  'dono-fundraising-platform' ) },
                            { value: 'alphabetical', label: __( 'Alphabetical', 'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { sort: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'Number of supporters', 'dono-fundraising-platform' ) }
                        value={ attributes.limit }
                        onChange={ ( v ) => setAttributes( { limit: Number( v ) || 50 } ) }
                        min={ 5 }
                        max={ 500 }
                        step={ 5 }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Minimum donation amount', 'dono-fundraising-platform' ) }
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
                        help={ __( 'Only show donors who gave at least this amount. 0 = no minimum.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor message', 'dono-fundraising-platform' ) }
                        checked={ attributes.showMessage }
                        onChange={ ( v ) => setAttributes( { showMessage: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donation amount', 'dono-fundraising-platform' ) }
                        checked={ attributes.showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Columns', 'dono-fundraising-platform' ) }
                        value={ attributes.columns }
                        options={ [
                            { value: 'auto', label: __( 'Auto', 'dono-fundraising-platform' ) },
                            { value: '2',    label: __( '2', 'dono-fundraising-platform' ) },
                            { value: '3',    label: __( '3', 'dono-fundraising-platform' ) },
                            { value: '4',    label: __( '4', 'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { columns: v } ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/supporter-wall"
                editableTitle
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
                    placeholder={ __( 'Thank you to our supporters', 'dono-fundraising-platform' ) }
                    allowedFormats={ [] }
                />
            </CampaignCanvas>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'dono/campaign-grid', {
    apiVersion: 3,
    title:       __( 'Campaigns grid', 'dono-fundraising-platform' ),
    description: __( 'A responsive grid of other published campaigns as cards.', 'dono-fundraising-platform' ),
    category:   'dono',
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
                <PanelBody title={ __( 'Campaigns grid', 'dono-fundraising-platform' ) }>
                    <TextControl
                        label={ __( 'Heading', 'dono-fundraising-platform' ) }
                        value={ attributes.heading }
                        onChange={ ( v ) => setAttributes( { heading: v } ) }
                        help={ __( 'Leave empty when a Heading block above this one already names the section, as the seeded layout does.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'dono-fundraising-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'This is the only campaign running right now.', 'dono-fundraising-platform' ) }
                        help={ __( 'Shown when there is nothing to list yet, so a heading above this block never captions the wrong thing.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <RangeControl
                        label={ __( 'How many', 'dono-fundraising-platform' ) }
                        value={ attributes.count }
                        min={ 1 }
                        max={ 12 }
                        onChange={ ( v ) => setAttributes( { count: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <SelectControl
                        label={ __( 'Order by', 'dono-fundraising-platform' ) }
                        value={ attributes.orderBy }
                        options={ [
                            { value: 'recent',      label: __( 'Most recent', 'dono-fundraising-platform' ) },
                            { value: 'most-funded', label: __( 'Most funded', 'dono-fundraising-platform' ) },
                            { value: 'ending-soon', label: __( 'Ending soon', 'dono-fundraising-platform' ) },
                        ] }
                        onChange={ ( v ) => setAttributes( { orderBy: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { ! onCampaignPage && (
                        <>
                            <CampaignPicker
                                value={ attributes.campaignId }
                                onChange={ ( v ) => setAttributes( { campaignId: v } ) }
                                noneLabel={ __( 'Exclude none', 'dono-fundraising-platform' ) }
                            />
                            <p className="dono-block-note dono-block-note--muted">
                                { __( 'The selected campaign (or this page\'s campaign) is excluded from the grid.', 'dono-fundraising-platform' ) }
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
                        block="dono/campaign-grid"
                        attributes={ { ...attributes, campaignId: attributes.campaignId || resolvedId } }
                        urlQueryArgs={ revision ? { dono_rev: revision } : undefined }
                    />
                </Disabled>
            </div>
        </>;
    },
    save: () => null,
} );

registerBlockType( 'dono/donation-form', {
    apiVersion: 3,
    title:       __( 'Donation form', 'dono-fundraising-platform' ),
    description: __( 'Renders the campaign donation form inline on the page.', 'dono-fundraising-platform' ),
    category:   'dono',
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
                ? `admin.php?page=dono-forms&form=${ formId }`
                : `admin.php?page=dono-campaigns&view=detail&id=${ resolvedId }&tab=forms`,
            window.location.href
        ).href;
        return <>
            <InspectorControls>
                <PanelBody title={ __( 'Donation form', 'dono-fundraising-platform' ) }>
                    <CampaignField
                        attributes={ attributes }
                        setAttributes={ setAttributes }
                        onCampaignPage={ onCampaignPage }
                    />
                    <TextControl
                        label={ __( 'Empty state text', 'dono-fundraising-platform' ) }
                        value={ attributes.emptyText }
                        onChange={ ( v ) => setAttributes( { emptyText: v } ) }
                        placeholder={ __( 'Donations are not open for this campaign yet.', 'dono-fundraising-platform' ) }
                        help={ __( 'Shown when the campaign is not taking donations, so the heading above this block never captions an empty space.', 'dono-fundraising-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    { campaign && (
                        <p className="dono-block-note">
                            <Button
                                variant="secondary"
                                href={ formEditUrl }
                                target="_blank"
                                __next40pxDefaultSize
                            >
                                { formId
                                    ? __( 'Edit donation form', 'dono-fundraising-platform' )
                                    : __( 'Manage donation forms', 'dono-fundraising-platform' ) }
                            </Button>
                        </p>
                    ) }
                </PanelBody>
            </InspectorControls>
            <CampaignCanvas
                block="dono/donation-form"
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
