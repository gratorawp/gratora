/**
 * A give-again link exists because the donor has already decided the amount.
 * They follow it to skip that decision, so nobody re-reads the figure: whatever
 * the form preselects is what gets given. Minor units mean nothing without a
 * currency, and 500000 is 5,000 yen or 5,000 dollars depending only on which
 * one the link was written in.
 *
 * Driven through the real runtime, because the defect is in the wiring between
 * the link, the form's own currency, and the tile the donor is shown.
 */

const AMOUNT_URL = '/campaign/?dono_amount=500000&dono_currency=JPY&dono_frequency=one_time';

function config( overrides = {} ) {
    return {
        slug:     'give-again',
        form_id:  11,
        currency: 'USD',
        currencies: [ 'USD', 'EUR', 'JPY' ],
        fx:       { base: 'USD', rates: { USD: 1, EUR: 0.9, JPY: 150 } },
        gateway:  'offline',
        gateways: {
            default: 'offline',
            options: [ { id: 'offline', label: 'Offline', currencies: [ '*' ], frequencies: [ 'one_time' ] } ],
        },
        layout:   'inline',
        numberFormat: {
            decimalPlaces:  2,
            decimalSep:     '.',
            thousandSep:    ',',
            symbolPosition: 'before',
            symbol:         '$',
        },
        steps: [ {
            type:        'amount',
            page:        0,
            presets:     [ { cents: 2500 }, { cents: 5000 } ],
            allowCustom: true,
        } ],
        i18n: {
            amount:       'Donation amount',
            customAmount: 'Other amount',
            currency:     'Currency',
            donateNow:    'Donate now',
        },
        ...overrides,
    };
}

function addForm( cfg ) {
    const form = document.createElement( 'form' );
    form.className = 'dono-donation-form';
    form.id = 'dono-form-1';

    const json = document.createElement( 'script' );
    json.type = 'application/json';
    json.setAttribute( 'data-dono-form-config', '' );
    json.textContent = JSON.stringify( cfg );
    form.appendChild( json );

    document.body.appendChild( form );
    return form;
}

// The runtime boots itself on import, so each test needs its own module copy.
async function boot() {
    jest.isolateModules( () => {
        require( '../../assets/donation-form/runtime.jsx' );
    } );
    await new Promise( ( r ) => setTimeout( r, 50 ) );
}

function typedAmount( form ) {
    return form.querySelector( '.dono-amount__input' ).value;
}

function amountCurrency( form ) {
    return form.querySelector( '.dono-amount__code' ).textContent;
}

beforeEach( () => {
    document.body.innerHTML = '';
    window.history.replaceState( {}, '', '/campaign/' );
} );

test( 'a link written in yen opens the form in yen, at the yen amount', async () => {
    window.history.replaceState( {}, '', AMOUNT_URL );
    const form = addForm( config() );

    await boot();

    expect( amountCurrency( form ) ).toBe( 'JPY' );
    expect( typedAmount( form ) ).toBe( '5,000' );
    expect( form.querySelector( '.dono-form__currency-switcher select' ).value ).toBe( 'JPY' );
    // The number read as this form's own currency, which is the whole defect.
    expect( form.textContent ).not.toContain( '$5,000.00' );
} );

test( 'a link in a currency the form cannot open in preselects nothing of its own', async () => {
    window.history.replaceState( {}, '', AMOUNT_URL );
    // No switcher: this form takes its authored currency and nothing else.
    const form = addForm( config( { currencies: [] } ) );

    await boot();

    expect( amountCurrency( form ) ).toBe( 'USD' );
    expect( typedAmount( form ) ).toBe( '' );
    expect( form.textContent ).not.toContain( '5,000' );
    // The form's own first tile, which is a figure it authored.
    expect( form.textContent ).toContain( '$25.00' );
} );

test( 'a link in the currency the form is authored in still prefills its amount', async () => {
    window.history.replaceState( {}, '', '/campaign/?dono_amount=7500&dono_currency=USD' );
    const form = addForm( config() );

    await boot();

    expect( amountCurrency( form ) ).toBe( 'USD' );
    const selected = form.querySelector( '.dono-form__preset.is-selected' );
    expect( selected.textContent ).toContain( '$75.00' );
} );

test( 'an amount above what the schema accepts is not preselected', async () => {
    window.history.replaceState( {}, '', '/campaign/?dono_amount=100000000&dono_currency=USD' );
    const form = addForm( config() );

    await boot();

    expect( typedAmount( form ) ).toBe( '' );
    expect( form.querySelector( '.dono-form__preset.is-selected' ).textContent ).toContain( '$25.00' );
} );
