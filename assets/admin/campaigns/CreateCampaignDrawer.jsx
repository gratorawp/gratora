import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { detailHref } from '../_shared/format';
import Dialog from '../_shared/components/Dialog';
import Notice from '../_shared/components/Notice';
import Field from '../_shared/components/Field';
import Segmented from '../_shared/components/Segmented';
import AmountInput from '../_shared/components/AmountInput';
import SearchableSelect from '../_shared/components/SearchableSelect';
import ScheduleFields from '../_shared/components/ScheduleFields';
import { Switch } from '../_shared/components/Switch';
import Btn from '../_shared/components/Btn';
import CampaignTemplatePicker from '../_shared/components/CampaignTemplatePicker';

import { DollarSign, HandHeart, Users, Ban, ImagePlus, Plus } from 'lucide-react';

const GOAL_OPTIONS = [
    { value: 'amount',    label: __( 'Amount', 'gratora' ),    icon: <DollarSign strokeWidth={ 1.75 } /> },
    { value: 'donations', label: __( 'Donations', 'gratora' ), icon: <HandHeart strokeWidth={ 1.75 } /> },
    { value: 'donors',    label: __( 'Donors', 'gratora' ),    icon: <Users strokeWidth={ 1.75 } /> },
    { value: 'none',      label: __( 'No goal', 'gratora' ),   icon: <Ban strokeWidth={ 1.75 } /> },
];

const GOAL_DESC = {
    amount:    __( 'Track progress toward a fundraising total.', 'gratora' ),
    donations: __( 'Track the number of completed donations.', 'gratora' ),
    donors:    __( 'Track the number of unique donors who give to this campaign.', 'gratora' ),
    none:      __( 'No progress bar or target.', 'gratora' ),
};

function slugify( s ) {
    return s.toLowerCase().trim().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
}

function openCoverFrame( onSelect ) {
    const media = window.wp?.media;
    if ( ! media ) return;
    const frame = media( {
        title:    __( 'Select or upload a cover image', 'gratora' ),
        button:   { text: __( 'Use this image', 'gratora' ) },
        multiple: false,
        library:  { type: 'image' },
    } );
    frame.on( 'select', () => {
        const a = frame.state().get( 'selection' ).first().toJSON();
        onSelect( { id: a.id, url: a.sizes?.medium?.url || a.url } );
    } );
    frame.open();
}

export default function CreateCampaignDrawer( { onClose } ) {
    const [ title, setTitle ]             = useState( '' );
    const [ slug, setSlug ]               = useState( '' );
    const [ slugEdited, setSlugEdited ]   = useState( false );
    const [ editingSlug, setEditingSlug ] = useState( false );

    const campaignTypes = window.gratora?.campaign_types || {};
    const typeNotices   = window.gratora?.campaign_type_notices || {};
    const [ campaignType, setCampaignType ] = useState( 'standard' );

    const [ goalType, setGoalType ] = useState( 'amount' );
    const [ amount, setAmount ]     = useState( '' );
    const [ count, setCount ]       = useState( '' );

    const [ description, setDescription ] = useState( '' );

    const [ funds, setFunds ]   = useState( [] );
    const [ fundId, setFundId ] = useState( null );

    const [ scheduleOn, setScheduleOn ] = useState( false );
    const [ startsAt, setStartsAt ]     = useState( null );
    const [ endsAt, setEndsAt ]         = useState( null );

    const [ pageTemplate, setPageTemplate ]   = useState( { id: 'standard', name: __( 'Standard campaign', 'gratora' ) } );
    const [ pickingLayout, setPickingLayout ] = useState( false );

    const [ cover, setCover ]           = useState( null );
    const [ publishNow, setPublishNow ] = useState( false );

    const [ submitting, setSubmitting ] = useState( false );
    const [ error, setError ]           = useState( null );
    const errorRef                      = useRef( null );

    // The submit button sits in the footer but the error renders at the top of a
    // long scrollable body; pull it into view (Notice already announces via role=alert).
    useEffect( () => {
        if ( error ) errorRef.current?.scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    }, [ error ] );

    useEffect( () => {
        let aborted = false;
        apiFetch( { path: '/gratora/v1/admin/campaigns/funds' } )
            .then( ( rows ) => {
                if ( aborted || ! Array.isArray( rows ) ) return;
                setFunds( rows.map( ( f ) => ( { value: f.id, label: f.name, hint: f.code } ) ) );
                const def = rows.find( ( f ) => f.is_default );
                if ( def ) setFundId( ( cur ) => ( cur == null ? def.id : cur ) );
            } )
            .catch( () => {} );
        return () => { aborted = true; };
    }, [] );

    const onTitle = ( v ) => {
        setTitle( v );
        if ( ! slugEdited ) setSlug( slugify( v ) );
    };

    const siteBase = ( window.gratora?.wp?.home_url || '' )
        .replace( /^https?:\/\//, '' )
        .replace( /\/+$/, '' );
    const slugBase = `${ siteBase }/campaigns`;

    const canCreate = title.trim() !== '' && ! submitting;

    const submit = async () => {
        if ( ! canCreate ) return;
        setSubmitting( true );
        setError( null );

        const payload = {
            title:               title.trim(),
            campaign_type:       campaignType,
            status:              publishNow ? 'published' : 'draft',
            description:         description.trim() === '' ? null : description.trim(),
            default_fund_id:     fundId || null,
            image_attachment_id: cover?.id || null,
            starts_at:           scheduleOn ? ( startsAt || null ) : null,
            ends_at:             scheduleOn ? ( endsAt || null ) : null,
        };
        if ( slug.trim() !== '' ) payload.slug = slug.trim();

        if ( goalType === 'amount' ) {
            payload.goal_type  = 'amount';
            payload.goal_cents = amount === '' || Number( amount ) <= 0 ? null : Math.round( Number( amount ) * 100 );
            payload.goal_count = null;
        } else if ( goalType === 'donations' || goalType === 'donors' ) {
            payload.goal_type  = goalType;
            payload.goal_count = count === '' || Number( count ) <= 0 ? null : Math.round( Number( count ) );
            payload.goal_cents = null;
        } else {
            payload.goal_type  = 'amount';
            payload.goal_cents = null;
            payload.goal_count = null;
        }

        try {
            payload.page_template = pageTemplate.id;

            const c = await apiFetch( {
                path:   '/gratora/v1/admin/campaigns',
                method: 'POST',
                data:   payload,
            } );
            window.location.href = detailHref( c.id, 'overview' );
        } catch ( err ) {
            setError( err?.message || __( 'Could not create campaign.', 'gratora' ) );
            setSubmitting( false );
        }
    };

    const foot = (
        <div className="gratora-cc__foot">
            { /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- Switch is self-labeled via its label prop; the wrapping label makes the whole row a click target */ }
            <label className="gratora-cc__publish">
                <Switch checked={ publishNow } onChange={ setPublishNow } label={ __( 'Publish now', 'gratora' ) } />
                <span className="gratora-cc__publish-txt">
                    <strong>{ publishNow ? __( 'Publish now', 'gratora' ) : __( 'Create as draft', 'gratora' ) }</strong>
                    <span>{ publishNow
                        ? __( 'Page goes live on create', 'gratora' )
                        : __( 'Toggle to publish now', 'gratora' ) }</span>
                </span>
            </label>
            <div className="gratora-cc__foot-actions">
                <Btn variant="ghost" onClick={ onClose } disabled={ submitting }>
                    { __( 'Cancel', 'gratora' ) }
                </Btn>
                <Btn variant="primary" onClick={ submit } isBusy={ submitting } disabled={ ! canCreate }>
                    <Plus size={ 14 } strokeWidth={ 1.75 } />
                    { __( 'Create', 'gratora' ) }
                </Btn>
            </div>
        </div>
    );

    return (
        <>
        <Dialog
            title={ __( 'New campaign', 'gratora' ) }
            onClose={ submitting ? undefined : onClose }
            foot={ foot }
        >
            <p className="gratora-dialog__help">
                { __( 'A few quick details, then you are live. You can change everything later.', 'gratora' ) }
            </p>
            <div className="gratora-cc">
            { error && (
                <div ref={ errorRef } className="gratora-cc__error">
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                </div>
            ) }

            <Field label={ __( 'Campaign title', 'gratora' ) }>
                <input
                    className="gratora-input"
                    type="text"
                    value={ title }
                    autoFocus
                    placeholder={ __( 'Enter campaign title', 'gratora' ) }
                    onChange={ ( e ) => onTitle( e.target.value ) }
                />
            </Field>

            { Object.keys( campaignTypes ).length > 1 && (
                <Field label={ __( 'Campaign type', 'gratora' ) }>
                    <Segmented
                        ariaLabel={ __( 'Campaign type', 'gratora' ) }
                        value={ campaignType }
                        onChange={ setCampaignType }
                        options={ Object.entries( campaignTypes ).map( ( [ value, label ] ) => ( { value, label } ) ) }
                    />
                    <div className="gratora-cc__goal-desc">
                        { campaignType === 'standard'
                            ? __( 'Collects donations directly on the campaign page.', 'gratora' )
                            : ( typeNotices[ campaignType ] || '' ) }
                    </div>
                </Field>
            ) }

            <Field label={ __( 'Campaign template', 'gratora' ) }>
                <button
                    type="button"
                    className="gratora-cc__layout"
                    onClick={ () => setPickingLayout( true ) }
                    disabled={ submitting }
                >
                    <span className="gratora-cc__layout-name">{ pageTemplate.name }</span>
                    <span className="gratora-cc__layout-change">
                        { __( 'Change', 'gratora' ) }
                    </span>
                </button>
                <div className="gratora-cc__goal-desc">
                    { __( 'The starting arrangement of the campaign page. Blocks, so you can rearrange it afterwards.', 'gratora' ) }
                </div>
            </Field>

            <Field label={ __( 'Goal', 'gratora' ) }>
                <Segmented
                    ariaLabel={ __( 'Goal type', 'gratora' ) }
                    value={ goalType }
                    onChange={ setGoalType }
                    options={ GOAL_OPTIONS }
                />
                { goalType === 'amount' && (
                    <div className="gratora-cc__goal-input">
                        <AmountInput
                            value={ amount }
                            onChange={ setAmount }
                            currency={ window.gratora?.default_currency || 'USD' }
                            placeholder="0"
                        />
                    </div>
                ) }
                { ( goalType === 'donations' || goalType === 'donors' ) && (
                    <div className="gratora-cc__goal-input">
                        <input
                            className="gratora-input"
                            type="number"
                            min="0"
                            value={ count }
                            placeholder={ __( 'Enter a number', 'gratora' ) }
                            onChange={ ( e ) => setCount( e.target.value ) }
                        />
                    </div>
                ) }
                <div className="gratora-cc__goal-desc">{ GOAL_DESC[ goalType ] }</div>
            </Field>

            <Field
                label={ __( 'Description', 'gratora' ) }
                help={ __( 'One or two sentences. Shows on campaign cards and the page hero.', 'gratora' ) }
            >
                <textarea
                    className="gratora-textarea"
                    rows={ 3 }
                    value={ description }
                    onChange={ ( e ) => setDescription( e.target.value ) }
                />
            </Field>

            <Field
                label={ __( 'Fund', 'gratora' ) }
                help={ __( 'Donations to this campaign are designated to this fund.', 'gratora' ) }
            >
                <SearchableSelect
                    value={ fundId }
                    onChange={ setFundId }
                    options={ funds }
                    placeholder={ __( 'Search funds', 'gratora' ) }
                />
            </Field>

            <Field
                label={ __( 'Schedule', 'gratora' ) }
                help={ __( 'By default the campaign is always on with no end date.', 'gratora' ) }
            >
                <ScheduleFields
                    enabled={ scheduleOn }
                    onToggle={ setScheduleOn }
                    startsAt={ startsAt }
                    onStartsAt={ setStartsAt }
                    endsAt={ endsAt }
                    onEndsAt={ setEndsAt }
                />
            </Field>

            <Field label={ __( 'Cover image', 'gratora' ) }>
                { cover ? (
                    <div className="gratora-cc__cover-sel">
                        <img className="gratora-cc__cover-thumb" src={ cover.url } alt="" />
                        <span className="gratora-cc__cover-name">{ __( 'Cover image selected', 'gratora' ) }</span>
                        <Btn variant="ghost" size="sm" onClick={ () => openCoverFrame( setCover ) }>
                            { __( 'Change', 'gratora' ) }
                        </Btn>
                        <Btn variant="ghost" size="sm" onClick={ () => setCover( null ) }>
                            { __( 'Remove', 'gratora' ) }
                        </Btn>
                    </div>
                ) : (
                    <button
                        type="button"
                        className="gratora-cc__cover-pick"
                        onClick={ () => openCoverFrame( setCover ) }
                    >
                        <span className="gratora-cc__cover-icon">
                            <ImagePlus size={ 18 } strokeWidth={ 1.75 } />
                        </span>
                        <span className="gratora-cc__cover-txt">
                            <span className="gratora-cc__cover-title">{ __( 'Select or upload an image', 'gratora' ) }</span>
                        </span>
                    </button>
                ) }
            </Field>

            <Field
                label={ __( 'Permalink', 'gratora' ) }
                help={ __( 'Auto-generated from the title.', 'gratora' ) }
            >
                { editingSlug ? (
                    <>
                        <div className="gratora-cc__slug-edit">
                            <span className="gratora-cc__slug-prefix">{ slugBase }/</span>
                            <input
                                className="gratora-cc__slug-input"
                                type="text"
                                value={ slug }
                                autoFocus
                                aria-label={ __( 'Campaign slug', 'gratora' ) }
                                onChange={ ( e ) => {
                                    setSlug( e.target.value.toLowerCase().replace( /[^a-z0-9-]+/g, '-' ) );
                                    setSlugEdited( true );
                                } }
                                onBlur={ () => setEditingSlug( false ) }
                            />
                        </div>
                        <div className="gratora-cc__slug-help">
                            { __( 'Lowercase letters, numbers and hyphens. Must be unique across campaigns.', 'gratora' ) }
                        </div>
                    </>
                ) : (
                    <div className="gratora-cc__slug">
                        <span className="gratora-cc__slug-lbl">{ __( 'URL', 'gratora' ) }</span>
                        <span className="gratora-cc__slug-url">
                            { slugBase }/<em>{ slug || __( 'campaign', 'gratora' ) }</em>
                        </span>
                        <button
                            type="button"
                            className="gratora-cc__slug-btn"
                            onClick={ () => setEditingSlug( true ) }
                        >
                            { __( 'Edit', 'gratora' ) }
                        </button>
                    </div>
                ) }
            </Field>
            </div>
            </Dialog>

            { pickingLayout && (
                <CampaignTemplatePicker
                    value={ pageTemplate.id }
                    campaignType={ campaignType }
                    onPick={ ( t ) => { setPageTemplate( t ); setPickingLayout( false ); } }
                    onClose={ () => setPickingLayout( false ) }
                />
            ) }
        </>
    );
}
