export function getValue( values, path ) {
    if ( ! path ) return undefined;
    const parts = path.split( '.' );
    let v = values;
    for ( const p of parts ) {
        if ( v == null ) return undefined;
        v = v[ p ];
    }
    return v;
}

export function evaluateCondition( cond, values ) {
    if ( ! cond || ! cond.field ) return true;
    const actual   = getValue( values, cond.field );
    const expected = cond.value;
    switch ( cond.op ) {
        case '=':        return String( actual ?? '' ) === String( expected );
        case '!=':       return String( actual ?? '' ) !== String( expected );
        case '>':        return Number( actual ) >  Number( expected );
        case '>=':       return Number( actual ) >= Number( expected );
        case '<':        return Number( actual ) <  Number( expected );
        case '<=':       return Number( actual ) <= Number( expected );
        case 'contains': return String( actual ?? '' ).toLowerCase().includes( String( expected ).toLowerCase() );
        default:         return true;
    }
}
