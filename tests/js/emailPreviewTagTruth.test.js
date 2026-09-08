/**
 * A sample value is how this file says a tag is real: expandTags resolves
 * anything that has one and renders the rest as a chip. Two tags carried a
 * sample that no sender fills, so the preview showed the admin a resolved date
 * and address and every donor received the literal braces.
 */

import { expandTags } from '../../assets/admin/settings/panels/EmailPanel';

const rendered = ( parts ) => parts.map( ( p ) => ( typeof p === 'string' ? p : p.props.children ) ).join( '' );

it( 'shows a tag no sender fills as a tag', () => {
    const out = rendered( expandTags( 'Received on {date}, confirmation to {donor_email}' ) );

    expect( out ).toContain( '{date}' );
    expect( out ).toContain( '{donor_email}' );
} );

it( 'does not promise an address that never arrives', () => {
    expect( rendered( expandTags( 'to {donor_email}' ) ) ).not.toContain( 'jane@example.com' );
} );

/** The advertised tags still preview, or the preview would stop being one. */
it( 'still resolves the tags a sender does pass', () => {
    const out = rendered( expandTags( 'Hi {donor_first_name}, thanks for {amount}' ) );

    expect( out ).not.toContain( '{donor_first_name}' );
    expect( out ).not.toContain( '{amount}' );
} );
