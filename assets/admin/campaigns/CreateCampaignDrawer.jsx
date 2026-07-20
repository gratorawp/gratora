import { useState, useEffect, useRef } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import { detailHref } from '../_shared/format';
import Drawer from '../_shared/components/Drawer';
import Notice from '../_shared/components/Notice';
import Field from '../_shared/components/Field';
import Segmented from '../_shared/components/Segmented';
import AmountInput from '../_shared/components/AmountInput';
import SearchableSelect from '../_shared/components/SearchableSelect';
import DateField from '../_shared/components/DateField';
import { Switch } from '../_shared/components/Switch';
import Btn from '../_shared/components/Btn';

import { DollarSign, HandHeart, Users, Ban, ImagePlus, CircleCheck, Plus } from 'lucide-react';

const GOAL_OPTIONS = [
    { value: 'amount',    label: __( 'Amount', 'dono' ),    icon: <DollarSign strokeWidth={ 1.75 } /> },
    { value: 'donations', label: __( 'Donations', 'dono' ), icon: <HandHeart strokeWidth={ 1.75 } /> },
    { value: 'donors',    label: __( 'Donors', 'dono' ),    icon: <Users strokeWidth={ 1.75 } /> },
    { value: 'none',      label: __( 'No goal', 'dono' ),   icon: <Ban strokeWidth={ 1.75 } /> },
];

const GOAL_DESC = {
    amount:    __( 'Track progress toward a fundraising total.', 'dono' ),
    donations: __( 'Track the number of completed donations.', 'dono' ),
    donors:    __( 'Track the number of unique donors who give to this campaign.', 'dono' ),
    none:      __( 'No progress bar or target.', 'dono' ),
};

function slugify( s ) {
    return s.toLowerCase().trim().replace( /[^a-z0-9]+/g, '-' ).replace( /^-+|-+$/g, '' );
}

function openCoverFrame( onSelect ) {
    const media = window.wp?.media;
    if ( ! media ) return;
    const frame = media( {
        title:    __( 'Select or upload a cover image', 'dono' ),
        button:   { text: __( 'Use this image', 'dono' ) },
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

    const campaignTypes = window.dono?.campaign_types || {};
    const typeNotices   = window.dono?.campaign_type_notices || {};
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
        apiFetch( { path: '/dono/v1/admin/campaigns/funds' } )
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

    const siteBase = ( window.dono?.wp?.home_url || '' )
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
            const c = await apiFetch( {
                path:   '/dono/v1/admin/campaigns',
                method: 'POST',
                data:   payload,
            } );
            window.location.href = detailHref( c.id, 'overview' );
        } catch ( err ) {
            setError( err?.message || __( 'Could not create campaign.', 'dono' ) );
            setSubmitting( false );
        }
    };

    const foot = (
        <>
            <div className="dono-cc__reassure">
                <CircleCheck size={ 14 } strokeWidth={ 1.75 } />
                <span>{ __( 'Creating builds a landing page for you. You can change everything later.', 'dono' ) }</span>
            </div>
            <div className="dono-cc__foot">
                { /* eslint-disable-next-line jsx-a11y/label-has-associated-control -- Switch is self-labeled via its label prop; the wrapping label makes the whole row a click target */ }
                <label className="dono-cc__publish">
                    <Switch checked={ publishNow } onChange={ setPublishNow } label={ __( 'Publish now', 'dono' ) } />
                    <span className="dono-cc__publish-txt">
                        <strong>{ publishNow ? __( 'Publish now', 'dono' ) : __( 'Create as draft', 'dono' ) }</strong>
                        <span>{ publishNow
                            ? __( 'Page goes live on create', 'dono' )
                            : __( 'Toggle to publish now', 'dono' ) }</span>
                    </span>
                </label>
                <div className="dono-cc__foot-actions">
                    <Btn variant="ghost" onClick={ onClose } disabled={ submitting }>
                        { __( 'Cancel', 'dono' ) }
                    </Btn>
                    <Btn variant="primary" onClick={ submit } isBusy={ submitting } disabled={ ! canCreate }>
                        <Plus size={ 14 } strokeWidth={ 1.75 } />
                        { __( 'Create', 'dono' ) }
                    </Btn>
                </div>
            </div>
        </>
    );

    return (
        <Drawer
            title={ __( 'New campaign', 'dono' ) }
            sub={ __( 'A few quick details, then you are live. You can change everything later.', 'dono' ) }
            onClose={ submitting ? undefined : onClose }
            foot={ foot }
        >
            <div className="dono-cc">
            { error && (
                <div ref={ errorRef } className="dono-cc__error">
                    <Notice status="error" isDismissible={ false }>{ error }</Notice>
                </div>
            ) }

            <Field label={ __( 'Campaign title', 'dono' ) }>
                <input
                    className="dono-input"
                    type="text"
                    value={ title }
                    autoFocus
                    placeholder={ __( 'Enter campaign title', 'dono' ) }
                    onChange={ ( e ) => onTitle( e.target.value ) }
                />
            </Field>

            { Object.keys( campaignTypes ).length > 1 && (
                <Field label={ __( 'Campaign type', 'dono' ) }>
                    <Segmented
                        ariaLabel={ __( 'Campaign type', 'dono' ) }
                        value={ campaignType }
                        onChange={ setCampaignType }
                        options={ Object.entries( campaignTypes ).map( ( [ value, label ] ) => ( { value, label } ) ) }
                    />
                    <div className="dono-cc__goal-desc">
                        { campaignType === 'standard'
                            ? __( 'Collects donations directly on the campaign page.', 'dono' )
                            : ( typeNotices[ campaignType ] || '' ) }
                    </div>
                </Field>
            ) }

            <Field label={ __( 'Goal', 'dono' ) }>
                <Segmented
                    ariaLabel={ __( 'Goal type', 'dono' ) }
                    value={ goalType }
                    onChange={ setGoalType }
                    options={ GOAL_OPTIONS }
                />
                { goalType === 'amount' && (
                    <div className="dono-cc__goal-input">
                        <AmountInput
                            value={ amount }
                            onChange={ setAmount }
                            currency={ window.dono?.default_currency || 'USD' }
                            placeholder="0"
                        />
                    </div>
                ) }
                { ( goalType === 'donations' || goalType === 'donors' ) && (
                    <div className="dono-cc__goal-input">
                        <input
                            className="dono-input"
                            type="number"
                            min="0"
                            value={ count }
                            placeholder={ __( 'Enter a number', 'dono' ) }
                            onChange={ ( e ) => setCount( e.target.value ) }
                        />
                    </div>
                ) }
                <div className="dono-cc__goal-desc">{ GOAL_DESC[ goalType ] }</div>
            </Field>

            <Field
                label={ __( 'Description', 'dono' ) }
                help={ __( 'One or two sentences. Shows on campaign cards and the page hero.', 'dono' ) }
            >
                <textarea
                    className="dono-textarea"
                    rows={ 3 }
                    value={ description }
                    onChange={ ( e ) => setDescription( e.target.value ) }
                />
            </Field>

            <Field
                label={ __( 'Fund', 'dono' ) }
                help={ __( 'Donations to this campaign are designated to this fund.', 'dono' ) }
            >
                <SearchableSelect
                    value={ fundId }
                    onChange={ setFundId }
                    options={ funds }
                    placeholder={ __( 'Search funds', 'dono' ) }
                />
            </Field>

            <Field
                label={ __( 'Schedule', 'dono' ) }
                help={ __( 'By default the campaign is always on with no end date.', 'dono' ) }
            >
                <div className="dono-cc__toggle-row">
                    <div className="dono-cc__toggle-txt">
                        <div className="dono-cc__toggle-title">{ __( 'Set a schedule', 'dono' ) }</div>
                        <div className="dono-cc__toggle-sub">{ __( 'Add an optional start and end date', 'dono' ) }</div>
                    </div>
                    <Switch checked={ scheduleOn } onChange={ setScheduleOn } label={ __( 'Set a schedule', 'dono' ) } />
                </div>
                { scheduleOn && (
                    <div className="dono-cc__dates">
                        <div>
                            <span className="dono-cc__date-lbl">{ __( 'Start date', 'dono' ) }</span>
                            <DateField
                                value={ startsAt }
                                onChange={ setStartsAt }
                                placeholder={ __( 'Starts immediately', 'dono' ) }
                                ariaLabel={ __( 'Start date', 'dono' ) }
                            />
                        </div>
                        <div>
                            <span className="dono-cc__date-lbl">{ __( 'End date', 'dono' ) }</span>
                            <DateField
                                value={ endsAt }
                                onChange={ setEndsAt }
                                placeholder={ __( 'No end date', 'dono' ) }
                                ariaLabel={ __( 'End date', 'dono' ) }
                            />
                        </div>
                    </div>
                ) }
            </Field>

            <Field label={ __( 'Cover image', 'dono' ) }>
                { cover ? (
                    <div className="dono-cc__cover-sel">
                        <img className="dono-cc__cover-thumb" src={ cover.url } alt="" />
                        <span className="dono-cc__cover-name">{ __( 'Cover image selected', 'dono' ) }</span>
                        <Btn variant="ghost" size="sm" onClick={ () => openCoverFrame( setCover ) }>
                            { __( 'Change', 'dono' ) }
                        </Btn>
                        <Btn variant="ghost" size="sm" onClick={ () => setCover( null ) }>
                            { __( 'Remove', 'dono' ) }
                        </Btn>
                    </div>
                ) : (
                    <button
                        type="button"
                        className="dono-cc__cover-pick"
                        onClick={ () => openCoverFrame( setCover ) }
                    >
                        <span className="dono-cc__cover-icon">
                            <ImagePlus size={ 18 } strokeWidth={ 1.75 } />
                        </span>
                        <span className="dono-cc__cover-txt">
                            <span className="dono-cc__cover-title">{ __( 'Select or upload an image', 'dono' ) }</span>
                        </span>
                    </button>
                ) }
            </Field>

            <Field
                label={ __( 'Permalink', 'dono' ) }
                help={ __( 'Auto-generated from the title.', 'dono' ) }
            >
                { editingSlug ? (
                    <>
                        <div className="dono-cc__slug-edit">
                            <span className="dono-cc__slug-prefix">{ slugBase }/</span>
                            <input
                                className="dono-cc__slug-input"
                                type="text"
                                value={ slug }
                                autoFocus
                                aria-label={ __( 'Campaign slug', 'dono' ) }
                                onChange={ ( e ) => {
                                    setSlug( e.target.value.toLowerCase().replace( /[^a-z0-9-]+/g, '-' ) );
                                    setSlugEdited( true );
                                } }
                                onBlur={ () => setEditingSlug( false ) }
                            />
                        </div>
                        <div className="dono-cc__slug-help">
                            { __( 'Lowercase letters, numbers and hyphens. Must be unique across campaigns.', 'dono' ) }
                        </div>
                    </>
                ) : (
                    <div className="dono-cc__slug">
                        <span className="dono-cc__slug-lbl">{ __( 'URL', 'dono' ) }</span>
                        <span className="dono-cc__slug-url">
                            { slugBase }/<em>{ slug || __( 'campaign', 'dono' ) }</em>
                        </span>
                        <button
                            type="button"
                            className="dono-cc__slug-btn"
                            onClick={ () => setEditingSlug( true ) }
                        >
                            { __( 'Edit', 'dono' ) }
                        </button>
                    </div>
                ) }
            </Field>
            </div>
        </Drawer>
    );
}
