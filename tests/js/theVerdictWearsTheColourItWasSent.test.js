/**
 * The at-risk table names why each donor is on it, and the chip is coloured by
 * that verdict.
 *
 * The colours were a map in that file, keyed by verdict. A verdict added on
 * the server reached it unlisted and rendered muted, which beside nine
 * coloured chips reads as no verdict at all. The colour comes with the row
 * now, so this is checking the screen has not gone back to deciding.
 */

import { render } from 'preact';

jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

import { ReasonPill } from '../../assets/admin/donors/Insights';

// A verdict and a colour this file could not have known.
const ROW = {
    risk_reason: 'plan_becalmed',
    risk_reason_label: 'Becalmed',
    risk_reason_tone: 'is-violet',
    avg_gap_days: 45,
};

function mount( row ) {
    document.body.innerHTML = '<div id="root"></div>';
    render( <ReasonPill row={ row } />, document.getElementById( 'root' ) );

    return document.querySelector( '.dp-pill' );
}

it( 'says the verdict it was sent', () => {
    expect( mount( ROW ).textContent.trim() ).toBe( 'Becalmed' );
} );

it( 'and wears the colour it was sent with it', () => {
    expect( mount( ROW ).className ).toContain( 'is-violet' );
} );

it( 'falls back to muted rather than an unstyled chip', () => {
    expect( mount( { ...ROW, risk_reason_tone: undefined } ).className ).toContain( 'is-muted' );
} );

/** The gap is a hover note, and a donor with no measurable gap gets none. */
it( 'carries the average gap as a note when there is one', () => {
    expect( mount( ROW ).getAttribute( 'title' ) ).toContain( '45' );
    expect( mount( { ...ROW, avg_gap_days: null } ).getAttribute( 'title' ) ).toBeNull();
} );
