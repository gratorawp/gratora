/**
 * The screen's label maps and the values the server stores had drifted. A
 * cheque recorded by hand is classified 'manual', which had no entry, so the
 * Channel chip printed the raw lowercase slug beside chips reading 'Email' and
 * 'Referral' and stayed English in every locale, because the fallback is the
 * slug rather than a translated string.
 */

import { CHANNEL_LABEL } from '../../assets/admin/donations/detail/helpers';

/** Every value ChannelClassifier::classify can return. */
const CLASSIFIER_RANGE = [
    'direct', 'email', 'social', 'paid-social', 'organic',
    'cpc', 'referral', 'qr', 'peer', 'manual',
];

test.each( CLASSIFIER_RANGE )( 'the channel %p has a label', ( channel ) => {
    expect( CHANNEL_LABEL[ channel ] ).toBeTruthy();
} );

it( 'carries no label for a channel the classifier cannot return', () => {
    const extra = Object.keys( CHANNEL_LABEL ).filter( ( k ) => ! CLASSIFIER_RANGE.includes( k ) );

    expect( extra ).toEqual( [] );
} );
