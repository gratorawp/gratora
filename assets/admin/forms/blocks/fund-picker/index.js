import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, TextControl, ToggleControl, SelectControl, Spinner, Notice, ExternalLink } from '@wordpress/components';
import { useState, useEffect } from '@wordpress/element';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/fund-picker';

const FUNDS_ADMIN_URL = 'admin.php?page=dono-funds';

function FundTiles( { funds, selectedId, allowEmpty, emptyLabel, emptyDescription, emptySelected } ) {
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
                { __( 'No active funds yet. Create funds under Donations → Funds; donations will use your organisation default until then.', 'dono' ) }
                {' '}
                <a href={ FUNDS_ADMIN_URL }>{ __( 'Manage funds', 'dono' ) }</a>
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
                        background:     emptySelected ? 'color-mix(in srgb, var(--dono-accent, #1e8a4e) 6%, transparent)' : '#fafbfc',
                        border:         `${ emptySelected ? '2px' : '1px' } solid ${ emptySelected ? 'var(--dono-accent, #1e8a4e)' : '#e5e7eb' }`,
                        borderRadius:   'var(--dono-radius-sm, 8px)',
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
                        { emptyLabel || __( 'No specific fund', 'dono' ) }
                    </span>
                    { emptyDescription && (
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
                            background:    isSelected ? 'color-mix(in srgb, var(--dono-accent, #1e8a4e) 6%, transparent)' : '#fafbfc',
                            border:        `2px solid ${ isSelected ? 'var(--dono-accent, #1e8a4e)' : '#e5e7eb' }`,
                            borderRadius:  'var(--dono-radius-sm, 8px)',
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
                                color:      isSelected ? 'var(--dono-accent, #1e8a4e)' : '#111827',
                            } }
                        >
                            { f.label }
                        </span>
                        { f.description !== '' && (
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
        fundIds          = [],
        condition        = DEFAULT_CONDITION,
    } = attributes;

    const [ funds, setFunds ] = useState( null );

    useEffect( () => {
        let cancelled = false;
        apiFetch( { path: '/dono/v1/admin/forms/funds' } )
            .then( ( res ) => { if ( ! cancelled ) setFunds( Array.isArray( res ) ? res : [] ); } )
            .catch( () => { if ( ! cancelled ) setFunds( [] ); } );
        return () => { cancelled = true; };
    }, [] );

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--fund' } );

    // When the admin restricts fundIds, only those + their parents flow into
    // the preview tiles. Empty array = "all active funds".
    const filteredList = ( funds || [] ).filter( ( f ) => {
        if ( ! fundIds.length ) return true;
        return fundIds.map( String ).includes( String( f.id ) );
    } );
    const list        = funds || [];
    const visible     = fundIds.length ? filteredList : list;
    const selectable  = visible.filter( ( f ) => f.selectable );
    const emptyChosen = allowEmpty && defaultId === '__none__';
    const selectedId  = emptyChosen ? '__none__' : ( defaultId || selectable[ 0 ]?.id || '' );

    const toggleFundId = ( id ) => {
        const sid = String( id );
        const current = fundIds.map( String );
        const next = current.includes( sid )
            ? current.filter( ( x ) => x !== sid )
            : [ ...current, sid ];
        setAttributes( { fundIds: next.map( ( x ) => parseInt( x, 10 ) ).filter( Boolean ) } );
    };

    const preselectChoices = [
        { value: '', label: __( 'Auto (form, campaign, then org default)', 'dono' ) },
        ...( allowEmpty ? [ { value: '__none__', label: __( 'No specific fund', 'dono' ) } ] : [] ),
        ...list.map( ( f ) => ( {
            value:    f.selectable ? String( f.id ) : `g:${ f.id }`,
            label:    f.depth ? `- ${ f.label }` : f.label,
            disabled: ! f.selectable,
        } ) ),
    ];

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Fund picker', 'dono' ) } initialOpen>
                    <TextControl
                        label={ __( 'Label', 'dono' ) }
                        value={ label }
                        onChange={ ( v ) => setAttributes( { label: v } ) }
                        placeholder={ __( 'Direct my donation to', 'dono' ) }
                        help={ __( 'This picker always shows your active funds. Fund names and descriptions are managed under Donations → Funds.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <p style={ { margin: '8px 0 0' } }>
                        <ExternalLink href={ FUNDS_ADMIN_URL }>
                            { __( 'Manage funds', 'dono' ) }
                        </ExternalLink>
                    </p>
                    <SelectControl
                        label={ __( 'Preselected fund', 'dono' ) }
                        value={ defaultId }
                        options={ preselectChoices }
                        onChange={ ( v ) => setAttributes( { defaultId: v } ) }
                        help={ __( 'Leave on the first fund to follow the form, campaign, then organisation default order.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Allow "no specific fund"', 'dono' ) }
                        checked={ allowEmpty }
                        onChange={ ( v ) => setAttributes( { allowEmpty: v } ) }
                        help={ __( 'Adds a tile letting donors skip choosing a fund.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                    { list.length > 0 && (
                        <div style={ { marginTop: 16 } }>
                            <strong style={ { fontSize: 11, textTransform: 'uppercase', letterSpacing: '.04em', color: '#6b7280' } }>
                                { __( 'Restrict to funds', 'dono' ) }
                            </strong>
                            <p style={ { margin: '4px 0 8px', fontSize: 12, color: '#6b7280' } }>
                                { __( 'Pick which funds this block offers. Leave all unchecked to show every active fund.', 'dono' ) }
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
                        <>
                            <TextControl
                                label={ __( 'No-specific-fund label', 'dono' ) }
                                value={ emptyLabel }
                                onChange={ ( v ) => setAttributes( { emptyLabel: v } ) }
                                placeholder={ __( 'No specific fund', 'dono' ) }
                                __nextHasNoMarginBottom
                            />
                            <TextControl
                                label={ __( 'No-specific-fund description', 'dono' ) }
                                value={ emptyDescription }
                                onChange={ ( v ) => setAttributes( { emptyDescription: v } ) }
                                help={ __( 'Optional. Shown under the label on that tile.', 'dono' ) }
                                __nextHasNoMarginBottom
                            />
                        </>
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
                    className="dono-block-preview__label"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Direct my donation to', 'dono' ) }
                    allowedFormats={ [] }
                />
                <FundTiles
                    funds={ funds === null ? null : visible }
                    selectedId={ selectedId }
                    allowEmpty={ allowEmpty }
                    emptyLabel={ emptyLabel }
                    emptyDescription={ emptyDescription }
                    emptySelected={ emptyChosen }
                />
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion:  3,
        title:       __( 'Fund picker', 'dono' ),
        description: __( 'Tile-style picker that lets donors choose which fund or designation their donation goes to.', 'dono' ),
        category:    'dono-extras',
        icon:        BlockIcons[ 'fund-picker' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            label:            { type: 'string',  default: '' },
            defaultId:        { type: 'string',  default: '' },
            allowEmpty:       { type: 'boolean', default: false },
            emptyLabel:       { type: 'string',  default: '' },
            emptyDescription: { type: 'string',  default: '' },
            fundIds:          { type: 'array',   default: [] },
            condition:        { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
