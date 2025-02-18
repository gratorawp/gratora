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
