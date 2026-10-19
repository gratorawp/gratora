/**
 * Settings, Emails listed every template an add-on describes under "Donor
 * emails, Sent to donors automatically", so a mail that only ever goes to
 * someone at the organization read as one a donor receives. A template that
 * says it goes to staff is listed under a heading of its own; one that says
 * nothing stays where it was.
 */

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );
jest.mock( '@wordpress/api-fetch', () => jest.fn( () => new Promise( () => {} ) ) );

import { render } from 'preact';

import EmailPanel from '../../assets/admin/settings/panels/EmailPanel';

const s = {
    value:    ( k, f ) => f,
    bind:     () => ( { value: '', onChange: () => {} } ),
    setValue: () => () => {},
    edit:     () => {},
    isDirty:  false,
};

function mount() {
    document.body.innerHTML = '<div id="root"></div>';
    render( <EmailPanel s={ s } />, document.getElementById( 'root' ) );
}

function cardHeaded( title ) {
    return [ ...document.querySelectorAll( '.gratora-card' ) ].find(
        ( card ) => card.querySelector( '.gratora-card__title' )?.textContent === title
    );
}

const rowsOf = ( card ) =>
    [ ...card.querySelectorAll( '.gratora-email-row__title' ) ].map( ( row ) => row.firstChild.textContent );

afterEach( () => {
    document.body.innerHTML = '';
    delete window.gratora;
} );

it( 'lists a template that goes to staff under the staff heading only', () => {
    window.gratora = {
        email_template_meta: [
            { id: 'crm_task_assigned', label: 'Task assigned', recipient: 'Task owner', audience: 'staff' },
        ],
    };

    mount();

    const staff = cardHeaded( 'Staff emails' );

    expect( staff ).toBeDefined();
    expect( rowsOf( staff ) ).toEqual( [ 'Task assigned' ] );
    expect( staff.querySelector( '.gratora-email-row__recipient' ).textContent ).toBe( 'Task owner' );
    expect( rowsOf( cardHeaded( 'Donor emails' ) ) ).not.toContain( 'Task assigned' );
} );

it( 'keeps a template that says nothing with the donor emails', () => {
    window.gratora = {
        email_template_meta: [
            { id: 'p2p_fundraiser_welcome', label: 'Fundraiser welcome', recipient: 'Fundraiser' },
        ],
    };

    mount();

    expect( rowsOf( cardHeaded( 'Donor emails' ) ) ).toContain( 'Fundraiser welcome' );
    expect( cardHeaded( 'Staff emails' ) ).toBeUndefined();
} );

it( 'keeps core\'s own templates with the donor emails', () => {
    window.gratora = {
        email_template_meta: [
            { id: 'crm_report', label: 'Donor CRM report', audience: 'staff' },
        ],
    };

    mount();

    const donor = rowsOf( cardHeaded( 'Donor emails' ) );

    expect( donor ).toContain( 'Donation receipt' );
    expect( donor ).toContain( 'Recurring payment failed' );
    expect( rowsOf( cardHeaded( 'Staff emails' ) ) ).toEqual( [ 'Donor CRM report' ] );
} );
