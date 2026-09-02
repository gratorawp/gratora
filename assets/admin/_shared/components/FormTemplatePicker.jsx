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
    All:       __( 'All', 'fundraising-toolkit' ),
    Blank:     __( 'Blank', 'fundraising-toolkit' ),
    Starter:   __( 'Starter', 'fundraising-toolkit' ),
    Standard:  __( 'Standard', 'fundraising-toolkit' ),
    Recurring: __( 'Recurring', 'fundraising-toolkit' ),
    Wizard:    __( 'Wizard', 'fundraising-toolkit' ),
    Formal:    __( 'Formal', 'fundraising-toolkit' ),
    Other:     __( 'Other', 'fundraising-toolkit' ),
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
        apiFetch( { path: '/fundkit/v1/admin/forms/templates' } )
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
            title={ __( 'Choose a starter template', 'fundraising-toolkit' ) }
            onRequestClose={ onClose }
            className="fundkit-template-picker"
            size="large"
        >
            { failed ? (
                <div style={ { padding: 40, textAlign: 'center' } }>
                    <p>{ __( 'The starter templates could not be loaded.', 'fundraising-toolkit' ) }</p>
                    <button type="button" className="btn" onClick={ load }>
                        { __( 'Try again', 'fundraising-toolkit' ) }
                    </button>
                </div>
            ) : loading ? (
                <div style={ { padding: 40, textAlign: 'center' } }><Spinner /></div>
            ) : (
                <>
                    { intro && (
                        <p className="fundkit-template-picker__intro">{ intro }</p>
                    ) }
                    <div className="fundkit-template-picker__filters" role="tablist">
                        { categories.map( ( c ) => (
                            <button
                                key={ c }
                                type="button"
                                role="tab"
                                aria-selected={ category === c }
                                className={ `fundkit-template-picker__filter${ category === c ? ' is-active' : '' }` }
                                onClick={ () => setCategory( c ) }
                            >
                                { CATEGORY_LABELS[ c ] || c }
                            </button>
                        ) ) }
                    </div>
                    <div className="fundkit-template-picker__grid">
                        { visible.map( ( t ) => (
                            <button
                                key={ t.id }
                                type="button"
                                className="fundkit-template-picker__card"
                                onClick={ () => onPick( t ) }
                                disabled={ creating }
                            >
                                <FormTemplateThumb template={ t } />
                                <div className="fundkit-template-picker__meta">
                                    <strong>{ t.name }</strong>
                                    <span className="fundkit-template-picker__desc">{ t.description }</span>
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

    const presets   = Array.isArray( window.fundkit?.styling?.presets ) ? window.fundkit.styling.presets : [];
    const defaults  = window.fundkit?.styling?.defaults || {};
    const defaultId = String( window.fundkit?.styling?.default_id || '' );
    const templatePresetId = String( settings.style?.preset_id || '' );
    const chosenPreset = presets.find( ( p ) => p.id === ( templatePresetId || defaultId ) );
    const tokens = { ...defaults, ...( chosenPreset?.tokens || {} ) };
    const accent = ( settings.theme?.accent ) || tokens[ 'fundkit-accent' ] || '#211d3f';
    const radius = settings.theme?.radius
        ? `${ settings.theme.radius }px`
        : ( tokens[ 'fundkit-radius-md' ] || tokens[ 'fundkit-radius' ] || '8px' );

    if ( template.id === 'blank' ) {
        return (
            <div className="fundkit-template-thumb fundkit-template-thumb--blank">
                <Icon name="plus" size={ 20 } aria-hidden="true" />
            </div>
        );
    }

    // Detect multi-step shape from block markup so the thumb shows a
    // progress strip even though the form's layout field is still 'inline'.
    const isWizard = /wp:fundkit\/steps/.test( template.blocks || '' );

    const sheet = (
        <div className="fundkit-template-thumb__sheet" style={ { borderRadius: radius } }>
            { isWizard && (
                <div className="fundkit-template-thumb__steps">
                    <span className="is-active" />
                    <span />
                    <span />
                </div>
            ) }
            <span className="fundkit-template-thumb__title" />
            <span className="fundkit-template-thumb__sub" />
            <div className="fundkit-template-thumb__tiles">
                <span style={ { borderRadius: radius } } />
                <span className="is-active" style={ { borderRadius: radius } } />
                <span style={ { borderRadius: radius } } />
                <span style={ { borderRadius: radius } } />
            </div>
            <span className="fundkit-template-thumb__field" style={ { borderRadius: radius } } />
            <span
                className="fundkit-template-thumb__button"
                style={ { background: accent, borderRadius: radius } }
            />
        </div>
    );

    return (
        <div
            className={ `fundkit-template-thumb fundkit-template-thumb--${ layout }` }
            style={ { '--thumb-accent': accent } }
        >
            { layout === 'modal' ? (
                <div className="fundkit-template-thumb__modal-backdrop">
                    { sheet }
                </div>
            ) : sheet }
        </div>
    );
}
