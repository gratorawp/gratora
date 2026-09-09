/**
 * The palette is enqueued on the umbrella capability, so a reader who holds
 * one area cap sees every Gratora destination in it. Following one lands on
 * "Sorry, you are not allowed to access this page", losing the screen they
 * were on: the palette is the only place those pages appear for them, since
 * the sidebar already withholds them.
 */

const mockRegisterCommand = jest.fn();

jest.mock( '@wordpress/data', () => ( { dispatch: () => ( { registerCommand: mockRegisterCommand } ) } ) );
jest.mock( '@wordpress/commands', () => ( { store: 'commands' } ) );
// The real package builds an SVG per icon at import time, which jsdom has no
// need to do for a test about which entries register.
jest.mock( '@wordpress/icons', () => new Proxy( {}, { get: () => null } ) );

const ALL = [
    'gratora/dashboard',
    'gratora/donations',
    'gratora/donors',
    'gratora/campaigns',
    'gratora/funds',
    'gratora/settings',
    'gratora/onboarding',
    'gratora/new-campaign',
];

// The module registers on import, so each case needs its own evaluation.
function registered() {
    jest.isolateModules( () => require( '../../assets/admin/command-palette/index' ) );

    return mockRegisterCommand.mock.calls.map( ( [ cmd ] ) => cmd );
}

beforeEach( () => {
    mockRegisterCommand.mockClear();
    delete window.gratoraCommandPalette;
} );

it( 'offers a bookkeeper only the screens they can open', () => {
    window.gratoraCommandPalette = {
        adminUrl: '/wp-admin/',
        can:      {
            'gratora':            true,
            'gratora-donations':  true,
            'gratora-donors':     false,
            'gratora-campaigns':  false,
            'gratora-funds':      false,
            'gratora-settings':   false,
            'gratora-onboarding': false,
        },
    };

    expect( registered().map( ( c ) => c.name ) ).toEqual( [ 'gratora/dashboard', 'gratora/donations' ] );
} );

it( 'offers an administrator all of them', () => {
    window.gratoraCommandPalette = {
        adminUrl: '/wp-admin/',
        can:      Object.fromEntries( [
            'gratora',
            'gratora-donations',
            'gratora-donors',
            'gratora-campaigns',
            'gratora-funds',
            'gratora-settings',
            'gratora-onboarding',
        ].map( ( p ) => [ p, true ] ) ),
    };

    const cmds = registered();

    expect( cmds.map( ( c ) => c.name ) ).toEqual( ALL );
    // The store is handed the shape it was handed before the gate existed.
    expect( cmds.some( ( c ) => 'page' in c ) ).toBe( false );
} );

it( 'offers all of them when no map was injected', () => {
    expect( registered().map( ( c ) => c.name ) ).toEqual( ALL );
} );
