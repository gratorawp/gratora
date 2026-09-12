import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, SelectControl, Spinner, Notice, ExternalLink } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'gratora/fund-picker';

const FUNDS_ADMIN_URL = 'admin.php?page=gratora-funds';

function FundTiles( { funds, selectedId, allowEmpty, emptyLabel, emptyDescription, emptySelected, showDescriptions } ) {
    if ( funds === null ) {
        return (
            <div style={ { display: 'flex', justifyContent: 'center', padding: 16 } }>
                <Spinner />
            </div>
        );
    }

    if ( funds.length === 0 ) {
        return (
            <Notice status="warning" isDismissible={ false }>
                { __( 'No active funds yet. Create funds under Donations → Funds; donations will use your organization default until then.', 'gratora-donation-platform' ) }
                {' '}
                <a href={ FUNDS_ADMIN_URL }>{ __( 'Manage funds', 'gratora-donation-platform' ) }</a>
            </Notice>
        );
    }

    return (
        <div
            style={ {
                display:             'grid',
                gridTemplateColumns: 'repeat(auto-fit, minmax(140px, 1fr))',
                gap:                 8,
                marginTop:           8,
            } }
        >
            { allowEmpty && (
                <div
                    style={ {
                        padding:        '10px 12px',
                        background:     emptySelected ? 'color-mix(in srgb, var(--gratora-accent, #211d3f) 6%, transparent)' : '#fafbfc',
                        border:         `${ emptySelected ? '2px' : '1px' } solid ${ emptySelected ? 'var(--gratora-accent, #211d3f)' : '#e5e7eb' }`,
                        borderRadius:   'var(--gratora-radius-sm, 8px)',
                        display:        'flex',
                        flexDirection:  'column',
                        alignItems:     'center',
                        justifyContent: 'center',
                        gap:            4,
                        textAlign:      'center',
                        color:          '#6b7280',
                        minHeight:      56,
                    } }
                >
                    <span style={ { fontSize: 13, fontWeight: 600 } }>
                        { emptyLabel || __( 'No specific fund', 'gratora-donation-platform' ) }
                    </span>
                    { showDescriptions && emptyDescription && (
                        <span style={ { fontSize: 11, lineHeight: 1.3 } }>
                            { emptyDescription }
                        </span>
                    ) }
                </div>
            ) }
            { funds.map( ( f ) => {
                if ( ! f.selectable ) {
                    return (
                        <div
                            key={ `g-${ f.id }` }
                            style={ {
                                gridColumn:    '1 / -1',
                                fontSize:      11,
                                fontWeight:    600,
                                letterSpacing: '.04em',
                                textTransform: 'uppercase',
                                color:         '#6b7280',
                                marginTop:     4,
                            } }
                        >
                            { f.label }
                        </div>
                    );
                }

                const isSelected = String( f.id ) === String( selectedId );
                return (
                    <div
                        key={ f.id }
                        style={ {
                            padding:       '10px 12px',
                            marginLeft:    f.depth ? 14 : 0,
                            background:    isSelected ? 'color-mix(in srgb, var(--gratora-accent, #211d3f) 6%, transparent)' : '#fafbfc',
                            border:        `2px solid ${ isSelected ? 'var(--gratora-accent, #211d3f)' : '#e5e7eb' }`,
                            borderRadius:  'var(--gratora-radius-sm, 8px)',
                            display:       'flex',
                            flexDirection: 'column',
                            gap:           4,
                            minHeight:     56,
                        } }
                    >
                        <span
                            style={ {
                                fontSize:   13,
                                fontWeight: 600,
                                color:      isSelected ? 'var(--gratora-accent, #211d3f)' : '#111827',
                            } }
                        >
                            { f.label }
                        </span>
                        { showDescriptions && f.description !== '' && (
                            <span style={ { fontSize: 11, color: '#6b7280', lineHeight: 1.3 } }>
                                { f.description }
                            </span>
                        ) }
                    </div>
                );
            } ) }
        </div>
    );
}

function Edit( { attributes, setAttributes } ) {
    const {
        label            = '',
        defaultId        = '',
        allowEmpty       = false,
        emptyLabel       = '',
        emptyDescription = '',
        showDescriptions = true,
        fundIds          = [],
        condition        = DEFAULT_CONDITION,
    } = attributes;

    const [ funds, setFunds ] = useState( null );

    useEffect( () => {
        let cancelled = false;
        apiFetch( { path: '/gratora/v1/admin/forms/funds' } )
            .then( ( res ) => { if ( ! cancelled ) setFunds( Array.isArray( res ) ? res : [] ); } )
            .catch( () => { if ( ! cancelled ) setFunds( [] ); } );
        return () => { cancelled = true; };
    }, [] );

    const blockProps = useBlockProps( { className: 'gratora-block-preview gratora-block-preview--fund' } );

    // When the admin restricts fundIds, only those + their parents flow into
    // the preview tiles. Empty array = "all active funds".
    const filteredList = ( funds || [] ).filter( ( f ) => {
        if ( ! fundIds.length ) return true;
        return fundIds.map( String ).includes( String( f.id ) );
    } );
    const list        = funds || [];
    const visible     = fundIds.length ? filteredList : list;
    const selectable  = visible.filter( ( f ) => f.selectable );
    const selectableIds = selectable.map( ( f ) => String( f.id ) );
    const emptyChosen = allowEmpty && defaultId === '__none__';
    // A preselect pointing at a fund outside the offered set falls back to the
    // first offered fund, matching how the runtime resolves the default.
    const validDefault = defaultId && selectableIds.includes( String( defaultId ) ) ? defaultId : '';
    const selectedId  = emptyChosen ? '__none__' : ( validDefault || selectable[ 0 ]?.id || '' );

    const toggleFundId = ( id ) => {
        const sid = String( id );
        const current = fundIds.map( String );
        const next = current.includes( sid )
            ? current.filter( ( x ) => x !== sid )
            : [ ...current, sid ];
        const nextIds = next.map( ( x ) => parseInt( x, 10 ) ).filter( Boolean );
        const patch = { fundIds: nextIds };
        // Restricting to a set that no longer offers the preselected fund resets
        // it to Auto so we never persist a default outside the allowlist.
        if ( nextIds.length && defaultId && defaultId !== '__none__'
            && ! nextIds.map( String ).includes( String( defaultId ) ) ) {
            patch.defaultId = '';
        }
        setAttributes( patch );
    };

    // Offer only funds this block actually shows; a restricted set must not let
    // the admin preselect a fund the donor can't pick.
    const preselectChoices = [
        { value: '', label: __( 'Auto (form, campaign, then org default)', 'gratora-donation-platform' ) },
        ...( allowEmpty ? [ { value: '__none__', label: __( 'No specific fund', 'gratora-donation-platform' ) } ] : [] ),
        ...visible.map( ( f ) => ( {
            value:    f.selectable ? String( f.id ) : `g:${ f.id }`,
            label:    f.depth ? `- ${ f.label }` : f.label,
            disabled: ! f.selectable,
        } ) ),
    ];

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Fund picker', 'gratora-donation-platform' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'gratora-donation-platform' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Direct my donation to', 'gratora-donation-platform' ) }
                        help={ __( 'This picker always shows your active funds. Fund names and descriptions are managed under Donations → Funds.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <p style={ { margin: '8px 0 20px' } }>
                        <ExternalLink href={ FUNDS_ADMIN_URL }>
                            { __( 'Manage funds', 'gratora-donation-platform' ) }
                        </ExternalLink>
                    </p>
                    <SelectControl
                        label={ __( 'Preselected fund', 'gratora-donation-platform' ) }
                        value={ defaultId }
                        options={ preselectChoices }
                        onChange={ ( v ) => setAttributes( { defaultId: v } ) }
                        help={ __( 'Leave on the first fund to follow the form, campaign, then organization default order.', 'gratora-donation-platform' ) }
                        __nextHasNoMarginBottom
                    />
                    <div style={ { display: 'flex', flexDirection: 'column', gap: 12, marginTop: 16 } }>
                        <ToggleControl
                            label={ __( 'Show fund descriptions', 'gratora-donation-platform' ) }
                            checked={ showDescriptions }
                            onChange={ ( v ) => setAttributes( { showDescriptions: v } ) }
                            help={ __( 'Descriptions come from Donations → Funds. Turn this off to show fund names only.', 'gratora-donation-platform' ) }
                            __nextHasNoMarginBottom
                        />
                        <ToggleControl
                            label={ __( 'Allow "no specific fund"', 'gratora-donation-platform' ) }
                            checked={ allowEmpty }
                            onChange={ ( v ) => setAttributes( { allowEmpty: v } ) }
                            help={ __( 'Adds a tile letting donors skip choosing a fund.', 'gratora-donation-platform' ) }
                            __nextHasNoMarginBottom
                        />
                    </div>
                    { list.length > 0 && (
                        <div style={ { marginTop: 16, marginBottom: 24 } }>
                            <strong style={ { fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: '#6b7280' } }>
                                { __( 'Restrict to funds', 'gratora-donation-platform' ) }
                            </strong>
                            <p style={ { margin: '4px 0 8px', fontSize: 12, color: '#6b7280' } }>
                                { __( 'Pick which funds this block offers. Leave all unchecked to show every active fund.', 'gratora-donation-platform' ) }
                            </p>
                            <div style={ { display: 'flex', flexDirection: 'column', gap: 4 } }>
                                { list.filter( ( f ) => f.selectable ).map( ( f ) => (
                                    <label key={ f.id } style={ { display: 'flex', gap: 6, alignItems: 'center', fontSize: 12 } }>
                                        <input
                                            type="checkbox"
                                            checked={ fundIds.map( String ).includes( String( f.id ) ) }
                                            onChange={ () => toggleFundId( f.id ) }
                                        />
                                        <span>{ f.label }</span>
                                    </label>
                                ) ) }
                            </div>
                        </div>
                    ) }
                    { allowEmpty && (
                        <div style={ { display: 'flex', flexDirection: 'column', gap: 16 } }>
                            <TextControl
                                label={ __( 'No-specific-fund label', 'gratora-donation-platform' ) }
                                value={ emptyLabel }
                                onChange={ ( v ) => setAttributes( { emptyLabel: v } ) }
                                placeholder={ __( 'No specific fund', 'gratora-donation-platform' ) }
                                __nextHasNoMarginBottom
                            />
                            { showDescriptions && (
                                <TextControl
                                    label={ __( 'No-specific-fund description', 'gratora-donation-platform' ) }
                                    value={ emptyDescription }
                                    onChange={ ( v ) => setAttributes( { emptyDescription: v } ) }
                                    help={ __( 'Optional. Shown under the label on that tile.', 'gratora-donation-platform' ) }
                                    __nextHasNoMarginBottom
                                />
                            ) }
                        </div>
                    ) }
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( cnd ) => setAttributes( { condition: cnd } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="span"
                    className="gratora-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Direct my donation to', 'gratora-donation-platform' ) }
                    allowedFormats={ [] }
                />
                <FundTiles
                    funds={ funds === null ? null : visible }
                    selectedId={ selectedId }
                    allowEmpty={ allowEmpty }
                    emptyLabel={ emptyLabel }
                    emptyDescription={ emptyDescription }
                    emptySelected={ emptyChosen }
                    showDescriptions={ showDescriptions }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Fund picker', 'gratora-donation-platform' ),
        description: __( 'Tile-style picker that lets donors choose which fund or designation their donation goes to.', 'gratora-donation-platform' ),
        category:    'gratora-extras',
        icon:        BlockIcons[ 'fund-picker' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:            { type: 'string',  default: '' },
            defaultId:        { type: 'string',  default: '' },
            allowEmpty:       { type: 'boolean', default: false },
            emptyLabel:       { type: 'string',  default: '' },
            emptyDescription: { type: 'string',  default: '' },
            showDescriptions: { type: 'boolean', default: true },
            fundIds:          { type: 'array',   default: [] },
            condition:        { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
