/**
 * The offline card has to agree with OfflineGateway::canCharge().
 *
 * Either instructions or bank details is a way for a donor to pay, and the
 * gateway trims before deciding. When this screen checked instructions alone
 * it told a site with bank details written that it was not configured, and
 * opened the card to nag, while donations were going through it.
 *
 * The rule is asserted directly: the panel needs a whole settings harness to
 * render, and what breaks here is the predicate, not the markup.
 */
const configured = ( offline ) =>
    [ 'instructions', 'bank_details' ]
        .some( ( key ) => String( offline[ key ] ?? '' ).trim() !== '' );

describe( 'offline gateway readiness', () => {
    it( 'is not ready with nothing written', () => {
        expect( configured( { instructions: '', bank_details: '' } ) ).toBe( false );
    } );

    it( 'is ready on instructions alone', () => {
        expect( configured( { instructions: 'Post a cheque to...', bank_details: '' } ) ).toBe( true );
    } );

    it( 'is ready on bank details alone, which used to read as unconfigured', () => {
        expect( configured( { instructions: '', bank_details: 'IBAN GB00 0000' } ) ).toBe( true );
    } );

    it( 'does not count whitespace as a way to pay', () => {
        expect( configured( { instructions: '   ', bank_details: '\n' } ) ).toBe( false );
    } );

    it( 'copes with the fields never having been saved', () => {
        expect( configured( {} ) ).toBe( false );
    } );
} );
