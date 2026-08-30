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
 *
 * A template can carry its own shape in a `thumb` key. Add-ons register
 * layouts core has never heard of, and without that they all fall back to the
 * standard wireframe, so a picker offering two of them draws the same page
 * twice.
 */

import { useEffect, useMemo, useState } from '@wordpress/element';
import { Modal, Spinner } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import { addQueryArgs } from '@wordpress/url';
import { __ } from '@wordpress/i18n';

// Grouping keys are stable; only the display text is translated.
const CATEGORY_LABELS = {
    All:       __( 'All', 'fundkit-fundraising-campaigns' ),
    General:   __( 'General', 'fundkit-fundraising-campaigns' ),
    Appeals:   __( 'Appeals', 'fundkit-fundraising-campaigns' ),
    Community: __( 'Community', 'fundkit-fundraising-campaigns' ),
    Impact:    __( 'Impact', 'fundkit-fundraising-campaigns' ),
    Bare:      __( 'Bare', 'fundkit-fundraising-campaigns' ),
    Other:     __( 'Other', 'fundkit-fundraising-campaigns' ),
};

const CATEGORY_ORDER = [ 'General', 'Appeals', 'Community', 'Impact', 'Bare' ];

// What each layout puts down the main column, top to bottom. The parts are
// drawn as miniatures of the real thing rather than as grey bars: somebody
// choosing between fourteen of these is comparing pages, and a bar chart of
// rectangles tells them nothing about which page they are getting.
const THUMBS = {
    standard:     { main: [ 'media', 'figures2', 'bar', 'text', 'list' ],  form: true },
    hero:         { main: [ 'accent', 'text', 'list' ],                    form: true },
    cover:        { main: [ 'cover', 'text', 'list' ],                     form: true },
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

/**
 * The wireframe a template is drawn as. A template can carry its own shape,
 * which is the only way an add-on's layout gets a picture of itself: core keys
 * these by id and has never heard of one.
 */
export function thumbFor( template ) {
    return template.thumb || THUMBS[ template.id ] || THUMBS.standard;
}

const line = ( i ) => <i key={ i } />;

/** One part of the page, drawn small. */
function Part( { kind } ) {
    switch ( kind ) {
        case 'title':
            return <span className="gctp-title" />;

        case 'text':
            return <span className="gctp-text">{ [ 0, 1, 2 ].map( line ) }</span>;

        // The photo glyph says the grey block is where a picture goes, rather
        // than a panel somebody has to guess at.
        case 'media':
        case 'mediaTall':
            return (
                <span className={ `gctp-media${ kind === 'mediaTall' ? ' is-tall' : '' }` }>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                        <rect x="3" y="4" width="18" height="16" rx="2.5" />
                        <circle cx="8.5" cy="9.5" r="1.6" />
                        <path d="m21 15.5-4.5-4.5L6 21.5" />
                    </svg>
                </span>
            );

        // The figures sit ON the photo here, which is the only thing that tells
        // this apart from the colour hero at thumbnail size.
        case 'cover':
        case 'coverCentre':
            return (
                <span className={ `gctp-cover${ kind === 'coverCentre' ? ' is-centred' : '' }` }>
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                        <rect x="3" y="4" width="18" height="16" rx="2.5" />
                        <circle cx="8.5" cy="9.5" r="1.6" />
                        <path d="m21 15.5-4.5-4.5L6 21.5" />
                    </svg>
                    <span className="over">
                        <i className="h" />
                        <span className="figs"><i /><i /></span>
                        <span className="bar"><i /></span>
                    </span>
                </span>
            );

        // The photograph on one side and the readout on the other, which is a
        // shape none of the stacked heroes can stand for.
        case 'split':
            return (
                <span className="gctp-split">
                    <span className="gctp-media">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.7" strokeLinecap="round" strokeLinejoin="round">
                            <rect x="3" y="4" width="18" height="16" rx="2.5" />
                            <circle cx="8.5" cy="9.5" r="1.6" />
                            <path d="m21 15.5-4.5-4.5L6 21.5" />
                        </svg>
                    </span>
                    <span className="gctp-accent">
                        <i className="h" />
                        <span className="figs"><i /><i /></span>
                        <span className="bar"><i /></span>
                    </span>
                </span>
            );

        // The row of links to the rest of the page.
        case 'nav':
            return (
                <span className="gctp-nav">
                    { [ 0, 1, 2 ].map( ( i ) => <i key={ i } /> ) }
                </span>
            );

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
    // Ranked rows across the whole width, for a layout that puts its lists
    // under the columns instead of inside one.
    if ( kind === 'rows' ) {
        return (
            <span className="gctp-list is-wide">
                { [ 0, 1, 2 ].map( ( i ) => (
                    <span key={ i }><i className="av" /><i className="nm" /><i className="amt" /></span>
                ) ) }
            </span>
        );
    }
    return null;
}

export default function CampaignTemplatePicker( { value, campaignType, onPick, onClose } ) {
    const [ templates, setTemplates ] = useState( [] );
    const [ loading, setLoading ]     = useState( true );
    const [ category, setCategory ]   = useState( 'All' );
    const [ failed, setFailed ]       = useState( false );

    const load = () => {
        setLoading( true );
        setFailed( false );
        // The type is asked for, because what a campaign can lay out depends on
        // it: a peer-to-peer campaign has teams and a fundraiser grid to place,
        // and a single-form layout has nowhere to put either.
        apiFetch( { path: addQueryArgs( '/fundkit/v1/admin/campaigns/templates', {
            campaign_type: campaignType || undefined,
        } ) } )
            .then( ( list ) => setTemplates( Array.isArray( list ) ? list : [] ) )
            .catch( () => {
                setTemplates( [] );
                setFailed( true );
            } )
            .finally( () => setLoading( false ) );
    };

    // eslint-disable-next-line react-hooks/exhaustive-deps
    useEffect( load, [ campaignType ] );

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
            title={ __( 'Campaign templates', 'fundkit-fundraising-campaigns' ) }
            onRequestClose={ onClose }
            className="fundkit-template-picker fundkit-ctp"
            size="large"
        >
            { failed ? (
                <div className="fundkit-template-picker__state">
                    <p>{ __( 'The campaign templates could not be loaded.', 'fundkit-fundraising-campaigns' ) }</p>
                    <button type="button" className="btn" onClick={ load }>
                        { __( 'Try again', 'fundkit-fundraising-campaigns' ) }
                    </button>
                </div>
            ) : loading ? (
                <div className="fundkit-template-picker__state"><Spinner /></div>
            ) : (
                <>
                    <p className="fundkit-template-picker__intro">
                        { __( 'Where things sit on the campaign page. Everything here is blocks, so you can rearrange any of it afterwards.', 'fundkit-fundraising-campaigns' ) }
                    </p>

                    { categories.length > 2 && (
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
                    ) }

                    <div className="fundkit-template-picker__grid">
                        { visible.map( ( t ) => (
                            <button
                                key={ t.id }
                                type="button"
                                className={ `fundkit-template-picker__card${ value === t.id ? ' is-active' : '' }` }
                                aria-pressed={ value === t.id }
                                onClick={ () => onPick( t ) }
                            >
                                <Wireframe shape={ thumbFor( t ) } />
                                <span className="fundkit-template-picker__meta">
                                    <strong>{ t.name }</strong>
                                    <span className="fundkit-template-picker__desc">{ t.description }</span>
                                    { t.best_for && (
                                        <span className="fundkit-ctp__best">{ t.best_for }</span>
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
    const { main, form, footer, stacked, tone } = shape;

    return (
        <span className={ `fundkit-ctp__thumb${ tone === 'dark' ? ' is-dark' : '' }` } aria-hidden="true">
            <span className={ `fundkit-ctp__cols${ stacked ? ' is-stacked' : '' }` }>
                <span className="fundkit-ctp__main">
                    { main.map( ( kind, i ) => <Part key={ i } kind={ kind } /> ) }
                    { stacked && <FormPart /> }
                </span>
                { form && (
                    <span className="fundkit-ctp__side">
                        <FormPart />
                    </span>
                ) }
            </span>
            { footer && <Footer kind={ footer } /> }
        </span>
    );
}
