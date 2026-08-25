/**
 * Picking a template in the form editor rewrites the form's settings object,
 * and Undo holds blocks only. Anything a template overwrites that it never
 * named is configuration the author cannot get back.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn() );

// @wordpress/interface subscribes to breakpoints the moment the editor module
// is imported, and jsdom ships no matchMedia, so the stub has to be in place
// before the require: an import statement would be hoisted above it.
window.matchMedia = () => ( {
    matches: false, addListener: () => {}, removeListener: () => {},
    addEventListener: () => {}, removeEventListener: () => {},
} );

const { settingsAfterTemplate, templateApplication } = require( '../../assets/admin/forms/Editor' );

// What the shipped templates carry (FormTemplates::defaultSettings plus the
// copy some of them override). Nothing here names a goal, a container or test
// mode.
const template = {
    layout:            'inline',
    style:             { preset_id: '' },
    recurring:         { enabled: true, frequencies: [ 'monthly' ] },
    gateways:          { allowed: [] },
    anonymous_allowed: true,
    thank_you_message: 'You are amazing.',
};

// An onboarding form the author has already configured.
const current = {
    layout:    'modal',
    style:     { preset_id: 'warm' },
    container: { width: 720, style: 'card' },
    gateways:  { allowed: [ 'stripe' ] },
    goal:      { type: 'amount', amount_cents: 500000, count: 0 },
    thank_you_message: 'Thank you from all of us.',
    redirect_url: 'https://example.test/thanks',
    test_mode: true,
};

test( 'the goal the author set survives a template', () => {
    const next = settingsAfterTemplate( current, template );

    expect( next.goal ).toEqual( { type: 'amount', amount_cents: 500000, count: 0 } );
} );

test( 'container width and test mode survive a template', () => {
    const next = settingsAfterTemplate( current, template );

    expect( next.container ).toEqual( { width: 720, style: 'card' } );
    expect( next.test_mode ).toBe( true );
} );

test( 'what the template names is what the template replaces', () => {
    const next = settingsAfterTemplate( current, template );

    expect( next.layout ).toBe( 'inline' );
    expect( next.thank_you_message ).toBe( 'You are amazing.' );
    expect( next.recurring ).toEqual( { enabled: true, frequencies: [ 'monthly' ] } );
} );

test( 'a form with no settings yet still gets the whole default shape', () => {
    const next = settingsAfterTemplate( null, template );

    expect( next.goal ).toEqual( { type: 'none', amount_cents: 0, count: 0 } );
    expect( next.container ).toEqual( { width: 540, style: 'plain' } );
    expect( next.layout ).toBe( 'inline' );
} );

/**
 * The merge being right is not the same as the editor using it. This drives
 * what the template picker actually hands the form, which is where handing
 * over the template's settings raw would discard the author's configuration.
 */
test( 'applying a template keeps what the author configured', () => {
    const { settings } = templateApplication( current, { blocks: '', settings: template } );

    expect( settings.goal.amount_cents ).toBe( 500000 );
    expect( settings.container.width ).toBe( 720 );
    expect( settings.test_mode ).toBe( true );
    expect( settings.layout ).toBe( 'inline' );
} );

/** A template that names no settings must leave them alone entirely. */
test( 'a template with no settings changes none', () => {
    const { settings } = templateApplication( current, { blocks: '<!-- wp:paragraph /-->' } );

    expect( settings ).toBeNull();
} );

/**
 * No shipped template has an opinion about where a particular organisation
 * sends its donors afterwards, and undo restores blocks, not settings.
 */
test( 'a template does not clear the thank-you redirect the author set', () => {
    const { settings } = templateApplication( current, { blocks: '', settings: template } );

    expect( settings.redirect_url ).toBe( 'https://example.test/thanks' );
} );
