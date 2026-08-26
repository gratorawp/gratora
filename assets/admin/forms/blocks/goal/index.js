import { useBlockProps, InspectorControls } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { formatAmount } from '../../../_shared/format';
import Segmented from '../../../_shared/components/Segmented';
import { BlockIcons } from '../_shared/block-icons';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';

const NAME = 'giveflow/goal';

const EMPTY_GOAL = { type: 'none', amount_cents: 0, count: 0 };

function readContext() {
    return typeof window !== 'undefined' && window.giveflowFormEditor
        ? window.giveflowFormEditor
        : { formCampaignId: 0, formGoal: EMPTY_GOAL, campaigns: [] };
}

function useFormContext() {
    const [ ctx, setCtx ] = useState( readContext );
    useEffect( () => {
        let cancelled = false;
        const tick = () => {
            if ( cancelled ) return;
            const next = readContext();
            setCtx( ( prev ) =>
                prev.formCampaignId === next.formCampaignId &&
                prev.formGoal === next.formGoal &&
                prev.campaigns === next.campaigns
                    ? prev
                    : next
            );
        };
        const id = setInterval( tick, 400 );
        return () => { cancelled = true; clearInterval( id ); };
    }, [] );
    return ctx;
}

function daysLeft( endsAt ) {
    if ( ! endsAt ) return null;
    const end = new Date( endsAt );
    if ( isNaN( end.getTime() ) ) return null;
    const diff = Math.ceil( ( end.getTime() - Date.now() ) / 86400000 );
    return diff > 0 ? diff : 0;
}

function Edit( { attributes, setAttributes } ) {
    const {
        source       = 'campaign',
        showAmount   = true,
        showDonors   = true,
        showDeadline = false,
        condition    = DEFAULT_CONDITION,
    } = attributes;

    const ctx = useFormContext();
    const isFormSource = source === 'form';

    const campaign = ctx.campaigns.find( ( c ) => c.id === ( ctx.formCampaignId || 0 ) );
    const currency = campaign?.currency || 'USD';

    // Resolve the goal being displayed. Form source reads the form's own goal
    // (configured in Settings -> Goal); campaign source reads the parent
    // campaign's goal. The editor has no live per-form donation stats, so the
    // form-source preview shows the target with progress at zero and a note.
    const goal = isFormSource
        ? ( ctx.formGoal || EMPTY_GOAL )
        : {
            type:         campaign?.goal_type || 'amount',
            amount_cents: Number( campaign?.goal_cents || 0 ),
            count:        Number( campaign?.goal_count || 0 ),
        };

    const goalType = goal.type || 'none';
    const isAmount = goalType === 'amount';
    const target   = isAmount ? Number( goal.amount_cents || 0 ) : Number( goal.count || 0 );

    const current = isFormSource
        ? 0
        : ( isAmount
            ? Number( campaign?.raised_cents || 0 )
            : goalType === 'donations'
                ? Number( campaign?.donations_count || 0 )
                : Number( campaign?.donors_count || 0 ) );

    const donors  = isFormSource ? 0 : Number( campaign?.donors_count || 0 );
    const days    = isFormSource ? null : daysLeft( campaign?.ends_at );
    const percent = target > 0 ? Math.min( 100, Math.round( ( current / target ) * 100 ) ) : 0;

    const hasGoal = goalType !== 'none' && target > 0;

    const blockProps = useBlockProps( { className: 'giveflow-block-preview giveflow-block-preview--goal' } );

    const fmtValue = ( v ) =>
        isAmount ? formatAmount( v, currency, { compact: true } ) : String( v.toLocaleString() );

    const missingHint = isFormSource
        ? __( 'No goal set for this form. Set one in Settings, Goal.', 'giveflow-fundraising-campaigns' )
        : ( campaign
            ? __( 'The parent campaign has no goal set.', 'giveflow-fundraising-campaigns' )
            : __( 'Link this form to a campaign to show its goal.', 'giveflow-fundraising-campaigns' ) );

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Goal', 'giveflow-fundraising-campaigns' ) } initialOpen>
                    <Segmented
                        label={ __( 'Show', 'giveflow-fundraising-campaigns' ) }
                        value={ source }
                        onChange={ ( v ) => setAttributes( { source: v } ) }
                        options={ [
                            { value: 'campaign', label: __( 'Campaign goal', 'giveflow-fundraising-campaigns' ) },
                            { value: 'form',     label: __( 'Form goal', 'giveflow-fundraising-campaigns' ) },
                        ] }
                        help={ isFormSource
                            ? __( 'Tracks this form’s own donations against the form goal set in Settings, Goal.', 'giveflow-fundraising-campaigns' )
                            : __( 'Tracks the parent campaign total against the campaign goal.', 'giveflow-fundraising-campaigns' ) }
                    />

                    <ToggleControl
                        label={ __( 'Show amount raised vs goal', 'giveflow-fundraising-campaigns' ) }
                        checked={ showAmount }
                        onChange={ ( v ) => setAttributes( { showAmount: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Show donor count', 'giveflow-fundraising-campaigns' ) }
                        checked={ showDonors }
                        onChange={ ( v ) => setAttributes( { showDonors: v } ) }
                        __nextHasNoMarginBottom
                    />
                    { ! isFormSource && (
                        <ToggleControl
                            label={ __( 'Show deadline', 'giveflow-fundraising-campaigns' ) }
                            checked={ showDeadline }
                            onChange={ ( v ) => setAttributes( { showDeadline: v } ) }
                            __nextHasNoMarginBottom
                        />
                    ) }
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                { ! hasGoal ? (
                    <div className="giveflow-block-preview__hint">{ missingHint }</div>
                ) : (
                    <>
                        { showAmount && (
                            <div className="giveflow-block-preview__goal-top">
                                <strong>{ fmtValue( current ) }</strong>
                                <span>{ __( 'of', 'giveflow-fundraising-campaigns' ) } { fmtValue( target ) }</span>
                            </div>
                        ) }
                        <div className="giveflow-block-preview__goal-bar">
                            <div
                                className="giveflow-block-preview__goal-fill"
                                style={ { width: `${ percent }%` } }
                            />
                        </div>
                        <div className="giveflow-block-preview__goal-meta">
                            <span>{ percent }%</span>
                            { showDonors && ! isFormSource && (
                                <span>
                                    { donors === 1
                                        ? __( '1 donor', 'giveflow-fundraising-campaigns' )
                                        : `${ donors.toLocaleString() } ${ __( 'donors', 'giveflow-fundraising-campaigns' ) }` }
                                </span>
                            ) }
                            { showDeadline && ! isFormSource && days !== null && (
                                <span>
                                    { days === 0
                                        ? __( 'Last day', 'giveflow-fundraising-campaigns' )
                                        : days === 1
                                            ? __( '1 day left', 'giveflow-fundraising-campaigns' )
                                            : `${ days } ${ __( 'days left', 'giveflow-fundraising-campaigns' ) }` }
                                </span>
                            ) }
                        </div>
                        { isFormSource && (
                            <p className="giveflow-block-preview__note">
                                { __( 'Live progress appears on the published form.', 'giveflow-fundraising-campaigns' ) }
                            </p>
                        ) }
                    </>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Goal', 'giveflow-fundraising-campaigns' ),
        description: __( 'Progress bar for this form’s goal or its parent campaign’s goal.', 'giveflow-fundraising-campaigns' ),
        category:   'giveflow-extras',
        icon:       BlockIcons[ 'goal' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            source:       { type: 'string',  default: 'campaign' },
            showAmount:   { type: 'boolean', default: true },
            showDonors:   { type: 'boolean', default: true },
            showDeadline: { type: 'boolean', default: false },
            condition:    { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
