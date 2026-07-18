import { useBlockProps, InspectorControls, RichText } from '@wordpress/block-editor';
import { PanelBody, ToggleControl } from '@wordpress/components';
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { ConditionPanel, DEFAULT_CONDITION } from '../_shared/condition';
import { BlockIcons } from '../_shared/block-icons';

const NAME = 'dono/tribute';

const DEFAULT_TYPES = [
    { id: 'honor',    label: 'In honor of' },
    { id: 'memorial', label: 'In memory of' },
];

function slugify( s ) {
    return String( s || '' )
        .toLowerCase()
        .replace( /[^a-z0-9]+/g, '-' )
        .replace( /^-+|-+$/g, '' );
}

function uniqueId( base, existing ) {
    const root  = slugify( base ) || 'type';
    const taken = new Set( ( existing || [] ).map( ( t ) => t.id ) );
    if ( ! taken.has( root ) ) return root;
    let i = 2;
    while ( taken.has( `${ root }-${ i }` ) ) i++;
    return `${ root }-${ i }`;
}

function Edit( { attributes, setAttributes } ) {
    const {
        types       = DEFAULT_TYPES,
        allowNotify = true,
        allowAnnual = true,
        label       = __( 'Make this donation in honor or memory of someone', 'dono' ),
        condition   = DEFAULT_CONDITION,
    } = attributes;

    const blockProps = useBlockProps( { className: 'dono-block-preview dono-block-preview--tribute' } );

    const [ dragIndex, setDragIndex ] = useState( null );

    const setTypeLabel = ( index, value ) => {
        const next = types.map( ( t, i ) => ( i === index ? { ...t, label: value } : t ) );
        setAttributes( { types: next } );
    };

    const addType = () => {
        setAttributes( { types: [ ...types, { id: uniqueId( 'type', types ), label: '' } ] } );
    };

    const removeType = ( index ) => {
        if ( types.length <= 1 ) return;
        setAttributes( { types: types.filter( ( _, i ) => i !== index ) } );
    };

    const reorder = ( from, to ) => {
        if ( from === null || from === to ) return;
        if ( from < 0 || to < 0 || from >= types.length || to >= types.length ) return;
        const next = types.slice();
        const [ moved ] = next.splice( from, 1 );
        next.splice( to, 0, moved );
        setAttributes( { types: next } );
    };

    return (
        <>
            <InspectorControls>
                <PanelBody title={ __( 'Tribute', 'dono' ) } initialOpen>
                    <div className="dono-amounts-head">
                        <span className="dono-amounts-head__label">{ __( 'Options', 'dono' ) }</span>
                        <button
                            type="button"
                            className="dono-amounts-add"
                            onClick={ addType }
                            aria-label={ __( 'Add type', 'dono' ) }
                            title={ __( 'Add type', 'dono' ) }
                        >
                            +
                        </button>
                    </div>

                    { types.map( ( t, i ) => (
                        <div
                            key={ t.id || i }
                            className={ `dono-preset-row${ dragIndex === i ? ' is-dragging' : '' }` }
                            onDragOver={ ( e ) => e.preventDefault() }
                            onDrop={ () => { reorder( dragIndex, i ); setDragIndex( null ); } }
                        >
                            <span
                                className="dono-preset-row__drag"
                                draggable
                                tabIndex={ 0 }
                                onDragStart={ ( e ) => {
                                    e.dataTransfer.effectAllowed = 'move';
                                    try { e.dataTransfer.setData( 'text/plain', String( i ) ); } catch ( _e ) {}
                                    setDragIndex( i );
                                } }
                                onDragEnd={ () => setDragIndex( null ) }
                                onKeyDown={ ( e ) => {
                                    if ( e.key === 'ArrowUp' )   { e.preventDefault(); reorder( i, i - 1 ); }
                                    if ( e.key === 'ArrowDown' ) { e.preventDefault(); reorder( i, i + 1 ); }
                                } }
                                role="button"
                                aria-label={ __( 'Drag to reorder, or use the arrow keys', 'dono' ) }
                                title={ __( 'Drag to reorder', 'dono' ) }
                            >
                                ⠿
                            </span>
                            <span className="dono-preset-row__amt">
                                <input
                                    type="text"
                                    className="dono-tribute-type-input"
                                    value={ t.label }
                                    placeholder={ __( 'Label', 'dono' ) }
                                    onChange={ ( e ) => setTypeLabel( i, e.target.value ) }
                                />
                            </span>
                            <button
                                type="button"
                                className="dono-preset-row__remove"
                                onClick={ () => removeType( i ) }
                                disabled={ types.length <= 1 }
                                aria-label={ __( 'Remove type', 'dono' ) }
                                title={ __( 'Remove type', 'dono' ) }
                            >
                                −
                            </button>
                        </div>
                    ) ) }
                    <p style={ { margin: '6px 0 0', fontSize: 11, color: '#6b7280' } }>
                        { __( 'Each option becomes a radio choice for the donor.', 'dono' ) }
                    </p>
                </PanelBody>
                <PanelBody title={ __( 'Extras', 'dono' ) } initialOpen={ false }>
                    <ToggleControl
                        label={ __( 'Allow notifying recipient', 'dono' ) }
                        checked={ allowNotify }
                        onChange={ ( v ) => setAttributes( { allowNotify: v } ) }
                        __nextHasNoMarginBottom
                    />
                    <ToggleControl
                        label={ __( 'Allow annual reminder', 'dono' ) }
                        checked={ allowAnnual }
                        onChange={ ( v ) => setAttributes( { allowAnnual: v } ) }
                        help={ __( 'Donor can opt to have this donation repeat annually on the same date.', 'dono' ) }
                        __nextHasNoMarginBottom
                    />
                </PanelBody>
                <ConditionPanel
                    condition={ condition }
                    onChange={ ( c ) => setAttributes( { condition: c } ) }
                />
            </InspectorControls>
            <div { ...blockProps }>
                <RichText
                    tagName="div"
                    value={ label }
                    onChange={ ( v ) => setAttributes( { label: v } ) }
                    placeholder={ __( 'Make this donation in honor or memory of someone', 'dono' ) }
                    allowedFormats={ [] }
                    className="dono-block-preview__title"
                />
                <ul
                    className="dono-tribute-preview-radios"
                    style={ {
                        listStyle: 'none',
                        margin: 0,
                        padding: 0,
                        display: 'flex',
                        flexWrap: 'wrap',
                        gap: '8px 18px',
                        marginBottom: 10,
                        fontSize: 13,
                    } }
                >
                    { types.map( ( t, i ) => (
                        <li key={ t.id || i } style={ { display: 'flex', alignItems: 'center', gap: 6, margin: 0, whiteSpace: 'nowrap', flex: '0 0 auto' } }>
                            <input type="radio" name="dono-tribute-preview" disabled style={ { margin: 0, flex: '0 0 auto' } } />
                            <span style={ { whiteSpace: 'nowrap', flex: '0 0 auto' } }>{ t.label || __( 'Untitled', 'dono' ) }</span>
                        </li>
                    ) ) }
                </ul>
                <div className="dono-block-preview__input">{ __( 'Name of the person', 'dono' ) }</div>
                { allowNotify && (
                    <>
                        <div className="dono-block-preview__input">{ __( 'Notify someone (optional email)', 'dono' ) }</div>
                        <div className="dono-block-preview__input">{ __( 'Personal message (optional)', 'dono' ) }</div>
                    </>
                ) }
                { allowAnnual && (
                    <div className="dono-block-preview__hint">
                        { __( 'Remember this person every year with a matching donation', 'dono' ) }
                    </div>
                ) }
            </div>
        </>
    );
}

export default function register( api ) {
    api.register( NAME, {
        apiVersion: 3,
        title:      __( 'Tribute', 'dono' ),
        description: __( 'Donate as a tribute, with one or more types you define and an optional annual reminder.', 'dono' ),
        category:   'dono-extras',
        icon:       BlockIcons[ 'tribute' ],
        supports: { html: false, anchor: false, inserter: true, multiple: false },
        attributes: {
            types:       { type: 'array',   default: DEFAULT_TYPES },
            allowNotify: { type: 'boolean', default: true },
            allowAnnual: { type: 'boolean', default: true },
            label:       { type: 'string',  default: '' },
            condition:   { type: 'object',  default: DEFAULT_CONDITION },
        },
        edit: Edit,
        save: () => null,
    } );
}
