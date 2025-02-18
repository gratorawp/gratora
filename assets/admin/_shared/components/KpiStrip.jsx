/**
 * Presentational KPI card strip shown above admin list tables. Each list
 * builds its own `items` (domain labels/values) and passes them here so the
 * card markup + loading treatment stay identical across donations, campaigns,
 * funds and donors.
 *
 *   items: Array<{ label: string, value: node, sub?: node }>
 *   loading: boolean   // dims the strip while the first stats fetch is in flight
 */
export default function KpiStrip( { items, loading } ) {
    return (
        <div className="dono-kpi-strip" aria-busy={ loading } style={ { opacity: loading ? 0.5 : 1 } }>
            { items.map( ( it, i ) => (
                <div key={ i } className="dono-kpi-strip__card">
                    <div className="dono-kpi-strip__label">{ it.label }</div>
                    <div className="dono-kpi-strip__value">{ it.value }</div>
                    { it.sub && <div className="dono-kpi-strip__sub">{ it.sub }</div> }
                </div>
            ) ) }
        </div>
    );
}
