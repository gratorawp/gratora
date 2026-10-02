/**
 * The form's words come from PHP, in the language of the page. The address
 * block's country picker was handed none of them, so it read "Search
 * country..." on a form that was otherwise in German.
 */

import { render } from 'preact';

import DonorStep from '../../assets/donation-form/steps/DonorStep';

const CONFIG = { currency: 'EUR', locale: 'de-DE', i18n: { searchCountry: 'Land suchen …' } };

it( 'asks for the country in the words the form was given', () => {
    document.body.innerHTML = '<div id="root"></div>';
    const root = document.getElementById( 'root' );

    render(
        <DonorStep
            fields={ [ { kind: 'address', showCountry: true } ] }
            state={ { values: { profile: { address: {} }, custom: {} }, errors: {} } }
            dispatch={ () => {} }
            config={ CONFIG }
        />,
        root
    );

    expect( root.querySelector( '.gratora-form__address input' ).placeholder ).toBe( 'Land suchen …' );
} );
