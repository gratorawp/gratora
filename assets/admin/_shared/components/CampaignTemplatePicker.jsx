/**
 * Starter layout for a new campaign page.
 *
 * Wears the shared template-picker chrome, the same as the form picker, so the
 * two read as one feature and this one has room for a lot more layouts than it
 * holds today. Only the wireframe thumb is its own.
 * Category tabs appear once there is more than one category to filter by;
 * with five templates in two groups they would be furniture.
 *
 * Each thumb is a wireframe rather than a rendered preview. What separates
 * these templates is where things sit on the page, and a real preview at this
 * size is a grey rectangle whichever layout it is.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { __ } from '@wordpress/i18n';

// Grouping keys are stable; only the display text is translated.
const CATEGORY_LABELS = {
    All:       __( 'All', 'giveflow-fundraising-campaigns' ),
    General:   __( 'General', 'giveflow-fundraising-campaigns' ),
    Appeals:   __( 'Appeals', 'giveflow-fundraising-campaigns' ),
    Community: __( 'Community', 'giveflow-fundraising-campaigns' ),
    Impact:    __( 'Impact', 'giveflow-fundraising-campaigns' ),
    Bare:      __( 'Bare', 'giveflow-fundraising-campaigns' ),
    Other:     __( 'Other', 'giveflow-fundraising-campaigns' ),
};

const CATEGORY_ORDER = [ 'General', 'Appeals', 'Community', 'Impact', 'Bare' ];

// Rows the wireframe draws, top to bottom, per template. An unknown id falls
// back to the standard shape rather than drawing an empty card.
const THUMBS = {
    standard:     { rows: [ 'media', 'figures', 'bar', 'text', 'list' ],   form: true },
    hero:         { rows: [ 'accent-tall', 'text', 'list' ],               form: true },
    split:        { rows: [ 'title', 'accent', 'text', 'media' ],          form: true },
    story:        { rows: [ 'media-wide', 'text', 'bar' ],                 form: true },
    gallery:      { rows: [ 'media', 'text', 'bar' ],                      form: true, band: true },
    deadline:     { rows: [ 'figures3', 'bar', 'media', 'text' ],          form: true },
    urgent:       { rows: [ 'accent-tall', 'text' ],                       form: true },
    matched:      { rows: [ 'title', 'accent', 'media', 'text' ],          form: true },
    supporters:   { rows: [ 'bar', 'text' ],                               form: true, wall: true },
    leaderboard:  { rows: [ 'soft', 'list', 'list' ],                      form: true },
    thermometer:  { rows: [ 'accent', 'text' ],                            form: true, wall: true },
    tiers:        { rows: [ 'text', 'cards', 'bar', 'media' ],             form: true },
    transparency: { rows: [ 'media', 'soft', 'text', 'bar' ],              form: true },
    minimal:      { rows: [ 'title', 'text' ],                             form: false, stacked: true, formRow: true },
};

export default function CampaignTemplatePicker( { value, onPick, onClose } ) {
    const [ templates, setTemplates ] = useState( [] );
    const [ loading, setLoading ]     = useState( true );
    const [ category, setCategory ]   = useState( 'All' );
    const [ failed, setFailed ]       = useState( false );

    const load = () => {
        setLoading( true );
        setFailed( false );
        apiFetch( { path: '/giveflow/v1/admin/campaigns/templates' } )
            .then( ( list ) => setTemplates( Array.isArray( list ) ? list : [] ) )
            .catch( () => {
                setTemplates( [] );
                setFailed( true );
            } )
            .finally( () => setLoading( false ) );
    };

    useEffect( load, [] );

    const categories = useMemo( () => {
        const seen = new Set();
        for ( const t of templates ) seen.add( t.category || 'Other' );
        const known = CATEGORY_ORDER.filter( ( c ) => seen.has( c ) );
        const extra = [ ...seen ].filter( ( c ) => ! CATEGORY_ORDER.includes( c ) );
        return [ 'All', ...known, ...extra ];
    }, [ templates ] );

    const visible = category === 'All'
        ? templates
        : templates.filter( ( t ) => ( t.category || 'Other' ) === category );

    return (
        <Modal
            title={ __( 'Choose a page layout', 'giveflow-fundraising-campaigns' ) }
            onRequestClose={ onClose }
            className="giveflow-template-picker giveflow-ctp"
            size="large"
        >
            { failed ? (
                <div className="giveflow-template-picker__state">
                    <p>{ __( 'The page layouts could not be loaded.', 'giveflow-fundraising-campaigns' ) }</p>
                    <button type="button" className="btn" onClick={ load }>
                        { __( 'Try again', 'giveflow-fundraising-campaigns' ) }
                    </button>
                </div>
            ) : loading ? (
                <div className="giveflow-template-picker__state"><Spinner /></div>
            ) : (
                <>
                    <p className="giveflow-template-picker__intro">
                        { __( 'Where things sit on the campaign page. Everything here is blocks, so you can rearrange any of it afterwards.', 'giveflow-fundraising-campaigns' ) }
                    </p>

                    { categories.length > 2 && (
                        <div className="giveflow-template-picker__filters" role="tablist">
                            { categories.map( ( c ) => (
                                <button
                                    key={ c }
                                    type="button"
                                    role="tab"
                                    aria-selected={ category === c }
                                    className={ `giveflow-template-picker__filter${ category === c ? ' is-active' : '' }` }
                                    onClick={ () => setCategory( c ) }
                                >
                                    { CATEGORY_LABELS[ c ] || c }
                                </button>
                            ) ) }
                        </div>
                    ) }

                    <div className="giveflow-template-picker__grid">
                        { visible.map( ( t ) => (
                            <button
                                key={ t.id }
                                type="button"
                                className={ `giveflow-template-picker__card${ value === t.id ? ' is-active' : '' }` }
                                aria-pressed={ value === t.id }
                                onClick={ () => onPick( t ) }
                            >
                                <Wireframe shape={ THUMBS[ t.id ] || THUMBS.standard } />
                                <span className="giveflow-template-picker__meta">
                                    <strong>{ t.name }</strong>
                                    <span className="giveflow-template-picker__desc">{ t.description }</span>
                                    { t.best_for && (
                                        <span className="giveflow-ctp__best">{ t.best_for }</span>
                                    ) }
                                </span>
                            </button>
                        ) ) }
                    </div>
                </>
            ) }
        </Modal>
    );
}

/** The page shape as bars: main column, form column beside it, wall beneath. */
function Wireframe( { shape } ) {
    const { rows, form, wall, stacked, formRow, band } = shape;

    return (
        <span className="giveflow-ctp__thumb" aria-hidden="true">
            <span className={ `giveflow-ctp__cols${ stacked ? ' is-stacked' : '' }` }>
                <span className="giveflow-ctp__main">
                    { rows.map( ( row, i ) => (
                        <span key={ i } className={ `giveflow-ctp__row giveflow-ctp__row--${ row }` } />
                    ) ) }
                </span>
                { form && <span className="giveflow-ctp__form" /> }
                { formRow && <span className="giveflow-ctp__row giveflow-ctp__row--form-wide" /> }
            </span>
            { band && <span className="giveflow-ctp__band" /> }
            { wall && <span className="giveflow-ctp__wall" /> }
        </span>
    );
}
