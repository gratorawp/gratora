// Returns gateway options visible for the donor's current currency, frequency,
// and country - re-resolved client-side without a server round trip.

export function visibleGateways( config, state ) {
    const g    = config && config.gateways;
    const opts = g && Array.isArray( g.options ) ? g.options : [];
    if ( ! opts.length ) return [];

    const currency = String( state && state.currency ? state.currency : '' ).toUpperCase();
    const freqRaw  = ( state && state.values && state.values.frequency ) || 'one-time';
    const bucket   = ( freqRaw === 'one-time' || freqRaw === 'one_time' ) ? 'one_time' : 'recurring';
    const country  = String(
        state && state.values && state.values.profile && state.values.profile.country
            ? state.values.profile.country
            : ''
    ).toUpperCase();

    return opts.filter( ( o ) => {
        const cur = ( o.currencies || [] ).map( ( c ) => String( c ).toUpperCase() );
        if ( cur.length && ! cur.includes( '*' ) && ! cur.includes( currency ) ) return false;

        // Unknown donor country falls through, same as the server.
        const ctr = ( o.countries || [] ).map( ( c ) => String( c ).toUpperCase() );
        if ( country && ctr.length && ! ctr.includes( '*' ) && ! ctr.includes( country ) ) return false;

        const fr = o.frequencies || [];
        if ( fr.length && ! fr.includes( bucket ) ) return false;

        return true;
    } );
}

/**
 * Why visibleGateways() came back empty: 'none' when the form allows no gateway
 * that is switched on, 'currency' or 'frequency' when some gateway exists but
 * this context rules them all out.
 *
 * Without this the UI blamed the currency for every empty list, including the
 * case where an administrator had simply not enabled a gateway.
 */
export function emptyReason( config, state ) {
    const g   = config && config.gateways;
    const all = g && Array.isArray( g.options ) ? g.options : [];
    if ( ! all.length ) return 'none';

    // Same context, currency lifted: if that alone brings something back, the
    // currency really is the blocker.
    const currency = String( state && state.currency ? state.currency : '' ).toUpperCase();
    const matchesCurrency = all.filter( ( o ) => {
        const cur = ( o.currencies || [] ).map( ( c ) => String( c ).toUpperCase() );
        return ! cur.length || cur.includes( '*' ) || cur.includes( currency );
    } );
    if ( ! matchesCurrency.length ) return 'currency';

    const freqRaw = ( state && state.values && state.values.frequency ) || 'one-time';
    const bucket  = ( freqRaw === 'one-time' || freqRaw === 'one_time' ) ? 'one_time' : 'recurring';
    const matchesFreq = matchesCurrency.filter( ( o ) => {
        const fr = o.frequencies || [];
        return ! fr.length || fr.includes( bucket );
    } );
    if ( ! matchesFreq.length ) return 'frequency';

    // Everything else (country) has no message of its own; the neutral one is
    // honest, where naming the currency would not be.
    return 'none';
}
