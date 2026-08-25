/**
 * Wait for something to become true, rather than for a fixed moment.
 *
 * A sleep long enough on an idle machine is not long enough when jest is
 * running suites in parallel, and a test that reads its assertion a beat early
 * fails at random. That is worse than a test that always fails, because it
 * teaches everyone to re-run instead of look.
 */
async function waitFor( condition, { timeout = 2000, interval = 10, what = 'condition' } = {} ) {
    const deadline = Date.now() + timeout;

    while ( Date.now() < deadline ) {
        if ( condition() ) return;
        await new Promise( ( r ) => setTimeout( r, interval ) );
    }

    throw new Error( `Timed out after ${ timeout }ms waiting for ${ what }.` );
}

/** Wait for the number of recorded calls to grow past a mark. */
const waitForCallsAfter = ( fn, mark, what = 'a request' ) =>
    waitFor( () => fn.mock.calls.length > mark, { what } );

/**
 * Let everything already in flight finish.
 *
 * Waiting 20ms of wall clock is load-dependent: it is plenty on an idle
 * machine and not enough when jest is running suites in parallel and this
 * worker is not scheduled. Waiting a fixed number of event-loop turns is not,
 * so a promise chain drains the same way under any load. The leading sleep is
 * there so real debounce timers still get their moment.
 */
async function settle() {
    await new Promise( ( r ) => setTimeout( r, 20 ) );

    for ( let i = 0; i < 20; i++ ) {
        await new Promise( ( r ) => setTimeout( r, 0 ) );
    }
}

module.exports = { waitFor, waitForCallsAfter, settle };
