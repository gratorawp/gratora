/**
 * Presentational KPI card strip above admin list tables; each list passes its own
 * items ({ label, value, sub? }) so card markup + loading treatment stay identical.
 */
export default function KpiStrip( { items, loading } ) {
    return (
        <div className="fundkit-kpi-strip" aria-busy={ loading } style={ { opacity: loading ? 0.5 : 1 } }>
            { items.map( ( it, i ) => (
                <div key={ i } className="fundkit-kpi-strip__card">
                    <div className="fundkit-kpi-strip__label">{ it.label }</div>
                    <div className="fundkit-kpi-strip__value">{ it.value }</div>
                    { it.sub && <div className="fundkit-kpi-strip__sub">{ it.sub }</div> }
                </div>
            ) ) }
        </div>
    );
}
