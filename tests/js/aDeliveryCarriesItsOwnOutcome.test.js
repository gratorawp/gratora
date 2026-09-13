/**
 * The log's Outcome column was blank on every row but a gateway delivery, and
 * a delivery is the rarest of the three kinds a site logs: on a site that has
 * taken none it is a column of nothing under a header that reads as an unknown
 * outcome rather than as one that does not apply.
 *
 * The outcome itself is not spare, though. A delivery's message is the
 * gateway's event type, and that string is the same whether the delivery was
 * refused at the signature, threw while being handled, or went through. So the
 * pill moved next to the event it belongs to and the column came off.
 *
 * The filter did not move. DataViews derives filters from the field list
 * rather than from the visible columns, so Problems only survives the column
 * being hidden, and the field stays defined for it.
 */

import { render } from 'preact';
import apiFetch from '@wordpress/api-fetch';

const { waitFor } = require( './support/waitFor' );

jest.mock( '@wordpress/api-fetch', () => jest.fn() );
jest.mock( 'react', () => require( 'preact/compat' ) );
jest.mock( 'react-dom', () => require( 'preact/compat' ) );
jest.mock( 'react/jsx-runtime', () => require( 'preact/compat/jsx-runtime' ) );
jest.mock( 'react/jsx-dev-runtime', () => require( 'preact/compat/jsx-dev-runtime' ) );

const captured = { fields: null, view: null };

jest.mock( '@wordpress/dataviews', () => ( {
    DataViews: ( props ) => {
        captured.fields = props.fields;
        captured.view   = props.view;
        return null;
    },
} ) );

import LogsTab from '../../assets/admin/tools/tabs/LogsTab';

const DELIVERY = {
    id:          1,
    kind:        'webhook',
    source:      'stripe',
    message:     'payment_intent.succeeded',
    verified:    false,
    processed:   false,
    error:       null,
    context:     {},
    occurred_at: '2026-09-13 19:00:00',
};

const ERROR_ROW = {
    id:          2,
    kind:        'error',
    source:      'admin.recurring',
    message:     'Cannot resume subscription sub_1.',
    context:     { recurring_plan_id: 4 },
    occurred_at: '2026-09-13 19:00:00',
};

async function mountWith( items ) {
    captured.fields = null;
    captured.view   = null;
    document.body.innerHTML = '<div id="root"></div>';

    apiFetch.mockReset();
    apiFetch.mockResolvedValue( {
        items,
        total:         items.length,
        page:          1,
        per_page:      25,
        sources:       [ 'webhook.stripe' ],
        clearable:     true,
        clear_blocked: null,
    } );

    window.gratora = { can: { manage_options: true, view_donations: true } };

    render( <LogsTab active setNotice={ () => {} } />, document.getElementById( 'root' ) );

    await waitFor( () => !! captured.fields, { what: 'the log to register its fields' } );
}

/** Render one field's cell and read it back as text. */
function cellText( fieldId, item ) {
    const field = captured.fields.find( ( f ) => f.id === fieldId );
    const host  = document.createElement( 'div' );
    document.body.appendChild( host );
    render( field.render( { item } ), host );

    return host.textContent || '';
}

test( 'a delivery says its outcome beside the event it belongs to', async () => {
    await mountWith( [ DELIVERY ] );

    const text = cellText( 'message', DELIVERY );

    expect( text ).toContain( 'payment_intent.succeeded' );
    expect( text ).toContain( 'Not verified' );
} );

test( 'the four readings are told apart', async () => {
    await mountWith( [ DELIVERY ] );

    const read = ( over ) => cellText( 'message', { ...DELIVERY, ...over } );

    expect( read( {} ) ).toContain( 'Not verified' );
    expect( read( { verified: true, error: 'boom' } ) ).toContain( 'Handling failed' );
    expect( read( { verified: true, processed: true } ) ).toContain( 'Processed' );
    // Gateways send everything they have, and most of it is none of our
    // business. That is not a fault and must not read as one.
    expect( read( { verified: true } ) ).toContain( 'No action needed' );
} );

test( 'the outcome column is off by default', async () => {
    await mountWith( [ DELIVERY ] );

    expect( captured.view.fields ).not.toContain( 'outcome' );
} );

/** Turning the column back on must move the pill, not print a second one. */
test( 'the outcome is shown once when the column is switched on', async () => {
    await mountWith( [ DELIVERY ] );

    captured.view.fields = [ ...captured.view.fields, 'outcome' ];
    render( <LogsTab active setNotice={ () => {} } />, document.getElementById( 'root' ) );
    await waitFor( () => captured.view.fields.includes( 'outcome' ), { what: 'the column to come on' } );

    expect( cellText( 'message', DELIVERY ) ).not.toContain( 'Not verified' );
    expect( cellText( 'outcome', DELIVERY ) ).toContain( 'Not verified' );
} );

/** Hiding the column must not take the narrowing with it. */
test( 'problems only is still offered', async () => {
    await mountWith( [ DELIVERY ] );

    const outcome = captured.fields.find( ( f ) => f.id === 'outcome' );

    expect( outcome ).toBeDefined();
    expect( outcome.elements.map( ( e ) => e.value ) ).toContain( 'failed' );
    expect( outcome.filterBy.operators ).toContain( 'is' );
} );

/** An error is a failure by definition, so a pill on it says nothing new. */
test( 'an error row carries no outcome pill', async () => {
    await mountWith( [ ERROR_ROW ] );

    const text = cellText( 'message', ERROR_ROW );

    expect( text ).toContain( 'Cannot resume subscription sub_1.' );
    expect( text ).not.toMatch( /verified|Processed|No action needed|Handling failed/ );
} );
