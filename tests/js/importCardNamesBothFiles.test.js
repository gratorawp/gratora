/**
 * The Export tab tells the reader that the Import tab can restore an Everything
 * file. The only control that can is a card titled "Import settings" whose
 * description ended "Donations, donors and campaigns are left alone."
 *
 * So a person holding gratora-export.json saw no control that admitted their
 * file, and a person who used it anyway was told their records were safe by the
 * card whose route was about to add the file's records and write its settings
 * over theirs. The confirm dialog already told both stories; the card did not.
 */

import { render } from 'preact';

import apiFetch from '@wordpress/api-fetch';

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

// Its own requests, and nothing to do with the JSON card.
jest.mock( '../../assets/admin/tools/tabs/CsvImportCard', () => ( {
    __esModule: true,
    default: () => null,
} ) );

import ImportTab from '../../assets/admin/tools/tabs/ImportTab';

function mount() {
    document.body.innerHTML = '<div id="root"></div>';
    apiFetch.mockReset();
    apiFetch.mockResolvedValue( {} );

    render( <ImportTab setNotice={ () => {} } />, document.getElementById( 'root' ) );

    return document.body.innerText || document.body.textContent || '';
}

test( 'the card admits the Everything export as well as the settings one', () => {
    const text = mount();

    // The word on the Export tab's own row, so someone who just clicked it
    // recognises the control.
    expect( text ).toMatch( /Everything/ );
    expect( text ).toMatch( /settings export/i );
} );

test( 'it names the records a full export brings with it', () => {
    const text = mount();

    expect( text ).toMatch( /donations/i );
    expect( text ).toMatch( /donors/i );
    expect( text ).toMatch( /campaigns/i );
} );

test( 'it does not promise that records are left alone', () => {
    const text = mount();

    expect( text ).not.toMatch( /left alone/i );
    expect( text ).not.toMatch( /are untouched/i );
} );

/**
 * The settings half is the destructive one and the card is now the broader
 * control, so the overwrite has to be on the card and not only in the dialog.
 */
test( 'it says the settings in the file are written over this site', () => {
    const text = mount();

    expect( text ).toMatch( /written? over|writes? its .* over/i );
} );

/** JSON against CSV is what tells the two cards apart. */
test( 'the button still asks for the file type', () => {
    mount();

    const labels = [ ...document.querySelectorAll( 'button' ) ].map( ( b ) => b.textContent );

    expect( labels.join( ' ' ) ).toMatch( /JSON/ );
} );
