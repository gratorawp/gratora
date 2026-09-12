/**
 * A ternary between "1 thing" and "%d things" is an English plural rule. In
 * Russian, 2 needs a form neither branch can supply, so a translator has one
 * string to cover both 2 and 5 and cannot be right at either.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { render } from 'preact';
import { setLocaleData } from '@wordpress/i18n';

import Header from '../../assets/admin/donors/profile/Header';

const RUSSIAN = 'nplurals=3; plural=(n%10==1 && n%100!=11 ? 0 : n%10>=2 && n%10<=4 && (n%100<10 || n%100>=20) ? 1 : 2);';

beforeEach( () => {
    // '%d active plans' is deliberately left untranslated: it is the msgid the
    // ternary reaches for, so leaving it out is what makes the difference show.
    setLocaleData( {
        '': { domain: 'gratora-donation-platform', lang: 'ru', plural_forms: RUSSIAN },
        '%d active plan': [ '%d active plan', 'PLAN_FEW_%d', 'PLAN_MANY_%d' ],
    }, 'gratora-donation-platform' );

    document.body.innerHTML = '<div id="root"></div>';
} );

function mount( activePlans ) {
    render(
        <Header
            donor={ { id: 1, name: 'A', is_anonymous: false, redacted_at: null, first_donation_at: null } }
            recurring={ { plans: Array.from( { length: activePlans }, () => ( { status: 'active' } ) ) } }
            banners={ [] }
            onBack={ () => {} }
            onEdit={ () => {} }
            onTabSwitch={ () => {} }
        />,
        document.getElementById( 'root' )
    );
}

it( 'uses the few form at two', () => {
    mount( 2 );

    expect( document.body.textContent ).toContain( 'PLAN_FEW_2' );
    expect( document.body.textContent ).not.toContain( 'PLAN_MANY_2' );
} );

it( 'uses the many form at five', () => {
    mount( 5 );

    expect( document.body.textContent ).toContain( 'PLAN_MANY_5' );
} );
