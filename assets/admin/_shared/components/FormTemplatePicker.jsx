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

// Category values are stable grouping keys; translate only for display.
const CATEGORY_LABELS = {
    All:       __( 'All', 'dono' ),
    Blank:     __( 'Blank', 'dono' ),
    Starter:   __( 'Starter', 'dono' ),
    Standard:  __( 'Standard', 'dono' ),
    Recurring: __( 'Recurring', 'dono' ),
    Tribute:   __( 'Tribute', 'dono' ),
    Wizard:    __( 'Wizard', 'dono' ),
    Formal:    __( 'Formal', 'dono' ),
    Other:     __( 'Other', 'dono' ),
};

export default function FormTemplatePicker( { onPick, onClose, creating = false, intro } ) {
    const [ templates, setTemplates ] = useState( [] );
    const [ loading, setLoading ]     = useState( true );
    const [ category, setCategory ]   = useState( 'All' );

    useEffect( () => {
        apiFetch( { path: '/dono/v1/admin/forms/templates' } )
            .then( ( list ) => setTemplates( Array.isArray( list ) ? list : [] ) )
            .catch( () => setTemplates( [] ) )
            .finally( () => setLoading( false ) );
    }, [] );

    const categories = useMemo( () => {
        const seen  = new Set();
        const order = [ 'Blank', 'Starter', 'Standard', 'Recurring', 'Tribute', 'Wizard', 'Formal' ];
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
            title={ __( 'Choose a starter template', 'dono' ) }
            onRequestClose={ onClose }
            className="dono-form-template-picker"
            size="large"
        >
            { loading ? (
                <div style={ { padding: 40, textAlign: 'center' } }><Spinner /></div>
            ) : (
                <>
                    { intro && (
                        <p className="dono-form-template-picker__intro">{ intro }</p>
                    ) }
                    <div className="dono-form-template-picker__filters" role="tablist">
                        { categories.map( ( c ) => (
                            <button
                                key={ c }
                                type="button"
                                role="tab"
                                aria-selected={ category === c }
                                className={ `dono-form-template-picker__filter${ category === c ? ' is-active' : '' }` }
                                onClick={ () => setCategory( c ) }
                            >
                                { CATEGORY_LABELS[ c ] || c }
                            </button>
                        ) ) }
                    </div>
                    <div className="dono-form-template-picker__grid">
                        { visible.map( ( t ) => (
                            <button
                                key={ t.id }
                                type="button"
                                className="dono-form-template-picker__card"
                                onClick={ () => onPick( t ) }
                                disabled={ creating }
                            >
                                <FormTemplateThumb template={ t } />
                                <div className="dono-form-template-picker__meta">
                                    <strong>{ t.name }</strong>
                                    <span className="dono-form-template-picker__desc">{ t.description }</span>
                                </div>
                            </button>
                        ) ) }
                    </div>
                </>
            ) }
        </Modal>
    );
}

function FormTemplateThumb( { template } ) {
    const settings = template.settings || {};
    const layout   = settings.layout  || 'inline';

    const presets   = Array.isArray( window.dono?.styling?.presets ) ? window.dono.styling.presets : [];
    const defaults  = window.dono?.styling?.defaults || {};
    const defaultId = String( window.dono?.styling?.default_id || '' );
    const templatePresetId = String( settings.style?.preset_id || '' );
    const chosenPreset = presets.find( ( p ) => p.id === ( templatePresetId || defaultId ) );
    const tokens = { ...defaults, ...( chosenPreset?.tokens || {} ) };
    const accent = ( settings.theme?.accent ) || tokens[ 'dono-accent' ] || '#1e8a4e';
    const radius = settings.theme?.radius
        ? `${ settings.theme.radius }px`
        : ( tokens[ 'dono-radius-md' ] || tokens[ 'dono-radius' ] || '8px' );

    if ( template.id === 'blank' ) {
        return (
            <div className="dono-template-thumb dono-template-thumb--blank">
                <Icon name="plus" size={ 20 } aria-hidden="true" />
            </div>
        );
    }

    // Detect multi-step shape from block markup so the thumb shows a
    // progress strip even though the form's layout field is still 'inline'.
    const isWizard = /wp:dono\/steps/.test( template.blocks || '' );

    const sheet = (
        <div className="dono-template-thumb__sheet" style={ { borderRadius: radius } }>
            { isWizard && (
                <div className="dono-template-thumb__steps">
                    <span className="is-active" />
                    <span />
                    <span />
                </div>
            ) }
            <span className="dono-template-thumb__title" />
            <span className="dono-template-thumb__sub" />
            <div className="dono-template-thumb__tiles">
                <span style={ { borderRadius: radius } } />
                <span className="is-active" style={ { borderRadius: radius } } />
                <span style={ { borderRadius: radius } } />
                <span style={ { borderRadius: radius } } />
            </div>
            <span className="dono-template-thumb__field" style={ { borderRadius: radius } } />
            <span
                className="dono-template-thumb__button"
                style={ { background: accent, borderRadius: radius } }
            />
        </div>
    );

    return (
        <div
            className={ `dono-template-thumb dono-template-thumb--${ layout }` }
            style={ { '--thumb-accent': accent } }
        >
            { layout === 'modal' ? (
                <div className="dono-template-thumb__modal-backdrop">
                    { sheet }
                </div>
            ) : sheet }
        </div>
    );
}
