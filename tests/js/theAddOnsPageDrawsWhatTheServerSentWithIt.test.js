/**
 * The Add-ons page hands its screen everything the server sent with it: the
 * cards, the plans, the offer and where the lists came from.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react-dom/client', () => require( 'preact/compat/client' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const PRICING = 'https://gratora.net/pricing/?utm_source=plugin&utm_medium=add-ons';

const CARD = {
    slug:        'events',
    name:        'Event Tickets',
    description: 'Sell tickets to fundraising events.',
    icon:        'ticket',
    url:         'https://gratora.net/add-ons/events/?utm_source=plugin&utm_medium=add-ons',
    free:        false,
    status:      'available',
    activateUrl: '',
    plan:        '',
};

// The page mounts its screen on import, so each case needs its own evaluation.
function mounted( data ) {
    document.body.innerHTML = '<div id="gratora-admin-addons"></div>';
    window.gratoraAddons = data;
    jest.isolateModules( () => require( '../../assets/admin/addons/index' ) );

    return document.getElementById( 'gratora-admin-addons' );
}

afterEach( () => {
    delete window.gratoraAddons;
} );

test( 'the page draws the cards, the plans, the offer and the source it was sent, and only cards for the built-in list', () => {
    const full = mounted( {
        source: 'remote',
        addons: [ { ...CARD, plan: 'Advanced' } ],
        plans:  [ { slug: 'advanced', name: 'Advanced', summary: '', sites: 1, count: 9, url: PRICING } ],
        offer:  { text: 'New plans are about half price for the first year.', url: PRICING },
    } );

    expect( full.querySelector( '.gratora-addon__name' ).textContent ).toBe( 'Event Tickets' );
    expect( full.querySelector( '.gratora-plan__name' ).textContent ).toBe( 'Advanced' );
    expect( full.querySelector( '.gratora-offer__text' ).textContent ).toBe( 'New plans are about half price for the first year.' );
    expect( full.querySelector( '.gratora-addons__source' ).textContent ).toContain( 'loaded from gratora.net' );

    const bare = mounted( { source: 'builtin', addons: [ CARD ], plans: [], offer: null } );

    expect( bare.querySelector( '.gratora-addon__name' ).textContent ).toBe( 'Event Tickets' );
    expect( bare.querySelector( '.gratora-plans' ) ).toBeNull();
    expect( bare.querySelector( '.gratora-offer' ) ).toBeNull();
    expect( bare.querySelector( '.gratora-addons__source' ) ).toBeNull();
} );
