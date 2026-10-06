/**
 * What gratora.net sends reaches the Add-ons screen: the plans, an offer, the
 * plan each card is sold in, and a line saying where the lists came from. A
 * site on the built-in list sees none of that and keeps its link to the plans.
 */

import { render } from 'preact';
import { resetLocaleData, setLocaleData } from '@wordpress/i18n';
import { Puzzle } from 'lucide-react';

import Addons from '../../assets/admin/addons/Addons';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const addon = ( over ) => ( {
    slug:        'events',
    name:        'Event Tickets',
    description: 'Sell tickets to fundraising events.',
    icon:        'ticket',
    url:         'https://gratora.net/add-ons/events/?utm_source=plugin&utm_medium=add-ons',
    free:        false,
    status:      'available',
    activateUrl: '',
    plan:        '',
    ...over,
} );

const plan = ( over ) => ( {
    slug:    'standard',
    name:    'Standard',
    summary: '',
    sites:   1,
    count:   6,
    url:     'https://gratora.net/pricing/?utm_source=plugin&utm_medium=add-ons',
    ...over,
} );

const RUSSIAN = 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);';

const OFFER = {
    text: 'New plans are about half price for the first year.',
    url:  'https://gratora.net/pricing/?utm_source=plugin&utm_medium=add-ons',
};

function screen( props ) {
    const root = document.createElement( 'div' );
    document.body.appendChild( root );
    render( <Addons addons={ [ addon() ] } { ...props } />, root );
    return root;
}

function drawing( icon ) {
    const root = document.createElement( 'div' );
    render( icon, root );
    return root.querySelector( 'svg' ).innerHTML;
}

const tile = ( icon ) => screen( { addons: [ addon( { icon } ) ] } ).querySelector( '.gratora-addon__tile svg' );
const cardPill = ( over ) => screen( { addons: [ addon( over ) ] } ).querySelector( '.gratora-addon .gratora-pill' );
const source = ( root ) => root.querySelector( '.gratora-page-head .gratora-addons__source' );
const planColumns = ( root ) => [ ...root.querySelectorAll( 'ul.gratora-plans > li.gratora-plan' ) ];
const facts = ( column ) => [ ...column.querySelectorAll( '.gratora-plan__fact' ) ].map( ( fact ) => fact.textContent );
const parts = ( root ) => [ ...root.querySelector( '.gratora-addons' ).children ].map( ( part ) => part.className );
const comparePlans = ( root ) => [ ...root.querySelectorAll( 'a' ) ].find( ( a ) => a.textContent.startsWith( 'Compare plans' ) );

beforeEach( () => {
    document.body.innerHTML = '';
} );

afterEach( () => {
    resetLocaleData();
} );

test( 'a card whose icon this build does not know draws the default one', () => {
    const puzzle = drawing( <Puzzle /> );

    expect( tile( 'rocket' )?.innerHTML ).toBe( puzzle );
    expect( tile( '' )?.innerHTML ).toBe( puzzle );
    expect( tile( 'ticket' ).innerHTML ).not.toBe( puzzle );
} );

test.each( [ 'constructor', 'toString', '__proto__' ] )(
    'the icon name "%s", which every object answers to, is still an unknown icon',
    ( name ) => {
        expect( tile( name )?.innerHTML ).toBe( drawing( <Puzzle /> ) );
    }
);

test( 'lists loaded from gratora.net say so with a link there, and the built-in list does not', () => {
    const line = source( screen( { source: 'remote' } ) );
    const link = line.querySelector( 'a' );

    expect( line.textContent ).toContain( 'The lists on this page are loaded from gratora.net' );
    expect( link.firstChild.textContent ).toBe( 'gratora.net' );
    expect( link.getAttribute( 'href' ) ).toBe( 'https://gratora.net/add-ons/?utm_source=plugin&utm_medium=add-ons' );
    expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
    expect( link.getAttribute( 'rel' ) ).toBe( 'noreferrer' );
    expect( link.querySelector( 'svg' ) ).not.toBeNull();
    expect( link.querySelector( '.screen-reader-text' ).textContent ).toBe( '(opens in a new tab)' );

    for ( const other of [ 'builtin', '', undefined ] ) {
        const root = screen( { source: other } );

        expect( source( root ) ).toBeNull();
        expect( root.textContent ).not.toContain( 'gratora.net' );
    }
} );

test( 'a translator can move the link inside the sentence', () => {
    setLocaleData( {
        'The lists on this page are loaded from <a>gratora.net</a>': [ 'Von <a>gratora.net</a> kommen die Listen dieser Seite' ],
    }, 'gratora-donation-platform' );

    const line = source( screen( { source: 'remote' } ) );
    const link = line.querySelector( 'a' );

    expect( line.firstChild.textContent ).toBe( 'Von ' );
    expect( link.firstChild.textContent ).toBe( 'gratora.net' );
    expect( link.nextSibling.textContent ).toBe( ' kommen die Listen dieser Seite' );
    expect( link.getAttribute( 'href' ) ).toBe( 'https://gratora.net/add-ons/?utm_source=plugin&utm_medium=add-ons' );
} );

test( 'the link to compare plans is there until the plans themselves are', () => {
    expect( comparePlans( screen( {} ) ).getAttribute( 'href' ) ).toBe( 'https://gratora.net/pricing/?utm_source=plugin&utm_medium=add-ons' );
    expect( comparePlans( screen( { source: 'remote', plans: [] } ) ) ).toBeDefined();

    expect( comparePlans( screen( { source: 'remote', plans: [ plan() ] } ) ) ).toBeUndefined();
} );

test( 'an offer is shown with its text and a link to it, and no offer leaves no bar', () => {
    const bar = screen( { offer: OFFER } ).querySelector( '.gratora-offer' );
    const link = bar.querySelector( 'a' );

    expect( bar.querySelector( '.gratora-offer__text' ).textContent ).toBe( OFFER.text );
    expect( bar.querySelector( '.gratora-offer__icon svg' ) ).not.toBeNull();
    expect( link.firstChild.textContent ).toBe( 'Learn more' );
    expect( link.getAttribute( 'href' ) ).toBe( OFFER.url );
    expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
    expect( link.getAttribute( 'rel' ) ).toBe( 'noreferrer' );
    expect( link.querySelector( 'svg' ) ).not.toBeNull();
    expect( link.querySelector( '.screen-reader-text' ).textContent ).toBe( '(opens in a new tab)' );
    expect( bar.querySelector( '.gratora-offer__text' ).id ).not.toBe( '' );
    expect( link.getAttribute( 'aria-describedby' ) ).toBe( bar.querySelector( '.gratora-offer__text' ).id );

    expect( screen( { offer: null } ).querySelector( '.gratora-offer' ) ).toBeNull();
    expect( screen( {} ).querySelector( '.gratora-offer' ) ).toBeNull();
} );

test( 'a plan shows its name, what it includes, how many sites it covers and a link to it', () => {
    const elitePage = 'https://gratora.net/pricing/?utm_source=plugin&utm_medium=add-ons#elite';
    const [ standard, elite, single ] = planColumns( screen( { plans: [
        plan(),
        plan( { slug: 'elite', name: 'Elite', sites: 5, count: 9, url: elitePage } ),
        plan( { slug: 'single', name: 'Single', sites: 2, count: 1 } ),
    ] } ) );
    const link = standard.querySelector( 'a' );

    expect( standard.querySelector( 'h3' ).textContent ).toBe( 'Standard' );
    expect( facts( standard ) ).toEqual( [ '6 add-ons', '1 site' ] );
    expect( link.firstChild.textContent ).toBe( 'See plan' );
    expect( link.getAttribute( 'href' ) ).toBe( plan().url );
    expect( link.getAttribute( 'target' ) ).toBe( '_blank' );
    expect( link.getAttribute( 'rel' ) ).toBe( 'noreferrer' );
    expect( link.getAttribute( 'aria-label' ) ).toBeNull();
    expect( link.textContent ).toBe( 'See plan Standard (opens in a new tab)' );
    expect( link.querySelector( 'svg' ) ).not.toBeNull();

    expect( elite.querySelector( 'h3' ).textContent ).toBe( 'Elite' );
    expect( facts( elite ) ).toEqual( [ '9 add-ons', '5 sites' ] );
    expect( elite.querySelector( 'a' ).getAttribute( 'href' ) ).toBe( elitePage );
    expect( elite.querySelector( 'a' ).textContent ).toBe( 'See plan Elite (opens in a new tab)' );

    expect( facts( single ) ).toEqual( [ '1 add-on', '2 sites' ] );
} );

test( 'a plan shows its summary only when it was given one', () => {
    const [ described, bare ] = planColumns( screen( { plans: [
        plan( { summary: 'More ways to take a donation.' } ),
        plan( { slug: 'advanced', name: 'Advanced' } ),
    ] } ) );

    expect( described.querySelector( '.gratora-plan__summary' ).textContent ).toBe( 'More ways to take a donation.' );
    expect( bare.querySelector( '.gratora-plan__summary' ) ).toBeNull();
    expect( bare.querySelectorAll( 'p' ) ).toHaveLength( 1 );
} );

test( 'the counts on a plan take the plural form the locale has for them', () => {
    setLocaleData( {
        '': { domain: 'gratora-donation-platform', lang: 'ru', plural_forms: RUSSIAN },
        '%d add-on': [ 'ADDON_ONE_%d', 'ADDON_FEW_%d', 'ADDON_MANY_%d' ],
        '%d site':   [ 'SITE_ONE_%d', 'SITE_FEW_%d', 'SITE_MANY_%d' ],
    }, 'gratora-donation-platform' );

    const [ few, many ] = planColumns( screen( { plans: [
        plan( { count: 3, sites: 2 } ),
        plan( { slug: 'elite', count: 9, sites: 5 } ),
    ] } ) );

    expect( facts( few ) ).toEqual( [ 'ADDON_FEW_3', 'SITE_FEW_2' ] );
    expect( facts( many ) ).toEqual( [ 'ADDON_MANY_9', 'SITE_MANY_5' ] );
} );

test( 'the plans and the cards are each labelled when both are on the screen, and neither is when the cards stand alone', () => {
    const root = screen( { plans: [ plan() ], offer: OFFER } );
    const labels = [ ...root.querySelectorAll( 'h2.gratora-addons__section' ) ].map( ( label ) => label.textContent );

    expect( labels ).toEqual( [ 'Plans', 'Add-ons' ] );
    expect( parts( root ) ).toEqual( [
        'gratora-crumbs',
        'gratora-page-head',
        'gratora-offer',
        'gratora-addons__section',
        'gratora-plans',
        'gratora-addons__section',
        'gratora-addons__grid',
    ] );

    expect( parts( screen( {} ) ) ).toEqual( [ 'gratora-crumbs', 'gratora-page-head', 'gratora-addons__grid' ] );
    expect( parts( screen( { plans: [] } ) ) ).toEqual( [ 'gratora-crumbs', 'gratora-page-head', 'gratora-addons__grid' ] );
} );

test( 'a card the site does not have names the plan it is sold in, and a card the site has says that instead', () => {
    const sold = cardPill( { plan: 'Advanced' } );

    expect( sold.textContent ).toBe( 'Advanced' );
    expect( sold.className ).toBe( 'gratora-pill gratora-pill--type' );

    expect( cardPill( { plan: 'Advanced', status: 'active' } ).textContent ).toBe( 'Active' );
    expect( cardPill( { plan: 'Advanced', status: 'installed' } ).textContent ).toBe( 'Installed' );
    expect( cardPill( { plan: 'Standard', free: true } ).textContent ).toBe( 'Free' );
    expect( cardPill( { plan: '' } ) ).toBeNull();
} );

test( 'what gratora.net sent is shown in its own words whatever the language of the screen', () => {
    setLocaleData( {
        Advanced:                        [ 'Fortgeschritten' ],
        Standard:                        [ 'Standardpaket' ],
        'More ways to take a donation.': [ 'Mehr Wege, eine Spende anzunehmen.' ],
        [ OFFER.text ]:                  [ 'Neue Tarife kosten im ersten Jahr etwa die Hälfte.' ],
    }, 'gratora-donation-platform' );

    const root = screen( {
        addons: [ addon( { plan: 'Advanced' } ) ],
        plans:  [ plan( { summary: 'More ways to take a donation.' } ) ],
        offer:  OFFER,
    } );

    expect( root.querySelector( '.gratora-addon .gratora-pill' ).textContent ).toBe( 'Advanced' );
    expect( root.querySelector( '.gratora-plan__name' ).textContent ).toBe( 'Standard' );
    expect( root.querySelector( '.gratora-plan__summary' ).textContent ).toBe( 'More ways to take a donation.' );
    expect( root.querySelector( '.gratora-offer__text' ).textContent ).toBe( OFFER.text );
} );

test( 'markup in what gratora.net sent is shown as written and never becomes part of the page', () => {
    const tag = '<img src="x" onerror="alert(1)">';
    const root = screen( {
        addons: [ addon( { name: `Name ${ tag }`, description: `About ${ tag }`, plan: `Plan ${ tag }` } ) ],
        plans:  [ plan( { name: `Standard ${ tag }`, summary: `Summary ${ tag }` } ) ],
        offer:  { ...OFFER, text: `Offer ${ tag }` },
    } );

    expect( root.querySelector( '.gratora-addon__name' ).textContent ).toBe( `Name ${ tag }` );
    expect( root.querySelector( '.gratora-addon__desc' ).textContent ).toBe( `About ${ tag }` );
    expect( root.querySelector( '.gratora-addon .gratora-pill' ).textContent ).toBe( `Plan ${ tag }` );
    expect( root.querySelector( '.gratora-plan__name' ).textContent ).toBe( `Standard ${ tag }` );
    expect( root.querySelector( '.gratora-plan__summary' ).textContent ).toBe( `Summary ${ tag }` );
    expect( root.querySelector( '.gratora-offer__text' ).textContent ).toBe( `Offer ${ tag }` );
    expect( root.querySelector( 'img' ) ).toBeNull();
} );

test( 'a card is named one level under the label its group has, and at the level it always had without one', () => {
    const name = ( root ) => root.querySelector( '.gratora-addon__name' ).tagName;

    expect( name( screen( {} ) ) ).toBe( 'H2' );

    const root = screen( { plans: [ plan() ] } );

    expect( [ ...root.querySelectorAll( '.gratora-addons__section' ) ].map( ( h ) => h.tagName ) ).toEqual( [ 'H2', 'H2' ] );
    expect( root.querySelector( '.gratora-plan__name' ).tagName ).toBe( 'H3' );
    expect( name( root ) ).toBe( 'H3' );
} );

test( 'the plans add-ons are sold in are worded apart from the recurring plans elsewhere in the plugin', () => {
    setLocaleData( {
        'paid plans that hold the add-ons\u0004Plans':  [ 'Tarife' ],
        'link to one paid plan\u0004See plan':          [ 'Tarif ansehen' ],
        Plans:                                           [ 'Daueraufträge' ],
    }, 'gratora-donation-platform' );

    const root = screen( { plans: [ plan() ] } );

    expect( root.querySelector( '.gratora-addons__section' ).textContent ).toBe( 'Tarife' );
    expect( root.querySelector( '.gratora-plan__foot a' ).firstChild.textContent ).toBe( 'Tarif ansehen' );
} );
