import { test, expect } from '../fixtures/donor-form';

/**
 * The seam an add-on listens on to learn that money moved in this browser.
 *
 * It is the only signal of its kind the form emits, so the shape of the detail
 * is a contract: a listener that has to ask the server for the amount cannot be
 * handed donor data it never asked for, and a donation that has not been paid
 * must not look like one that has.
 *
 * Prerequisite: the two paid cases select the sandbox gateway, which core only
 * registers when org-wide test mode is on. Without it they fail at gateway
 * selection rather than on the assertion.
 */
const COLLECT = `
    window.__donoCompleted = [];
    window.addEventListener( 'dono:donation:completed', ( e ) => {
        window.__donoCompleted.push( e.detail );
    } );
`;

type Detail = { reference: string; statusToken: string; status: string };

async function collected( page ): Promise<Detail[]> {
    return page.evaluate( () => window.__donoCompleted ?? [] );
}

test.describe( 'donation completion event', () => {
    test( 'a paid donation announces itself once', async ( { donor, page } ) => {
        await page.evaluate( COLLECT );

        await donor.selectPresetAt( 0 );
        await donor.fillName( 'E2E', 'Tester' );
        await donor.fillEmail( `e2e+${ Date.now() }@example.com` );
        await donor.selectGateway( 'sandbox' );
        await donor.submit();
        await donor.expectThankYou();

        await expect.poll( () => collected( page ) ).toHaveLength( 1 );

        const [ detail ] = await collected( page );
        expect( detail.status ).toBe( 'success' );
        expect( detail.reference ).toBeTruthy();
        // Without it the listener cannot read the amount back, so an empty
        // token is the same as no event at all.
        expect( detail.statusToken ).toBeTruthy();
    } );

    test( 'it carries the reference and its token and nothing else', async ( { donor, page } ) => {
        await page.evaluate( COLLECT );

        await donor.selectPresetAt( 0 );
        await donor.fillName( 'Nadia', 'Okonjo' );
        await donor.fillEmail( `e2e+${ Date.now() }@example.com` );
        await donor.selectGateway( 'sandbox' );
        await donor.submit();
        await donor.expectThankYou();

        await expect.poll( () => collected( page ) ).toHaveLength( 1 );

        const [ detail ] = await collected( page );
        expect( Object.keys( detail ).sort() ).toEqual( [ 'reference', 'status', 'statusToken' ] );
        // The donor's own details were on this page a moment ago. Nothing that
        // identifies them may ride along to whatever is listening.
        expect( JSON.stringify( detail ) ).not.toContain( 'Nadia' );
        expect( JSON.stringify( detail ) ).not.toContain( 'Okonjo' );
        expect( JSON.stringify( detail ) ).not.toContain( '@example.com' );
    } );

    test( 'an unpaid donation announces nothing', async ( { donor, page } ) => {
        await page.evaluate( COLLECT );

        await donor.selectPresetAt( 0 );
        await donor.fillName( 'E2E', 'Tester' );
        await donor.fillEmail( `e2e+${ Date.now() }@example.com` );
        // Offline records the donation and emails instructions. No money has
        // moved and it may never; reporting it as revenue would be a lie.
        await donor.selectGateway( 'offline' );
        await donor.submit();
        await donor.expectThankYou();

        expect( await collected( page ) ).toHaveLength( 0 );
    } );
} );
