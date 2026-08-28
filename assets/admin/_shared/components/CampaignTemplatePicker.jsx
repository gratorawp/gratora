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

// What each layout puts down the main column, top to bottom. The parts are
// drawn as miniatures of the real thing rather than as grey bars: somebody
// choosing between fourteen of these is comparing pages, and a bar chart of
// rectangles tells them nothing about which page they are getting.
const THUMBS = {
    standard:     { main: [ 'media', 'figures2', 'bar', 'text', 'list' ],  form: true },
    hero:         { main: [ 'accent', 'text', 'list' ],                    form: true },
    split:        { main: [ 'title', 'accentSmall', 'text', 'media' ],     form: true },
    story:        { main: [ 'mediaTall', 'text', 'bar' ],                  form: true },
    gallery:      { main: [ 'media', 'text', 'bar' ],                      form: true, footer: 'cards' },
    deadline:     { main: [ 'figures3', 'bar', 'media', 'text' ],          form: true },
    urgent:       { main: [ 'accent', 'text' ],                            form: true },
    matched:      { main: [ 'title', 'accentSmall', 'media', 'text' ],     form: true },
    supporters:   { main: [ 'bar', 'text' ],                               form: true, footer: 'wall' },
    leaderboard:  { main: [ 'soft2', 'list', 'list' ],                     form: true },
    thermometer:  { main: [ 'accentSmall', 'text' ],                       form: true, footer: 'wall' },
    tiers:        { main: [ 'text', 'cards3', 'bar', 'media' ],            form: true },
    transparency: { main: [ 'media', 'soft4', 'text', 'bar' ],             form: true },
    minimal:      { main: [ 'title', 'text' ],                             stacked: true },
};

const line = ( i ) => <i key={ i } />;

/** One part of the page, drawn small. */
function Part( { kind } ) {
    switch ( kind ) {
        case 'title':
            return <span className="gctp-title" />;

        case 'text':
            return <span className="gctp-text">{ [ 0, 1, 2 ].map( line ) }</span>;

        case 'media':
        case 'mediaTall':
            return <span className={ `gctp-media${ kind === 'mediaTall' ? ' is-tall' : '' }` } />;

        case 'bar':
            return <span className="gctp-bar"><i /></span>;

        case 'figures2':
        case 'figures3':
            return (
                <span className="gctp-figures">
                    { [ ...Array( kind === 'figures3' ? 3 : 2 ) ].map( ( _, i ) => (
                        <span key={ i }><i className="n" /><i className="l" /></span>
                    ) ) }
                </span>
            );

        case 'soft2':
        case 'soft4':
            return (
                <span className="gctp-soft">
                    { [ ...Array( kind === 'soft4' ? 4 : 2 ) ].map( ( _, i ) => (
                        <span key={ i }><i className="n" /><i className="l" /></span>
                    ) ) }
                </span>
            );

        // A band in the campaign's colour: figures and a progress bar reversed out.
        case 'accent':
        case 'accentSmall':
            return (
                <span className={ `gctp-accent${ kind === 'accentSmall' ? ' is-small' : '' }` }>
                    <i className="h" />
                    <span className="figs"><i /><i /></span>
                    <span className="bar"><i /></span>
                </span>
            );

        case 'list':
            return (
                <span className="gctp-list">
                    { [ 0, 1, 2 ].map( ( i ) => (
                        <span key={ i }><i className="av" /><i className="nm" /><i className="amt" /></span>
                    ) ) }
                </span>
            );

        case 'cards3':
            return (
                <span className="gctp-cards">
                    { [ 0, 1, 2 ].map( ( i ) => (
                        <span key={ i }><i className="n" /><i className="l" /></span>
                    ) ) }
                </span>
            );

        default:
            return null;
    }
}

/** The donation form, the one part that looks the same on every layout. */
function FormPart() {
    return (
        <span className="gctp-form">
            <i className="t" />
            <span className="tiles"><i /><i className="on" /><i /></span>
            <i className="f" />
            <i className="f" />
            <i className="btn" />
        </span>
    );
}

function Footer( { kind } ) {
    if ( kind === 'wall' ) {
        return (
            <span className="gctp-wall">
                { [ ...Array( 8 ) ].map( ( _, i ) => <i key={ i } /> ) }
            </span>
        );
    }
    if ( kind === 'cards' ) {
        return (
            <span className="gctp-grid">
                { [ 0, 1, 2 ].map( ( i ) => <i key={ i } /> ) }
            </span>
        );
    }
    return null;
}

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

/** The layout drawn small: main column, form beside it, any full-width footer. */
function Wireframe( { shape } ) {
    const { main, form, footer, stacked } = shape;

    return (
        <span className="giveflow-ctp__thumb" aria-hidden="true">
            <span className={ `giveflow-ctp__cols${ stacked ? ' is-stacked' : '' }` }>
                <span className="giveflow-ctp__main">
                    { main.map( ( kind, i ) => <Part key={ i } kind={ kind } /> ) }
                    { stacked && <FormPart /> }
                </span>
                { form && (
                    <span className="giveflow-ctp__side">
                        <FormPart />
                    </span>
                ) }
            </span>
            { footer && <Footer kind={ footer } /> }
        </span>
    );
}
