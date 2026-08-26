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
    All:       __( 'All', 'giveflow-fundraising-campaigns' ),
    Blank:     __( 'Blank', 'giveflow-fundraising-campaigns' ),
    Starter:   __( 'Starter', 'giveflow-fundraising-campaigns' ),
    Standard:  __( 'Standard', 'giveflow-fundraising-campaigns' ),
    Recurring: __( 'Recurring', 'giveflow-fundraising-campaigns' ),
    Wizard:    __( 'Wizard', 'giveflow-fundraising-campaigns' ),
    Formal:    __( 'Formal', 'giveflow-fundraising-campaigns' ),
    Other:     __( 'Other', 'giveflow-fundraising-campaigns' ),
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
        apiFetch( { path: '/giveflow/v1/admin/forms/templates' } )
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
            title={ __( 'Choose a starter template', 'giveflow-fundraising-campaigns' ) }
            onRequestClose={ onClose }
            className="giveflow-form-template-picker"
            size="large"
        >
            { failed ? (
                <div style={ { padding: 40, textAlign: 'center' } }>
                    <p>{ __( 'The starter templates could not be loaded.', 'giveflow-fundraising-campaigns' ) }</p>
                    <button type="button" className="btn" onClick={ load }>
                        { __( 'Try again', 'giveflow-fundraising-campaigns' ) }
                    </button>
                </div>
            ) : loading ? (
                <div style={ { padding: 40, textAlign: 'center' } }><Spinner /></div>
            ) : (
                <>
                    { intro && (
                        <p className="giveflow-form-template-picker__intro">{ intro }</p>
                    ) }
                    <div className="giveflow-form-template-picker__filters" role="tablist">
                        { categories.map( ( c ) => (
                            <button
                                key={ c }
                                type="button"
                                role="tab"
                                aria-selected={ category === c }
                                className={ `giveflow-form-template-picker__filter${ category === c ? ' is-active' : '' }` }
                                onClick={ () => setCategory( c ) }
                            >
                                { CATEGORY_LABELS[ c ] || c }
                            </button>
                        ) ) }
                    </div>
                    <div className="giveflow-form-template-picker__grid">
                        { visible.map( ( t ) => (
                            <button
                                key={ t.id }
                                type="button"
                                className="giveflow-form-template-picker__card"
                                onClick={ () => onPick( t ) }
                                disabled={ creating }
                            >
                                <FormTemplateThumb template={ t } />
                                <div className="giveflow-form-template-picker__meta">
                                    <strong>{ t.name }</strong>
                                    <span className="giveflow-form-template-picker__desc">{ t.description }</span>
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

    const presets   = Array.isArray( window.giveflow?.styling?.presets ) ? window.giveflow.styling.presets : [];
    const defaults  = window.giveflow?.styling?.defaults || {};
    const defaultId = String( window.giveflow?.styling?.default_id || '' );
    const templatePresetId = String( settings.style?.preset_id || '' );
    const chosenPreset = presets.find( ( p ) => p.id === ( templatePresetId || defaultId ) );
    const tokens = { ...defaults, ...( chosenPreset?.tokens || {} ) };
    const accent = ( settings.theme?.accent ) || tokens[ 'giveflow-accent' ] || '#211d3f';
    const radius = settings.theme?.radius
        ? `${ settings.theme.radius }px`
        : ( tokens[ 'giveflow-radius-md' ] || tokens[ 'giveflow-radius' ] || '8px' );

    if ( template.id === 'blank' ) {
        return (
            <div className="giveflow-template-thumb giveflow-template-thumb--blank">
                <Icon name="plus" size={ 20 } aria-hidden="true" />
            </div>
        );
    }

    // Detect multi-step shape from block markup so the thumb shows a
    // progress strip even though the form's layout field is still 'inline'.
    const isWizard = /wp:giveflow\/steps/.test( template.blocks || '' );

    const sheet = (
        <div className="giveflow-template-thumb__sheet" style={ { borderRadius: radius } }>
            { isWizard && (
                <div className="giveflow-template-thumb__steps">
                    <span className="is-active" />
                    <span />
                    <span />
                </div>
            ) }
            <span className="giveflow-template-thumb__title" />
            <span className="giveflow-template-thumb__sub" />
            <div className="giveflow-template-thumb__tiles">
                <span style={ { borderRadius: radius } } />
                <span className="is-active" style={ { borderRadius: radius } } />
                <span style={ { borderRadius: radius } } />
                <span style={ { borderRadius: radius } } />
            </div>
            <span className="giveflow-template-thumb__field" style={ { borderRadius: radius } } />
            <span
                className="giveflow-template-thumb__button"
                style={ { background: accent, borderRadius: radius } }
            />
        </div>
    );

    return (
        <div
            className={ `giveflow-template-thumb giveflow-template-thumb--${ layout }` }
            style={ { '--thumb-accent': accent } }
        >
            { layout === 'modal' ? (
                <div className="giveflow-template-thumb__modal-backdrop">
                    { sheet }
                </div>
            ) : sheet }
        </div>
    );
}
