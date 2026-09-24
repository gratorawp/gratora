/**
 * Shared form-template picker (campaign Detail "Add new form" + form Editor auto-prompt).
 * Fetches /admin/forms/templates, groups by category; `creating` disables the cards
 * while the parent flow creates the chosen form.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

import Icon from './Icon';
import { thumbFor } from './formThumb';
import { resolveEffectiveTokens } from '../styling/StylePreview';

// Category values are stable grouping keys; translate only for display.
const CATEGORY_LABELS = {
    All:       __( 'All', 'gratora-donation-platform' ),
    Blank:     __( 'Blank', 'gratora-donation-platform' ),
    Starter:   __( 'Starter', 'gratora-donation-platform' ),
    Standard:  __( 'Standard', 'gratora-donation-platform' ),
    Recurring: __( 'Recurring', 'gratora-donation-platform' ),
    Wizard:    __( 'Wizard', 'gratora-donation-platform' ),
    Formal:    __( 'Formal', 'gratora-donation-platform' ),
    Other:     __( 'Other', 'gratora-donation-platform' ),
};

export default function FormTemplatePicker( { onPick, onClose, creating = false, intro } ) {
    const [ templates, setTemplates ] = useState( [] );
    const [ loading, setLoading ]     = useState( true );
    const [ category, setCategory ]   = useState( 'All' );
    const [ failed, setFailed ]       = useState( false );

    // This is the first screen a new form opens on, so an empty grid reads as
    // "this install has no templates" rather than "the request failed".
    const load = () => {
        setLoading( true );
        setFailed( false );
        apiFetch( { path: '/gratora/v1/admin/forms/templates' } )
            .then( ( list ) => setTemplates( Array.isArray( list ) ? list : [] ) )
            .catch( () => {
                setTemplates( [] );
                setFailed( true );
            } )
            .finally( () => setLoading( false ) );
    };

    useEffect( load, [] );

    const categories = useMemo( () => {
        const seen  = new Set();
        const order = [ 'Blank', 'Starter', 'Standard', 'Recurring', 'Wizard', 'Formal' ];
        for ( const t of templates ) seen.add( t.category || 'Other' );
        const found = order.filter( ( c ) => seen.has( c ) );
        const extra = [ ...seen ].filter( ( c ) => ! order.includes( c ) );
        return [ 'All', ...found, ...extra ];
    }, [ templates ] );

    const visible = category === 'All'
        ? templates
        : templates.filter( ( t ) => ( t.category || 'Other' ) === category );

    return (
        <Modal
            title={ __( 'Choose a starter template', 'gratora-donation-platform' ) }
            onRequestClose={ onClose }
            className="gratora-template-picker"
            size="large"
        >
            { failed ? (
                <div style={ { padding: 40, textAlign: 'center' } }>
                    <p>{ __( 'The starter templates could not be loaded.', 'gratora-donation-platform' ) }</p>
                    <button type="button" className="btn" onClick={ load }>
                        { __( 'Try again', 'gratora-donation-platform' ) }
                    </button>
                </div>
            ) : loading ? (
                <div style={ { padding: 40, textAlign: 'center' } }><Spinner /></div>
            ) : (
                <>
                    { intro && (
                        <p className="gratora-template-picker__intro">{ intro }</p>
                    ) }
                    <div className="gratora-template-picker__filters" role="tablist">
                        { categories.map( ( c ) => (
                            <button
                                key={ c }
                                type="button"
                                role="tab"
                                aria-selected={ category === c }
                                className={ `gratora-template-picker__filter${ category === c ? ' is-active' : '' }` }
                                onClick={ () => setCategory( c ) }
                            >
                                { CATEGORY_LABELS[ c ] || c }
                            </button>
                        ) ) }
                    </div>
                    <div className="gratora-template-picker__grid">
                        { visible.map( ( t ) => (
                            <button
                                key={ t.id }
                                type="button"
                                className="gratora-template-picker__card"
                                onClick={ () => onPick( t ) }
                                disabled={ creating }
                            >
                                <FormTemplateThumb template={ t } />
                                <div className="gratora-template-picker__meta">
                                    <strong>{ t.name }</strong>
                                    <span className="gratora-template-picker__desc">{ t.description }</span>
                                </div>
                            </button>
                        ) ) }
                    </div>
                </>
            ) }
        </Modal>
    );
}

function Band( { part } ) {
    switch ( part.kind ) {
        case 'title':
            return <span className={ `gratora-template-thumb__title${ part.small ? ' is-sm' : '' }` } />;

        case 'text':
        case 'fine-print':
            return (
                <span className={ `gratora-template-thumb__text${ part.kind === 'fine-print' ? ' is-fine' : '' }` }>
                    <i /><i />
                </span>
            );

        case 'rule':
            return <span className="gratora-template-thumb__rule" />;

        case 'tiles':
            return (
                <span
                    className={ `gratora-template-thumb__tiles${ part.labels ? ' has-labels' : '' }` }
                    style={ { '--thumb-tile-cols': part.cols } }
                >
                    { Array.from( { length: part.count }, ( _, i ) => (
                        <i key={ i } className={ i === part.active ? 'is-active' : '' }>
                            { part.labels && <b /> }
                        </i>
                    ) ) }
                </span>
            );

        case 'amount':
            return <span className="gratora-template-thumb__amount" />;

        case 'pills':
            return (
                <span className={ `gratora-template-thumb__pills${ part.joined ? ' is-joined' : '' }` }>
                    { Array.from( { length: part.count }, ( _, i ) => (
                        <i key={ i } className={ i === part.on ? 'is-on' : '' } />
                    ) ) }
                </span>
            );

        case 'goal':
            return (
                <span className="gratora-template-thumb__goal">
                    { part.figures > 0 && (
                        <span className="figs">
                            { Array.from( { length: part.figures }, ( _, i ) => <i key={ i } /> ) }
                        </span>
                    ) }
                    <span className="track">
                        <i />
                        { part.pip && <b className="pip" /> }
                    </span>
                </span>
            );

        case 'choices':
            return (
                <span className="gratora-template-thumb__choices">
                    { Array.from( { length: part.count }, ( _, i ) => (
                        <i key={ i } className={ i === part.on ? 'is-on' : '' }>
                            <b className="dot" />
                            <b className="lbl" />
                            { i === part.on && part.sub && <b className="sub" /> }
                        </i>
                    ) ) }
                </span>
            );

        case 'fields':
            return (
                <span className="gratora-template-thumb__fields">
                    { part.rows.map( ( row, i ) => ( row === 'pair' ? (
                        <i key={ i } className="pair"><b /><b /></i>
                    ) : (
                        <i key={ i } className={ row === 'select' ? 'is-select' : '' } />
                    ) ) ) }
                </span>
            );

        case 'textarea':
            return <span className="gratora-template-thumb__textarea"><i /><i /></span>;

        case 'check':
            return (
                <span className="gratora-template-thumb__check">
                    { Array.from( { length: part.count }, ( _, i ) => (
                        <i key={ i } className={ part.on ? 'is-on' : '' }><b className="box" /><b className="lbl" /></i>
                    ) ) }
                </span>
            );

        case 'ghost':
            return (
                <span className="gratora-template-thumb__ghost">
                    { Array.from( { length: part.count }, ( _, i ) => <i key={ i } /> ) }
                </span>
            );

        case 'panel':
            return (
                <span className="gratora-template-thumb__panel">
                    { part.children.map( ( child, i ) => <Band key={ i } part={ child } /> ) }
                </span>
            );

        case 'cols':
            return (
                <span className="gratora-template-thumb__cols" style={ { '--thumb-cols': part.cols } }>
                    { part.children.map( ( child, i ) => <Band key={ i } part={ child } /> ) }
                </span>
            );

        case 'checkout':
            return (
                <span className="gratora-template-thumb__checkout">
                    { part.chips > 0 && (
                        <span className="cards">
                            { Array.from( { length: part.chips }, ( _, i ) => <i key={ i } /> ) }
                        </span>
                    ) }
                    <b className="bar" />
                </span>
            );

        case 'advance':
            return <span className="gratora-template-thumb__checkout is-advance"><b className="bar" /></span>;

        default:
            return null;
    }
}

export function FormTemplateThumb( { template } ) {
    const settings = template.settings || {};
    const layout   = settings.layout  || 'inline';

    // A template is free to name a preset, and gratora.form.templates lets a
    // site add one that names a preset it later deleted.
    const tokens = resolveEffectiveTokens( {
        tokens:   {},
        presetId: String( settings.style?.preset_id || '' ),
        layer:    'campaign',
        styling:  window.gratora?.styling || {},
    } );
    const accent = tokens[ 'gratora-accent' ] || '#211d3f';
    const radius = tokens[ 'gratora-radius' ] || '8px';

    // The sheet stands for a form about four times its width, so the template's
    // own radius has to come down with it: 8px on an 11px tile is a capsule,
    // and every template would look equally round.
    const px     = parseFloat( radius );
    const scaled = Number.isFinite( px ) ? ( px > 0 ? Math.max( 1, Math.round( px / 4 ) ) : 0 ) : 2;

    const shape = thumbFor( template );

    if ( shape.parts.length === 1 && shape.parts[ 0 ].kind === 'empty' ) {
        return (
            <div className="gratora-template-thumb gratora-template-thumb--blank">
                <Icon name="plus" size={ 20 } aria-hidden="true" />
            </div>
        );
    }

    const sheet = (
        <div className="gratora-template-thumb__sheet" style={ { borderRadius: radius } }>
            { shape.chrome === 'bar' && (
                <span className="gratora-template-thumb__steps is-bar">
                    { Array.from( { length: shape.steps }, ( _, i ) => (
                        <i key={ i } className={ i === 0 ? 'is-active' : '' } />
                    ) ) }
                </span>
            ) }
            { shape.parts.map( ( part, i ) => <Band key={ i } part={ part } /> ) }
            { /* The runtime renders the dot strip below the form, not above it. */ }
            { shape.chrome === 'dots' && (
                <span className="gratora-template-thumb__steps">
                    { Array.from( { length: shape.steps }, ( _, i ) => (
                        <i key={ i } className={ i === 0 ? 'is-active' : '' } />
                    ) ) }
                </span>
            ) }
        </div>
    );

    return (
        <div
            className={ `gratora-template-thumb gratora-template-thumb--${ layout }` }
            style={ { '--thumb-accent': accent, '--thumb-radius': `${ scaled }px` } }
        >
            { layout === 'modal' ? (
                <div className="gratora-template-thumb__modal-backdrop">
                    { sheet }
                </div>
            ) : sheet }
        </div>
    );
}
