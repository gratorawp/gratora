/**
 * The form's config rides in a script element printed through core's inline
 * script tag, and core lets any plugin filter that tag's attributes. Consent
 * managers and script optimizers rewrite the type to hold scripts back. A
 * runtime that looked for the config by its type would find nothing, and the
 * donor would be left with the unhydrated fallback for good.
 */

const { settle } = require( './support/waitFor' );

const CONFIG = {
    slug:     'appeal',
    form_id:  7,
    currency: 'USD',
    gateway:  'offline',
    layout:   'inline',
    rest:     'https://example.test/wp-json/gratora/v1/donations',
    gateways: { options: [ { id: 'offline', label: 'Bank transfer', currencies: [ '*' ], countries: [ '*' ], frequencies: [ 'one_time' ] } ] },
    steps: [
        { id: 'amount', type: 'amount', presets: [ 2500 ] },
        { id: 'submit', type: 'submit' },
    ],
    i18n: { donateNow: 'Donate now', processing: 'Processing' },
};

// Attribute order and surrounding newlines as wp_get_inline_script_tag() prints them.
function addForm( type ) {
    document.body.innerHTML = '<form class="gratora-donation-form" id="gratora-form-7">'
        + `<script data-gratora-form-config type="${ type }">\n${ JSON.stringify( CONFIG ) }\n</script>`
        + '</form>';

    return document.getElementById( 'gratora-form-7' );
}

beforeEach( () => {
    document.body.innerHTML = '';
    window.gratora = {
        default_currency: 'USD',
        number_format: { decimalPlaces: 2, decimalSep: '.', thousandSep: ',', symbolPosition: 'before', symbol: '$' },
    };
    global.fetch = jest.fn( () => Promise.reject( new Error( 'no request expected' ) ) );
} );

test.each( [ 'application/json', 'text/plain' ] )( 'a form whose config is typed %s still hydrates', async ( type ) => {
    const form = addForm( type );

    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    await settle();

    expect( form.dataset.gratoraMounted ).toBe( 'true' );
    expect( form.querySelector( '.gratora-form__button--primary' ) ).not.toBeNull();
} );
